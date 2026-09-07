<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/**
 * Who may do what to a lead, in one file.
 *
 * Two questions decide every answer here and they are deliberately separate:
 *
 *   *May this user do this kind of thing at all?*  — a permission, resolved by
 *   User::can_(), which reads the per-user JSON and falls back to the role's
 *   default. No role string appears below.
 *
 *   *May they do it to THIS lead?*  — ownership, which is Lead::visibleTo()'s
 *   rule said again for a single row. `see_all_leads` answers it for a manager
 *   and an admin alike.
 *
 * Both must pass. A telecaller granted `edit_leads` may edit the leads they
 * hold, not everybody's — the two toggles are independent on purpose, and
 * conflating them would turn "can edit" into "can see", which is the one
 * mistake in here that would leak data rather than merely annoy someone.
 *
 * Laravel 13 discovers this by name (App\Models\Lead -> App\Policies\LeadPolicy),
 * so there is nothing to register.
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return true;   // everyone has a list; visibleTo() decides what is on it
    }

    /** Ownership, said for one row. The scope says it for a query. */
    public function view(User $user, Lead $lead): bool
    {
        return $user->can_('see_all_leads') || $lead->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can_('add_leads');
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->can_('edit_leads') && $this->view($user, $lead);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->can_('delete_leads') && $this->view($user, $lead);
    }
}
