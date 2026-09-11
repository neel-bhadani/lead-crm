<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\Todo;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Automation\ConditionMatcher;
use App\Services\Automation\RuleEngine;
use Illuminate\Console\Command;
use App\Support\CrmTaxonomy;

/**
 * The hourly half of automation.
 *
 * Two jobs, and they are separate on purpose.
 *
 * TIME-TRIGGERED RULES. "A follow-up is overdue by three days" and "a lead has
 * sat in Discussion for a week" are not events — nothing happens to a lead when
 * it becomes overdue, so there is nothing for LeadFollowUpService to announce.
 * The only way to notice is to ask, and this is what asks. It uses the very
 * same ConditionMatcher the Test button uses, so the leads a rule acts on at
 * three in the morning are exactly the leads the admin was shown when they
 * pressed Test.
 *
 * BUILT-IN ALERTS. Four things worth knowing that need no rule at all, because
 * every builder's office wants them and none of them should have to be
 * discovered. Their thresholds are in config('crm.alerts').
 *
 * The fourth built-in — automation suppressed by loop protection — is not here.
 * It is raised by RuleEngine at the moment of suppression, which is the only
 * place that knows it happened.
 *
 * Running this twice in an hour is safe and running it late is safe. Every
 * alert deduplicates over 24 hours and every rule has its own cooldown, so the
 * command is a nudge rather than a schedule anything depends on.
 */
class RunAutomation extends Command
{
    protected $signature = 'automation:run
                            {--rules-only : Skip the built-in alerts}
                            {--alerts-only : Skip the time-triggered rules}';

    protected $description = 'Run time-based automation rules and raise the built-in alerts';

    public function handle(
        RuleEngine $engine,
        ConditionMatcher $matcher,
        AlertService $alerts,
    ): int {
        if (! $this->option('alerts-only')) {
            $this->runTimeRules($engine, $matcher);
        }

        /*
         | A heartbeat, written every run.
         |
         | Time triggers and the built-in alerts only happen if `schedule:run`
         | is on a cron, and when it is not, nothing anywhere errors: event
         | rules go on working perfectly and the time ones simply never fire.
         | The Automation page reads this key to say so out loud, because the
         | admin has no other way to tell the difference between "no lead
         | matched" and "nothing has run since March".
         */
        /*
         | A unix timestamp, not a Carbon.
         |
         | Anything but the `array` driver serialises what it is given and hands
         | it back on a later request, in a different process. A Carbon object
         | that survives that round trip is a Carbon object you got lucky with:
         | on the database driver it came back as __PHP_Incomplete_Class and
         | took the whole Automation page down with a TypeError. An integer has
         | no such failure mode.
         */
        cache()->put('automation.last_run_at', now()->getTimestamp(), now()->addDay());

        if (! $this->option('rules-only')) {
            $this->overdueFollowUps($alerts);
            $this->stuckLeads($alerts);
            $this->deactivatedUsersHoldingLeads($alerts);
        }

        return self::SUCCESS;
    }

    /* ---------------- time-triggered rules ---------------- */

    private function runTimeRules(RuleEngine $engine, ConditionMatcher $matcher): void
    {
        $timeTriggers = collect(config('automation.triggers'))
            ->filter(fn (array $meta) => $meta['kind'] === 'time')
            ->keys()
            ->all();

        $rules = AutomationRule::active()->whereIn('trigger', $timeTriggers)->orderBy('id')->get();

        foreach ($rules as $rule) {
            $fired = 0;

            /*
             | chunkById, not chunk. The rules being run change the leads being
             | paged through — a rule that moves a lead out of the stage it was
             | selected for shifts every later page by one under an OFFSET, and
             | leads get skipped. Paging on the primary key is stable whatever
             | the actions do.
             */
            $matcher->matchQuery($rule)->chunkById(200, function ($leads) use ($engine, $rule, &$fired) {
                foreach ($leads as $lead) {
                    if ($engine->run($rule, $lead) === 'fired') {
                        $fired++;
                    }
                }
            });

            $this->line("  {$rule->name}: fired on {$fired} lead(s)");
        }
    }

    /* ---------------- built-in alerts ---------------- */

