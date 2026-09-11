<?php

namespace App\Models;

use App\Support\CrmTaxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One stage of the pipeline — what used to be a line in `config('crm.stages')`.
 *
 * NEVER SOFT DELETED, and never hard deleted while anything points at it.
 * A stage is not a record of something that happened, it is the vocabulary the
 * records are written in: `leads.stage` and `todos.outcome_stage` hold its key
 * as a bare string, so a row that goes away takes the meaning of every one of
 * those strings with it. Switching it off is the operation an admin actually
 * wants — it leaves every lead and every history row exactly as it was and only
 * stops the stage being chosen again. See LeadStageController::destroy().
 *
 * `is_system` is the second lock. Six of the nine stages are named in PHP —
 * grep `'fresh'`, `'not_connected'`, `'site_visit_done'`, `'booking_done'`,
 * `'lost'` and `config('crm.handover_stage')` — and code cannot be told that a
 * word it depends on has been retired. Their labels and colours are free to
 * change, because nothing branches on those.
 */
class LeadStage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sort_order' => 'integer',
        'is_terminal' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Every write busts the cached vocabulary, from the model rather than from
     * the controller.
     *
     * The controller is not the only writer — the migration seeds, a future
     * seeder may too, and `tinker` certainly will — and a cache that is only
     * cleared by the one caller who remembered is a cache that serves a stage
     * somebody switched off half an hour ago.
     */
    protected static function booted(): void
    {
        /*
         | `owner_role` — the desk a new lead at this stage is given to — is
         | settled here rather than in the controller, for the same reason the
         | cache is busted here: every writer passes through.
         |
         | A terminal stage has no desk, whatever was sent: nothing is left to
         | follow up, so the lead stays with whoever added it. An open stage
         | always has one, and one left empty (a new stage, or a stage switched
         | from terminal back to open) takes the seeded default — an open lead
         | routed to nobody would be on nobody's list.
         */
        static::saving(function (LeadStage $stage) {
            if ($stage->is_terminal) {
                $stage->owner_role = null;
            } elseif (! $stage->owner_role) {
                $stage->owner_role = CrmTaxonomy::seededOwnerRole((string) $stage->key);
            }
        });

        static::saved(fn () => CrmTaxonomy::flush());
        static::deleted(fn () => CrmTaxonomy::flush());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The order every chart axis, dropdown and funnel band is drawn in. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** How many leads are standing in this stage right now. */
    public function leadCount(): int
    {
        return Lead::where('stage', $this->key)->count();
    }
}
