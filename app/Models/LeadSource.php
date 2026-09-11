<?php

namespace App\Models;

use App\Support\CrmTaxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a lead came from — what used to be a line in `config('crm.sources')`.
 *
 * The same rules as LeadStage and for a sharper reason: `leads.source` is the
 * dimension the Leads report defaults to grouping by, so a source row that
 * disappeared would not break a page, it would quietly change last quarter's
 * numbers. Deactivate, never delete, unless nothing has ever been filed under
 * it — see LeadSourceController::destroy().
 *
 * `default_stage_key` and `default_owner_role` are the two columns the brief
 * calls `source_defaults`. Nothing in this application has ever had such a
 * config block — grep it — so they are seeded null and are, for now, recorded
 * and edited but not applied: wiring them into lead creation would change what
 * `POST /leads` does to every new lead, which is a behaviour change and not
 * this migration's job. The columns and the screen are here so that change is a
 * one-line read when it is asked for.
 */
class LeadSource extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sort_order' => 'integer',
        'is_system'  => 'boolean',
        'is_active'  => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => CrmTaxonomy::flush());
        static::deleted(fn () => CrmTaxonomy::flush());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function leadCount(): int
    {
        return Lead::where('source', $this->key)->count();
    }
}
