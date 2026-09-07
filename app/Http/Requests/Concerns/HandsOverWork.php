<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use Illuminate\Validation\Validator;

/**
 * The "who takes this over" half of a form, shared by the two requests that
 * can strand somebody's work: deleting a user, and deactivating one.
 *
 * They are one rule in one place because they are one decision. The dialog is
 * the same dialog, the consequences are the same consequences, and a copy that
 * drifted in either request would let a user out of the application still
 * holding open leads — which is the failure this whole feature exists to
 * prevent.
 *
 * The host request decides *when* the choice is demanded by implementing
 * departingUser(): a user holding nothing needs no handover, and asking anyway
 * would put a dropdown in front of an admin for no reason.
 */
trait HandsOverWork
{
    /** The user whose work is at stake, or null when nothing is at stake. */
    abstract protected function departingUser(): ?User;

    /** @return array<string, array<mixed>> */
    protected function handoverRules(): array
    {
        return [
            'handover_to'      => ['nullable', 'integer', 'exists:users,id'],
            'leave_unassigned' => ['boolean'],
        ];
    }

    /**
     * Everything the two fields cannot say on their own.
     *
     * `exists:users,id` above only proves the row is there. Whether that row
     * is a sensible destination — active, not the person leaving, doing the
     * same job — is this, and it has to be checked server-side because the
     * dropdown that offers the choices is not what enforces it.
     */
    protected function validateHandover(Validator $validator): void
    {
        $validator->after(function ($v) {
            $from = $this->departingUser();

            if (! $from || ! app(\App\Services\UserHandoverService::class)->holdsWork($from)) {
                return;   // nothing to move; the fields are ignored
            }

            $targetId = $this->input('handover_to');

            /*
             | Exactly one of the two, and neither is a default. An admin who
             | submits neither has not decided yet, and guessing on their
             | behalf is how records get silently orphaned — the one thing the
             | brief is explicit about.
             */
            if (! $targetId && ! $this->boolean('leave_unassigned')) {
                $v->errors()->add(
                    'handover_to',
                    'Choose who takes over this user\'s open leads and pending follow-ups, or confirm leaving them unassigned.'
                );

                return;
            }

            if (! $targetId) {
                return;   // leave-unassigned, explicitly confirmed
            }

            $to = User::find($targetId);

            if ((int) $targetId === (int) $from->id) {
                $v->errors()->add('handover_to', 'Pick somebody other than the user being removed.');

                return;
            }

            if (! $to || ! $to->is_active) {
                $v->errors()->add('handover_to', 'That user is not active and cannot take over the work.');

                return;
            }

            /*
             | Same role, and not merely for tidiness. `leads.assigned_role`
             | travels with `assigned_to`, and the pipeline is split by it — a
             | telecaller handed leads at the site-visit stage cannot work
             | them, and the handover that is supposed to move a lead to a
             | salesperson would have nothing left to move.
             */
            if ($to->role !== $from->role) {
                $v->errors()->add(
                    'handover_to',
                    'Work can only be handed to another ' . (config('crm.role_labels')[$from->role] ?? $from->role) . '.'
                );
            }
        });
    }

    /** The chosen destination, or null when the admin said "leave unassigned". */
    public function handoverTarget(): ?User
    {
        return $this->filled('handover_to') ? User::find($this->input('handover_to')) : null;
    }
}
