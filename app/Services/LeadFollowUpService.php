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
 *
 * Follow-ups are scheduled by hand. The user types the date, the type and an
 * optional note on the form they are already filling in — the add-lead form or
 * the log-call modal — and this class saves that datetime exactly as it was
 * entered. There is no interval table, no retry ladder and no working-hours
 * clamp: a time a person chose is a time a person chose, and moving it would be
 * second-guessing them.
 *
 * What has not changed is who owns the rules. This is still the only place that
 * writes a pending to-do, still one transaction per change, and still the
 * keeper of "an open lead has exactly one pending to-do".
 */
class LeadFollowUpService
{
    /**
     * Called when a lead is created. Gives it its first task from the date the
     * user picked on the form, because an open lead must never exist without a
     * pending to-do.
     *
     * $when is null only when the lead arrived at a terminal stage — booked or
     * lost on the way in, nothing left to follow up. The form hides the three
     * fields in that case and LeadRequest stops requiring them, so a null here
     * means "no task wanted" rather than "the user forgot".
     */
    public function onLeadCreated(
        Lead $lead,
        ?Carbon $when = null,
        ?string $type = null,
        ?string $remarks = null
    ): void {
        DB::transaction(function () use ($lead, $when, $type, $remarks) {
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

            if ($when && ! $lead->isTerminal()) {
                $this->createTodo($lead, $when, $type, $remarks);
            }
        });
    }

    /**
     * Complete a task, move the stage, save the next task the user picked.
     * All of it in one transaction: if the new task fails to save,
     * the stage change rolls back too, so a lead can never end up
     * with no open task and disappear from everyone's list.
     *
     * @return array{handed_over_to: ?string} what was decided without the user
     *         asking — see noticeFor()
     */
    public function complete(
        Todo $todo,
        string $stage,
        string $remarks,
        ?Carbon $nextAt = null,
        ?string $nextType = null,
        ?string $nextRemarks = null,
        array $extra = []
    ): array {
        return DB::transaction(function () use ($todo, $stage, $remarks, $nextAt, $nextType, $nextRemarks, $extra) {

            $lead = Lead::whereKey($todo->lead_id)->lockForUpdate()->firstOrFail();

            $todo->update([
                'status'        => 'completed',
                'remarks'       => $remarks,
                'outcome_stage' => $stage,
                'completed_at'  => now(),
                'completed_by'  => Auth::id(),
            ]);

            $this->applyStage($lead, $stage, $extra);

            return $this->schedule($lead, $stage, $nextAt, $nextType, $nextRemarks, $todo->id);
        });
    }

    /**
     * Stage change made from the lead form rather than from a call.
     *
     * @return array{handed_over_to: ?string}
     */
    public function changeStage(
        Lead $lead,
        string $stage,
        array $extra = [],
        ?Carbon $nextAt = null,
        ?string $nextType = null,
        ?string $nextRemarks = null
    ): array {
        return DB::transaction(function () use ($lead, $stage, $extra, $nextAt, $nextType, $nextRemarks) {
            $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();

            $this->applyStage($lead, $stage, $extra);

            // complete() records its transition on the to-do being closed; this
            // path has no such to-do, so without this the change leaves no
            // history at all
            $this->recordStageChange($lead, $stage, 'Stage changed from the lead form.');

            return $this->schedule($lead, $stage, $nextAt, $nextType, $nextRemarks);
        });
    }

    /**
     * Turns the outcome above into the one line worth telling the user.
     * Pure string building — the controller owns the flash, this class
     * never touches the session.
     */
    public function noticeFor(array $outcome): ?string
    {
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

        /*
         | Still counted, and still reset by any other outcome, because the
         | to-do rows and the follow-up panels print "attempt 3" beside a lead
         | nobody can reach. Nothing acts on the number any more: the ladder
         | that used to close a lead at five failed attempts went with the
         | automatic scheduling, so a lead is lost only when a user says so.
         */
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
     * Cancel what is pending, hand over if this is the handover stage, and
     * write the task the user asked for.
     *
     * @return array{handed_over_to: ?string}
     */
    private function schedule(
        Lead $lead,
        string $stage,
        ?Carbon $when,
        ?string $type,
        ?string $remarks,
        ?int $fromTodo = null
    ): array {
        $outcome  = ['handed_over_to' => null];
        $terminal = in_array($stage, config('crm.terminal_stages'), true);

        /*
         | Exactly one pending task per lead — so the one it is holding goes
         | when a closed lead should have none, and when a replacement date has
         | arrived to take its place. Not otherwise.
         |
         | That last clause is the whole reason this is a condition rather than
         | the unconditional cancel it used to be. The scheduler always had an
         | answer, so cancelling first and creating second could never leave a
         | gap. Now the date comes from a form, and a stage change that carries
         | no date — editing a lead's stage without touching its follow-up —
         | must leave the existing task standing rather than cancel it and put
         | nothing back. An open lead with no pending to-do is invisible on
         | every list in the application and would never be called again.
         */
        if ($terminal || $when) {
            Todo::where('lead_id', $lead->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);
        }

        // handover — scheduling a site visit moves the lead to a salesperson
        if ($stage === config('crm.handover_stage') && $lead->assigned_role === 'telecaller') {
            $outcome['handed_over_to'] = $this->handover($lead);
        }

        if ($terminal || ! $when) {
            return $outcome;
        }

        $this->createTodo($lead, $when, $type, $remarks, $fromTodo);

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
     * have no to-do to close: the lead form, and a lead created straight into a
     * stage other than `fresh`.
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

    /**
     * The next task, from what the user typed.
     *
     * `$when` is used verbatim. It is a datetime a person chose — the customer
     * is free on Sunday evening or they are not — and there is nothing left in
     * the application that would move it.
     */
    private function createTodo(
        Lead $lead,
        Carbon $when,
        ?string $type,
        ?string $remarks,
        ?int $fromTodo = null
    ): void {
        Todo::create([
            'lead_id'             => $lead->id,
            'assigned_to'         => $lead->assigned_to,
            'created_by'          => Auth::id(),
            'scheduled_at'        => $when,
            'type'                => $type ?? 'call',
            'status'              => 'pending',
            'remarks'             => $remarks,
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

        /*
         | The new task is created after this and reads $lead->assigned_to, so
         | it lands on the salesperson by itself. This is for the other path:
         | a stage change that brought no date leaves the lead's existing task
         | standing, and a task left behind on the telecaller's list would be
         | a handover the To-do page never carried out.
         */
        Todo::where('lead_id', $lead->id)
            ->where('status', 'pending')
            ->update(['assigned_to' => $next->id]);

        return $next->display_name;
    }
}
