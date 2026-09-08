<?php

namespace App\Services\Automation;

use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\Lead;

/**
 * What stops two innocent rules destroying an afternoon.
 *
 * Rule A: "when a lead moves to Not connected, move it to Fresh."
 * Rule B: "when a lead moves to Fresh, move it to Not connected."
 *
 * Neither is unreasonable on its own and neither mentions the other. Together
 * they move one lead back and forth until the request times out, writing a
 * to-do, an alert and a WhatsApp message every time round. Nobody writes this
 * pair on purpose; people write it by adding the second rule three weeks after
 * the first.
 *
 * Two caps, because they stop two different things:
 *
 *   THE CHAIN CAP is in memory and counts per lead. One original event — a
 *   person clicking Save — may cascade into at most three automation touches
 *   of the same lead, however many rules are involved. This is what breaks the
 *   ping-pong above, on the third bounce, inside the same request.
 *
 *   THE COOLDOWN is in the database and counts per rule per lead. One rule may
 *   act on one lead once an hour and no more. This is what the in-memory
 *   counter cannot do: it survives the end of the request, so it also holds
 *   across the hourly command, across a queue worker, and across the case where
 *   the loop is not a loop at all but a person clicking Save four times.
 *
 * Neither is silent. Every suppression is written to automation_logs and
 * admins are alerted — see RuleEngine. A rule that is switched on, matches, and
 * does nothing is the most confusing thing this feature can produce, and the
 * admin has to be able to find out why without reading code.
 *
 * Registered as a singleton in AppServiceProvider: the chain counter is state
 * that has to survive from one nested rule to the next within a request, and a
 * fresh instance per injection would count to one forever.
 */
class LoopGuard
{
    /** Nesting depth. Touches are cleared when this returns to zero. */
    private int $depth = 0;

    /** @var array<int, int> lead id => automation touches in this chain */
    private array $touches = [];

    /* ---------------- the chain ---------------- */

    public function enterChain(): void
    {
        $this->depth++;
    }

    public function leaveChain(): void
    {
        $this->depth = max(0, $this->depth - 1);

        // the original event has finished cascading; the next one starts fresh
        if ($this->depth === 0) {
            $this->touches = [];
        }
    }

    /** How many times automation has already acted on this lead in this chain. */
    public function touchCount(Lead $lead): int
    {
        return $this->touches[$lead->id] ?? 0;
    }

    public function recordTouch(Lead $lead): void
    {
        $this->touches[$lead->id] = $this->touchCount($lead) + 1;
    }

    /* ---------------- the verdict ---------------- */

    /**
     * May this rule act on this lead?
     *
     * @return string|null  null to proceed, otherwise the reason — `loop_guard`
     *                      or `cooldown`, which is what gets written to
     *                      automation_logs.result and shown on the Activity tab.
     */
    public function verdict(AutomationRule $rule, Lead $lead): ?string
    {
        $max = (int) config('automation.loop_protection.max_touches_per_chain', 3);

        if ($this->touchCount($lead) >= $max) {
            return 'loop_guard';
        }

        if ($this->firedRecently($rule, $lead)) {
            return 'cooldown';
        }

        return null;
    }

    /**
     * Has this rule already acted on this lead inside its cooldown window?
     *
     * Reads the `rule_fired` line the engine writes before it runs the actions,
     * which is the whole reason that line is written first: a cooldown that
     * depended on the actions succeeding would let a failing rule retry
     * endlessly, and a failing rule is exactly the one you least want retrying.
     */
    public function firedRecently(AutomationRule $rule, Lead $lead): bool
    {
        return AutomationLog::where('rule_id', $rule->id)
            ->where('lead_id', $lead->id)
            ->where('action', 'rule_fired')
            ->where('fired_at', '>=', now()->subMinutes($this->cooldownFor($rule)))
            ->exists();
    }

    /**
     * A time trigger's window is the longer one. See the config for why: it is
     * the same rule set further out, not a different rule.
     */
    public function cooldownFor(AutomationRule $rule): int
    {
        $kind = config("automation.triggers.{$rule->trigger}.kind", 'event');

        return (int) ($kind === 'time'
            ? config('automation.loop_protection.time_trigger_cooldown_minutes', 1440)
            : config('automation.loop_protection.cooldown_minutes', 60));
    }
}
