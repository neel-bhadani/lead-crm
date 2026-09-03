<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Carbon;

/**
 * Decides when the next follow-up is due.
 * Kept separate from the service so it can be unit tested on its own —
 * this is the one piece of business logic genuinely worth testing.
 *
 * Everything this class returns is a *system-generated* time, and every one of
 * them is pulled inside working hours. A datetime the customer chose — the site
 * visit typed into the log-call modal — never comes through here at all; see
 * LeadFollowUpService::schedule(), which uses it verbatim.
 */
class FollowUpScheduler
{
    /**
     * How far ahead withinWorkingHours() will look for an open day before it
     * gives up. Only reachable if the config closes the office permanently, and
     * a fortnight is far past the point where that is a configuration mistake
     * rather than a long holiday.
     */
    private const MAX_DAYS_AHEAD = 14;

    /**
     * Returns null when no follow-up should exist:
     * either the stage is terminal, or the retry ladder is exhausted.
     */
    public function next(Lead $lead, string $stage): ?Carbon
    {
        if (in_array($stage, config('crm.terminal_stages'))) {
            return null;
        }

        if ($stage === 'not_connected') {
            $attempt = $lead->not_connected_count + 1;
            $hours   = config("crm.retry_hours.$attempt");

            return $hours === null ? null : $this->withinWorkingHours(now()->addHours($hours));
        }

        return $this->withinWorkingHours(now()->addHours(config("crm.followup_hours.$stage", 24)));
    }

    /**
     * Pull a system-generated time inside working hours.
     *
     * The interval decides roughly when; this decides whether that moment is
     * one a person could actually make the call, and moves it if not:
     *
     *   before opening, on an open day  →  opening time, same day
     *   inside the open hours           →  left exactly where it is
     *   at or after closing             →  opening time, next open day
     *   a closed day or a holiday       →  opening time, next open day
     *
     * The last two chain, so 11 PM on a Saturday in a Monday-to-Friday office
     * lands on Monday at opening rather than Sunday.
     *
     * Times are compared in the app timezone (Asia/Kolkata), never in UTC.
     */
    public function withinWorkingHours(Carbon $when): Carbon
    {
        $start = (int) config('crm.working_hours.start');
        $end   = (int) config('crm.working_hours.end');

        $when = $when->copy();

        if ($this->isWorkingDay($when)) {
            // early on an open day is the one case that stays on this day — a
            // 4 PM call plus 4 hours is tomorrow morning, not tomorrow evening
            if ($when->hour < $start) {
                return $when->setTime($start, 0);
            }

            // already usable: a 10 AM call plus 4 hours is 2 PM today, and
            // nothing here is allowed to push it to tomorrow
            if ($when->hour < $end) {
                return $when;
            }
        }

        $next = $when->copy()->addDay()->setTime($start, 0);

        for ($i = 0; $i < self::MAX_DAYS_AHEAD; $i++) {
            if ($this->isWorkingDay($next)) {
                return $next;
            }

            $next->addDay();
        }

        /*
         | Every day in the next fortnight is closed, so the config has no open
         | day to offer. Hand back the unclamped time rather than looping or
         | throwing: an awkward follow-up time is a nuisance, while a lead left
         | with no pending to-do at all breaks an invariant the whole To-do page
         | rests on.
         */
        return $when;
    }

    /**
     * True when the caller should mark the lead lost instead of scheduling.
     */
    public function attemptsExhausted(Lead $lead, string $stage): bool
    {
        if ($stage !== 'not_connected') {
            return false;
        }

        return config('crm.retry_hours.' . ($lead->not_connected_count + 1)) === null;
    }

    /**
     * Human preview shown in the modals before saving.
     *
     * The front end used to work this out for itself from a copy of the hour
     * map. It cannot any more — it would have to know the working days and the
     * holidays too — so the answer is computed here and passed down.
     */
    public function preview(Lead $lead, string $stage): string
    {
        if (in_array($stage, config('crm.terminal_stages'))) {
            return 'This closes the lead. No further follow-up will be scheduled.';
        }

        if ($this->attemptsExhausted($lead, $stage)) {
            return 'No response after ' . config('crm.max_attempts')
                . ' attempts — the lead will be marked lost automatically.';
        }

        $when = $this->next($lead, $stage);

        if (! $when) {
            return 'No further follow-up will be scheduled.';
        }

        return 'Next follow-up will be scheduled for ' . $when->format('d M Y, h:i A') . '.';
    }

    /**
     * Every stage the user can pick, with the line to show for it. Built here
     * because the modal has to answer as the dropdown changes, without asking
     * the server again on every keystroke.
     *
     * @return array<string, string>
     */
    public function previews(?Lead $lead = null): array
    {
        $lead ??= new Lead(['stage' => 'fresh', 'not_connected_count' => 0]);

        $previews = [];

        foreach (array_keys(config('crm.stages')) as $stage) {
            $previews[$stage] = $this->preview($lead, $stage);
        }

        return $previews;
    }

    /**
     * What a brand-new lead gets, keyed the same way.
     *
     * This cannot reuse previews(): onLeadCreated() does not call next() at
     * all. A new lead is called as soon as someone is in the office, not after
     * an interval, so the honest line is the opening time and not "in 48
     * hours" — which is what the old hardcoded preview claimed.
     *
     * @return array<string, string>
     */
    public function previewsForNewLead(): array
    {
        $when     = $this->withinWorkingHours(now());
        $terminal = config('crm.terminal_stages');

        $previews = [];

        foreach (array_keys(config('crm.stages')) as $stage) {
            $previews[$stage] = in_array($stage, $terminal)
                ? 'This lead is closed, so no follow-up will be scheduled.'
                : 'A follow-up call will be scheduled for ' . $when->format('d M Y, h:i A') . '.';
        }

        return $previews;
    }

    /** A day the office is open: an allowed weekday that is not a holiday. */
    private function isWorkingDay(Carbon $day): bool
    {
        if (in_array($day->toDateString(), (array) config('crm.holidays', []), true)) {
            return false;
        }

        $open = array_map('intval', (array) config('crm.working_days', []));

        // ISO-8601 numbering, so Monday is 1 and Sunday is 7 — see config/crm.php
        return in_array((int) $day->dayOfWeekIso, $open, true);
    }
}
