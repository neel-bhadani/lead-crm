<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Every stage change in the application goes through this class.
 * Nothing else may write leads.stage directly.
 */
class LeadFollowUpService
{
    public function __construct(private FollowUpScheduler $scheduler) {}

    /**
     * Called when a lead is created. Gives it its first task immediately,
     * because a lead must never exist without a pending to-do.
     */
    public function onLeadCreated(Lead $lead): void
    {
        DB::transaction(function () use ($lead) {
            /*
             | Added at anything but `fresh` — a backfill. Someone is typing in
             | a lead that has already been called, already visited, already
             | booked or already lost, and every one of those is an event that
             | happened: the three history cards and "Stage changes in this
             | range" read `todos.outcome_stage`, so a transition with no row
             | did not happen as far as the dashboard is concerned.
             |
             | This used to fire only for the terminal stages, which left one
             | visible hole. A lead added straight at "Site visit done" showed
             | up in the Leads-by-stage chart under Site visit done and was
             | never counted by the Site visits done card, in any range, ever —
             | a card and a chart contradicting each other on the same lead.
             | Booking and Lost were covered only because they happen to be the
             | terminal pair.
             |
             | `fresh` is the one stage that is genuinely not a transition: the
             | lead has just arrived and nothing has been done to it yet.
             | Recording that would fabricate a transition the lead never made
             | and put every new lead on a bar of the stage-changes chart.
             */
            if ($lead->stage !== 'fresh') {
                $this->recordStageChange($lead, $lead->stage, 'Lead added at this stage.');
            }

            // booked or lost on arrival: nothing left to schedule
            if ($lead->isTerminal()) {
                return;
            }

            // system-generated like any other, so it gets the same treatment:
            // a lead added at 9 PM is a call to make in the morning
            $this->createTodo(
                $lead,
                $this->scheduler->withinWorkingHours(now()),
                $lead->stage
            );
        });
    }

    /**
     * Complete a task, move the stage, schedule the next task.
     * All of it in one transaction: if the new task fails to save,
     * the stage change rolls back too, so a lead can never end up
     * with no open task and disappear from everyone's list.
     *
     * @return array{auto_lost: bool, handed_over_to: ?string} what was decided
     *         without the user asking — see noticeFor()
     */
    public function complete(
        Todo $todo,
        string $stage,
        string $remarks,
        ?Carbon $visitAt = null,
        array $extra = []
    ): array {
        return DB::transaction(function () use ($todo, $stage, $remarks, $visitAt, $extra) {

            $lead = Lead::whereKey($todo->lead_id)->lockForUpdate()->firstOrFail();

            $todo->update([
                'status'        => 'completed',
                'remarks'       => $remarks,
                'outcome_stage' => $stage,
                'completed_at'  => now(),
                'completed_by'  => Auth::id(),
            ]);

            $this->applyStage($lead, $stage, $extra);

            return $this->schedule($lead, $stage, $visitAt, $todo->id);
        });
    }

    /**
     * Stage change made from the lead form rather than from a call.
     *
     * @return array{auto_lost: bool, handed_over_to: ?string}
     */
    public function changeStage(Lead $lead, string $stage, array $extra = []): array
    {
        return DB::transaction(function () use ($lead, $stage, $extra) {
            $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();

            $this->applyStage($lead, $stage, $extra);

            // complete() records its transition on the to-do being closed; this
            // path has no such to-do, so without this the change leaves no
            // history at all
            $this->recordStageChange($lead, $stage, 'Stage changed from the lead form.');

            return $this->schedule($lead, $stage);
        });
    }

    /**
     * Turns the outcome above into the one line worth telling the user.
     * Pure string building — the controller owns the flash, this class
     * never touches the session.
     */
    public function noticeFor(array $outcome): ?string
    {
        if ($outcome['auto_lost'] ?? false) {
            return 'No response after ' . config('crm.max_attempts')
                . ' attempts. Lead marked as lost.';
        }

        if ($outcome['handed_over_to'] ?? null) {
            return "Lead handed over to {$outcome['handed_over_to']}.";
        }

        return null;
    }

    /* ---------------------------------------------------------- */

    private function applyStage(Lead $lead, string $stage, array $extra = []): void
    {
        $lead->stage            = $stage;
        $lead->stage_changed_at = now();
        $lead->last_activity_at = now();

        $lead->not_connected_count = $stage === 'not_connected'
            ? $lead->not_connected_count + 1
            : 0;

        if ($stage === 'lost') {
            $lead->reason = $extra['reason'] ?? $lead->reason;
        }

        if ($stage === 'booking_done') {
            $lead->booked_unit  = $extra['booked_unit'] ?? $lead->booked_unit;
            $lead->booking_date = $extra['booking_date'] ?? now()->toDateString();
        }

        $lead->save();
    }

