<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which sheet column (a visit, a follow-up, an absorbed row's history, the
 * final stage marker) a to-do came from, and the idempotency key for it. See
 * the migration for why source_column, not completed_at, is the third part
 * of the key. Written only by `import:legacy`, through the query builder, so
 * nothing here fires on insert.
 */
class TodoImportRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }
}
