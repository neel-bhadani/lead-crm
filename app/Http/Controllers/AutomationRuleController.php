<?php

namespace App\Http\Controllers;

use App\Http\Requests\AutomationRuleRequest;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Services\Automation\ConditionMatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as ValidationRule;
use App\Support\CrmTaxonomy;

/**
 * Writing, testing and switching on rules. Admin only, on the route group.
 *
 * The guard rails are the interesting part of this controller, and all four of
 * them are here rather than in the browser, because a guard rail that lives in
 * Vue is a guard rail somebody gets past with a POST:
 *
 *   a new rule is inactive          store() never reads `is_active`
 *   switching on shows the count    matches() answers before toggle() acts
 *   deleting a rule that has fired  destroy() reports fire_count back
 *   a rule that could loop          flagged at save, before it can ever run
 */
class AutomationRuleController extends Controller
{
    public function __construct(private ConditionMatcher $matcher) {}

    /**
     * A new rule, always switched off.
     *
     * There is no "save and activate", and the omission is the feature. The
     * admin writes the rule, reads the plain-words sentence back, presses Test
     * to see the thirty-four leads it would touch, and only then turns it on.
     * A rule that went live on save would apply to the whole database before
     * anybody had read it once.
     */
    public function store(AutomationRuleRequest $request)
    {
        $rule = AutomationRule::create($request->ruleAttributes() + [
            'is_active'  => false,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', "Rule saved, and switched off. Press Test to see which leads it would affect, then turn it on.")
            ->with('automation_rule_id', $rule->id);
    }

    /**
     * Edit a rule.
     *
     * `is_active` is left exactly as it was: editing a live rule does not
     * switch it off — that would be a nasty surprise for anybody fixing a typo
     * — and editing a switched-off one does not switch it on.
     */
    public function update(AutomationRuleRequest $request, AutomationRule $rule)
    {
        $rule->update($request->ruleAttributes());

        return back()->with('success', 'Rule updated.');
    }

    /**
     * Switch a rule on or off.
     *
     * The count that the confirmation dialog showed is deliberately not
     * re-checked here. It is information for the admin, not a condition of the
     * change: refusing the toggle because a lead moved between the dialog
     * opening and the button being pressed would be baffling, and the rule's
     * own conditions are re-evaluated per lead when it actually fires anyway.
     */
    public function toggle(Request $request, AutomationRule $rule)
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $rule->update(['is_active' => $data['is_active']]);

        if (! $data['is_active']) {
            return back()->with('success', "\"{$rule->name}\" is switched off. It will not run again until you turn it back on.");
        }

        $note = config("automation.triggers.{$rule->trigger}.kind") === 'time'
            ? ' It is a time-based rule, so it runs on the hour rather than straight away.'
            : '';

        return back()->with('success', "\"{$rule->name}\" is on." . $note);
    }

    /**
     * Delete a rule, saying what it did while it was alive.
     *
     * A rule that has fired four hundred times is part of the explanation for
     * how four hundred leads ended up where they are. Deleting it leaves the
     * activity log behind — `automation_logs.rule_id` goes null rather than
     * cascading — but the name goes, so the warning in the dialog is the last
     * chance to notice.
     */
    public function destroy(AutomationRule $rule)
    {
        $fired = $rule->fire_count;
        $name  = $rule->name;

        $rule->delete();

        return back()->with('success', $fired > 0
            ? "\"{$name}\" deleted. It had run {$fired} time(s); the activity log keeps what it did."
            : "\"{$name}\" deleted. It had never run.");
    }

    /**
     * THE TEST BUTTON. Which leads match this rule, right now.
     *
     * It does not fire anything, and it cannot: nothing on this path touches
     * RuleEngine, ActionRunner or LeadFollowUpService. It builds the same query
     * the engine would select leads with and counts the rows.
     *
     * It takes a rule spec rather than only a rule id, so it works on a rule
     * that has not been saved yet. That is the point — the blast radius is most
     * useful while the admin is still choosing the conditions, not after they
     * have committed to them.
     *
     * The count is over every lead an ADMIN can see, which is every lead. The
     * route is admin-only so there is no wider set to leak into; visibleTo is
     * applied regardless, because the day this page opens up to sales managers
     * is not the day to remember it.
     */
    public function matches(Request $request)
    {
        $data = $request->validate([
            'trigger'          => ['required', 'string', ValidationRule::in(array_keys(config('automation.triggers')))],
            'trigger_config'   => ['array'],
            'conditions'       => ['array', 'max:6'],
            'conditions.*.field' => ['required', 'string', ValidationRule::in(array_keys(config('automation.conditions')))],
            'conditions.*.value' => ['required'],
        ]);

        // an unsaved, unsaveable stand-in: it exists for the length of this
        // query and is never written
        $draft = new AutomationRule([
            'trigger'        => $data['trigger'],
            'trigger_config' => $data['trigger_config'] ?? [],
            'conditions'     => $data['conditions'] ?? [],
            'actions'        => [],
        ]);

        $query = $this->matcher->matchQuery($draft)->visibleTo($request->user());

        $sample = (clone $query)
            ->with(['project:id,name', 'owner:id,first_name,last_name'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (Lead $lead) => [
                'id'      => $lead->id,
                'name'    => $lead->full_name,
                'stage'   => CrmTaxonomy::stageLabel($lead->stage),
                'source'  => CrmTaxonomy::sourceLabel($lead->source),
                'project' => $lead->project?->name,
                'owner'   => $lead->owner?->display_name,
                'days_in_stage' => $lead->days_in_stage,
            ]);

        return response()->json([
            'count'   => $query->count(),
            'sample'  => $sample,
            // said in words, because "0" on its own reads as a failure
            'summary' => $this->summarise($query->count(), $data['trigger']),
        ]);
    }

    private function summarise(int $count, string $trigger): string
    {
        $kind = config("automation.triggers.$trigger.kind");

        if ($count === 0) {
            return $kind === 'event'
                ? 'No lead in the database matches these conditions today. That is fine for a rule that '
                    . 'watches for something happening — it will run on the next lead that fits.'
                : 'No lead matches yet. This rule runs on the hour and will act the first time one does.';
        }

        $leads = $count === 1 ? '1 lead' : "{$count} leads";

        return $kind === 'event'
            ? "{$leads} currently fit these conditions. The rule does not act on them now — it acts on the "
                . 'next lead that fits AND sets the trigger off.'
            : "{$leads} match right now. Switch this rule on and it will act on all of them within the hour.";
    }
}