    /**
     * @return array{auto_lost: bool, handed_over_to: ?string}
     */
    private function schedule(
        Lead $lead,
        string $stage,
        ?Carbon $visitAt = null,
        ?int $fromTodo = null
    ): array {
        $outcome = ['auto_lost' => false, 'handed_over_to' => null];

        // exactly one pending task per lead, always
        Todo::where('lead_id', $lead->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        // handover — scheduling a site visit moves the lead to a salesperson
        if ($stage === config('crm.handover_stage') && $lead->assigned_role === 'telecaller') {
            $outcome['handed_over_to'] = $this->handover($lead);
        }

        // retry ladder exhausted — close the lead instead of scheduling
        if ($this->scheduler->attemptsExhausted($lead, $stage)) {
            $lead->forceFill([
                'stage'            => 'lost',
                'reason'           => 'no_response',
                'stage_changed_at' => now(),
            ])->save();

            /*
             | The lead just moved to lost, and nothing else is going to say so.
             | The to-do the user completed carries the stage they chose — the
             | one that exhausted the ladder — not this one, so an auto-lost
             | lead was invisible to every count that reads the history.
             */
            $this->recordStageChange($lead, 'lost', 'No response after '
                . config('crm.max_attempts') . ' attempts — closed automatically.');

            $outcome['auto_lost'] = true;

            return $outcome;
        }

        /*
         | The one distinction in this file worth reading twice.
         |
         | $visitAt is the site visit the *customer* chose, typed into the
         | log-call modal. It is used exactly as entered — 8 PM on a Sunday is
         | 8 PM on a Sunday, because the customer decides when they are free to
         | visit, not the office diary. It must never be passed through
         | withinWorkingHours().
         |
         | Everything else on this line is *system*-generated, and next() has
         | already pulled it inside working hours.
         */
        $when = $visitAt ?? $this->scheduler->next($lead, $stage);

        if (! $when) {
            return $outcome;
        }

        $this->createTodo($lead, $when, $stage, $fromTodo);

        return $outcome;
    }

    /**
     * Write a stage transition into the history.
     *
     * The history is completed to-dos: `outcome_stage` is where the lead went,
     * `completed_at` is when. Everything that asks "how many bookings this
     * week" reads that, so a transition with no row is a transition that never
     * happened as far as the dashboard is concerned.
     *
     * complete() already writes one — it closes the to-do the user was working
     * on and stamps the outcome onto it — so this is only for the paths that
     * have no to-do to close: the lead form, the auto-lost rule, and a lead
     * created straight into a terminal stage.
     *
     * The row is `completed`, so it never becomes someone's task and cannot
     * affect "every open lead has a pending to-do". It does appear on the
     * Completed tab, which is the point: the remark says where it came from.
     */
    private function recordStageChange(Lead $lead, string $stage, string $remarks): void
    {
        Todo::create([
            'lead_id'       => $lead->id,
            'assigned_to'   => $lead->assigned_to,
            'created_by'    => Auth::id(),
            'scheduled_at'  => now(),
            'type'          => 'call',
            'status'        => 'completed',
            'remarks'       => $remarks,
            'outcome_stage' => $stage,
            'completed_at'  => now(),
            'completed_by'  => Auth::id(),
        ]);
    }

    private function createTodo(Lead $lead, Carbon $when, string $stage, ?int $fromTodo = null): void
    {
        Todo::create([
            'lead_id'             => $lead->id,
            'assigned_to'         => $lead->assigned_to,
            'created_by'          => AUth::id(),
            'scheduled_at'        => $when,
            'type'                => $stage === config('crm.handover_stage') ? 'site_visit' : 'call',
            'status'              => 'pending',
            'rescheduled_from_id' => $fromTodo,
        ]);
    }

    /**
     * Round-robin across active salespeople.
     * The counter lives in cache so it survives between requests.
     *
     * @return string|null the name the lead went to, or null if it stayed put
     */
    private function handover(Lead $lead): ?string
    {
        if (config('crm.handover_mode') !== 'round_robin') {
            return null;
        }

        // the whole row, not just the id — the caller needs a name to show
        $people = User::where('role', 'salesperson')
            ->where('is_active', true)
            ->orderBy('id')
            // no 'name': the CRM migration dropped that column in favour of
            // first_name/last_name, and display_name is built from those
            ->get(['id', 'first_name', 'last_name']);

        if ($people->isEmpty()) {
            return null;
        }

        $lastId = (int) cache()->get('last_assigned_salesperson', 0);
        $next   = $people->first(fn($u) => $u->id > $lastId) ?? $people->first();

        cache()->forever('last_assigned_salesperson', $next->id);

        $lead->assigned_to   = $next->id;
        $lead->assigned_role = 'salesperson';
        $lead->save();

        return $next->display_name;
    }
}
