<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moving a departing user's live work somewhere else.
 *
 * Deactivating or deleting a staff member is never only about that row. They
 * are holding open leads and pending to-dos, and the application has one
 * invariant it cannot survive losing:
 *
 *     Lead::open()->doesntHave('pendingTodo')->count() === 0
 *
 * Every list, badge and chart is built on it. So nothing in this class ever
 * cancels or deletes a to-do — it only changes who owns one. An open lead
 * keeps the pending row it already had, because `todos.lead_id` is not what is
 * moving. That is the reason the invariant holds here by construction rather
 * than by being checked afterwards.
 *
 * The two shapes of the same operation:
 *
 *   reassign          leads and their pending to-dos both move to the target.
 *
 *   leave unassigned  the LEADS move to nobody — `leads.assigned_to` is
 *                     nullable and that is the point of the option. Their
 *                     pending to-dos cannot follow: `todos.assigned_to` is NOT
 *                     NULL behind a foreign key, so "unassign the to-do too"
 *                     is not a thing the schema permits, and cancelling them
 *                     instead would leave open leads with no task and break
 *                     the invariant above. They go to the admin doing this, who
 *                     is the one person guaranteed to still be here. The
 *                     confirmation dialog says so before it happens; this is
 *                     not something the user should discover afterwards.
 *
 * Closed leads never move. A booked lead records who booked it, and the
 * dashboard's per-salesperson conversion charts read exactly that column —
 * reassigning history would rewrite it.
 */
class UserHandoverService
{
    /**
     * Move `$from`'s live work, in one transaction.
     *
     * @param  User   $from  the user being deactivated or deleted
     * @param  ?User  $to    the new owner, or null for "leave unassigned"
     * @param  User   $actor the admin performing this; catches the to-dos that
     *                       have nowhere else to go when $to is null
     * @return array{leads: int, todos: int, todos_to_actor: bool}
     */
    public function transfer(User $from, ?User $to, User $actor): array
    {
        /*
         | One transaction, because a half-done handover is worse than either
         | end of it: leads moved with their to-dos left behind puts a task on
         | a departed user's list for a lead they no longer own, and the
         | reverse hides the lead from the person now expected to call it.
         */
        return DB::transaction(function () use ($from, $to, $actor) {
            $leads = Lead::where('assigned_to', $from->id)
                ->open()
                ->update([
                    'assigned_to'   => $to?->id,
                    // kept in step with the owner, or the Leads page shows a
                    // lead sitting with nobody under a role that says otherwise
                    'assigned_role' => $to?->role,
                ]);

            /*
             | Pending only. A completed to-do is the record of a call that
             | happened and names who made it; moving those would credit one
             | person's work to another and change what the Completed tab says.
             */
            $todos = Todo::where('assigned_to', $from->id)
                ->where('status', 'pending')
                ->update(['assigned_to' => ($to ?? $actor)->id]);

            return [
                'leads'          => $leads,
                'todos'          => $todos,
                'todos_to_actor' => $to === null && $todos > 0,
            ];
        });
    }

    /** What `$user` is currently holding, for the confirmation dialog. */
    public function workload(User $user): array
    {
        return [
            'open_leads'    => Lead::where('assigned_to', $user->id)->open()->count(),
            'pending_todos' => Todo::where('assigned_to', $user->id)->where('status', 'pending')->count(),
        ];
    }

    /** True when there is anything at all that a handover would have to move. */
    public function holdsWork(User $user): bool
    {
        return array_sum($this->workload($user)) > 0;
    }

    /**
     * A sentence for the flash message. The admin has just made a choice with
     * consequences they cannot see on the page they are returned to, so the
     * result says what actually moved rather than "Saved".
     */
    public function summarise(array $result, ?User $to): ?string
    {
        if ($result['leads'] === 0 && $result['todos'] === 0) {
            return null;
        }

        $leads = $result['leads'] . ' ' . str('lead')->plural($result['leads']);
        $todos = $result['todos'] . ' ' . str('follow-up')->plural($result['todos']);

        if ($to) {
            return "{$leads} and {$todos} moved to {$to->display_name}.";
        }

        return "{$leads} left unassigned. {$todos} moved to you so nothing falls off the schedule.";
    }
}
