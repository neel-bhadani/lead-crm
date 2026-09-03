<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Todo extends Model
{
    protected $guarded = [];
    protected $casts = [
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
    public function owner()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }


    /**
     * Only todos whose lead is still there.
     *
     * `lead_id` is NOT NULL behind a cascading foreign key, so the column can
     * never dangle — but `Lead` soft deletes, and `LeadController::destroy()`
     * deliberately keeps a deleted lead's completed todos as history. Those
     * rows outlive the lead with a `lead` relation that resolves to null,
     * which is what took the To-do page down.
     *
     * `whereHas` runs the relation's own query, so the Lead soft-delete scope
     * applies and a trashed lead is excluded here exactly as it is from every
     * other lead query in the application. Restoring the lead brings its
     * history back with it.
     */
    public function scopeHasLead($query)
    {
        return $query->whereHas('lead');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue($query)
    {
        return $query->pending()->where('scheduled_at', '<', now()->startOfDay());
    }

    public function scopeDueToday($query)
    {
        return $query->pending()->whereDate('scheduled_at', today());
    }

    public function scopeUpcoming($query)
    {
        return $query->pending()->whereDate('scheduled_at', '>', today());
    }

    public function scopeForUser($query, User $user)
    {
        return $user->role === 'admin'
            ? $query
            : $query->where('assigned_to', $user->id);
    }
}
