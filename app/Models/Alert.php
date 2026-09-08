<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Something a person should know, sitting under the bell until they read it.
 *
 * An alert is not work. It does not appear on the To-do page, it is not counted
 * by anything the dashboard measures, and marking it read does not touch the
 * lead, its stage or its follow-up — it writes `read_at` on this row and stops.
 * That separation is the entire justification for a second table: a "follow-up"
 * that could be dismissed without doing anything would quietly break "every
 * open lead has a pending to-do".
 *
 * Nothing creates one of these directly. AlertService::raise() is the only
 * writer, because two things have to happen first — the recipient has to be
 * able to see the lead, and the same alert must not already have been raised
 * in the last day.
 */
class Alert extends Model
{
    /** Written once. `read_at` is the only thing that changes, and it is its own column. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'read_at'    => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function rule()
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }

    /* ---------------- scopes ---------------- */

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * One person's alerts, and nobody else's.
     *
     * Alerts are addressed, not shared: an alert row names its recipient, so
     * this is a plain `user_id` match for every role including admin. An admin
     * does not read other people's bells — they get their own alerts, raised to
     * them by name or as part of "all admins".
     *
     * The lead-level privacy check happens at creation instead, in
     * AlertService::raise(), which is the right place for it: an alert that
     * should never have existed must not be written and then hidden, because
     * the title itself carries the lead's name.
     */
    public function scopeFor($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }
}