    /**
     * A follow-up nobody has done, days after it was due.
     *
     * Addressed to the person holding it, because they are the one who can act
     * on it. Admins are not copied in: an office of six telecallers each
     * carrying a few stale follow-ups would put twenty alerts a day under the
     * admin's bell, and a bell that is always full is a bell nobody opens.
     */
    private function overdueFollowUps(AlertService $alerts): void
    {
        $days  = (int) config('crm.alerts.overdue_days', 3);
        $since = now()->subDays($days)->startOfDay();
        $count = 0;

        Todo::query()
            ->pending()
            // a to-do whose lead was deleted is history, not work
            ->hasLead()
            ->where('scheduled_at', '<=', $since)
            ->with(['lead:id,first_name,middle_name,last_name,mobile_number,stage,assigned_to', 'owner'])
            ->chunkById(200, function ($todos) use ($alerts, $days, &$count) {
                foreach ($todos as $todo) {
                    if (! $todo->lead || ! $todo->owner) {
                        continue;
                    }

                    $late = (int) $todo->scheduled_at->diffInDays(now());

                    $count += $alerts->raise(
                        recipient: $todo->owner,
                        type: 'follow_up_overdue',
                        title: "{$todo->lead->full_name} — follow-up {$late} days overdue",
                        body: "This follow-up was due on {$todo->scheduled_at->format('d M Y')} "
                            . "and is still open. Anything more than {$days} days late is worth a call today.",
                        lead: $todo->lead,
                        severity: $late >= $days * 2 ? 'urgent' : 'warning',
                    ) ? 1 : 0;
                }
            });

        $this->line("  Overdue follow-ups: {$count} alert(s) raised");
    }

    /**
     * A lead that has stopped moving.
     *
     * Not the same thing as an overdue follow-up: a lead can be called every
     * week and still sit in Discussion for a month. This is the one that
     * catches a deal quietly going cold while everybody does their to-dos.
     */
    private function stuckLeads(AlertService $alerts): void
    {
        $days  = (int) config('crm.alerts.stuck_days', 7);
        $count = 0;

        Lead::query()
            ->open()
            ->whereNotNull('assigned_to')
            ->whereRaw('COALESCE(stage_changed_at, created_at) <= ?', [now()->subDays($days)])
            ->with('owner')
            ->chunkById(200, function ($leads) use ($alerts, $days, &$count) {
                foreach ($leads as $lead) {
                    if (! $lead->owner) {
                        continue;
                    }

                    $stage = CrmTaxonomy::stageLabel($lead->stage);
                    $held  = $lead->days_in_stage ?? $days;

                    $count += $alerts->raise(
                        recipient: $lead->owner,
                        type: 'lead_stuck',
                        title: "{$lead->full_name} has been in {$stage} for {$held} days",
                        body: "Nothing has moved this lead on since it reached {$stage}. "
                            . 'Move it forward, or mark it lost so it stops counting as open.',
                        lead: $lead,
                        severity: 'warning',
                    ) ? 1 : 0;
                }
            });

        $this->line("  Stuck leads: {$count} alert(s) raised");
    }

    /**
     * Somebody was switched off and still has open leads.
     *
     * This is the one that quietly loses business. A deactivated account cannot
     * sign in, so their leads are nobody's: they appear on no Follow-ups page,
     * and the only trace is a name in the Assigned column. UserHandoverService
     * exists to prevent it, but a user deactivated straight in the database — or
     * before that service existed — leaves exactly this.
     *
     * Addressed to admins, because they are the only people who can hand the
     * work on, and the alert carries no lead so it does not need a visibility
     * check. The type carries the user's id so that two abandoned accounts are
     * two alerts rather than one silencing the other for the day.
     */
    private function deactivatedUsersHoldingLeads(AlertService $alerts): void
    {
        $admins = $alerts->admins();
        $count  = 0;

        User::where('is_active', false)
            ->withCount(['leads as open_leads_count' => fn ($q) => $q->open()])
            ->get()
            ->filter(fn (User $u) => $u->open_leads_count > 0)
            ->each(function (User $user) use ($alerts, $admins, &$count) {
                $count += $alerts->raiseMany(
                    recipients: $admins,
                    type: 'user_deactivated_open_leads.' . $user->id,
                    title: "{$user->display_name} is switched off but still holds {$user->open_leads_count} open lead(s)",
                    body: 'Nobody is working these leads and they appear on no Follow-ups page. '
                        . 'Open the Users page and hand them to somebody.',
                    severity: 'urgent',
                    actionUrl: route('users.index'),
                );
            });

        $this->line("  Abandoned leads: {$count} alert(s) raised");
    }
}
