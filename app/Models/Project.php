<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A development the company is selling. Every lead belongs to one.
 *
 * SOFT DELETES, and not as a convenience. `leads.project_id` is constrained
 * with cascadeOnDelete, so a hard delete here would take every lead filed
 * against this project and every follow-up attached to those leads. The trait
 * is what stands between a Delete button and that; ProjectController refuses
 * the operation altogether while any lead exists, trashed ones included.
 *
 * `is_active` and `deleted_at` answer different questions and both are needed.
 * Inactive means "we are not selling this any more": it disappears from the
 * add-lead dropdown so nothing new can be filed against it, while every
 * existing lead, report and chart goes on working. Deleted means "this should
 * never have been here", and is only reachable for a project no lead has ever
 * pointed at.
 */
class Project extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * How this project's type reads on screen.
     *
     * The keys are validated against config('crm.project_types'), but a row
     * written before a type was retired still has to render as something, so an
     * unknown key falls back to itself rather than to a blank cell.
     */
    public function getTypeLabelAttribute(): string
    {
        return config("crm.project_types.{$this->type}", $this->type);
    }
}
