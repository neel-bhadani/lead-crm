<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of "what the automation did, and why".
 *
 * Written once, never updated — hence no timestamps and a `fired_at` the writer
 * sets. Every action writes one, and so does every suppression: see the
 * migration for what `action` and `result` may hold.
 */
class AutomationLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'fired_at' => 'datetime',
    ];

    public function rule()
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    /** Whether this line is one somebody needs to look at. */
    public function isBad(): bool
    {
        return in_array($this->result, ['failed', 'loop_guard', 'cooldown'], true);
    }
}
