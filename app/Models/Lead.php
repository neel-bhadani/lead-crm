<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;
    protected $guarded = [];
    protected $casts = [
        'stage_changed_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'booking_date' => 'date',
    ];
    protected $appends = ['full_name', 'days_in_stage'];
    /* ---------------- relationships ---------------- */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }
    public function owner()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function todos()
    {
        return $this->hasMany(Todo::class);
    }
    public function pendingTodo()
    {
        return $this->hasOne(Todo::class)->where('status', 'pending');
    }
    public function completedTodos()
    {
        return $this->hasMany(Todo::class)
            ->where('status', 'completed')
            ->oldest('completed_at');
    }
    /* ---------------- accessors ---------------- */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }
    /**
     * Null means "no answer", never "zero days": a terminal lead has stopped
     * moving, and a constrained select that leaves `stage_changed_at` and
     * `created_at` out reads them as null rather than throwing. Any eager load
     * that carries this appended attribute has to select both columns — see
     * TodoController::index().
     */
    public function getDaysInStageAttribute(): ?int
    {
        if ($this->isTerminal()) {
            return null;
        }
        $since = $this->stage_changed_at ?? $this->created_at;
        return $since ? (int) $since->diffInDays(now()) : null;
    }
    public function isTerminal(): bool
    {
        return in_array($this->stage, config('crm.terminal_stages'));
    }
/* ---------------- scopes ---------------- */
    /**
     * The only thing protecting lead privacy.
     * Every lead query in the application must call this.
     */
    public function scopeVisibleTo($query, User $user)
    {
        return $user->role === 'admin'
            ? $query
            : $query->where('assigned_to', $user->id);
    }
    public function scopeOpen($query)
    {
        return $query->whereNotIn('stage', config('crm.terminal_stages'));
    }
}
