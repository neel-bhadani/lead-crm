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
    /**
     * The firm or broker this lead came through, when it came through one.
     *
     * Null on every lead created before channel partners existed, and on every
     * lead whose source is not `broker`. `broker_name` is the fallback for the
     * first of those — see getBrokerLabelAttribute().
     */
    public function channelPartner()
    {
        return $this->belongsTo(ChannelPartner::class);
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
    /**
     * Built from the parts that are actually there, not from a template with
     * holes in it: interpolating an empty `middle_name` leaves the two spaces
     * around it, and trim() only takes off the outer ones.
     */
    public function getFullNameAttribute(): string
    {
        $parts = array_filter(
            [$this->first_name, $this->middle_name, $this->last_name],
            fn ($part) => trim((string) $part) !== '',
        );

        return implode(' ', array_map(fn ($part) => trim((string) $part), $parts));
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
    /**
     * Who brought this lead, whichever of the two columns knows.
     *
     * The partner row first, the old free text second. That order is the whole
     * of the migration story: `channel_partner_id` is what new leads write, and
     * `broker_name` is what every lead created before this feature carries and
     * goes on carrying. Nothing matched the old strings to the new rows — see
     * the migration for why guessing at names would put business in the wrong
     * broker's column — so a lead answers with whichever it actually has.
     *
     * Null when the lead did not come through a broker at all.
     *
     * Not appended: it reads a relation, and a list of leads that had not eager
     * loaded it would pay a query a row. The pages that show it load
     * `channelPartner.parent` and ask for it by name.
     */
    public function getBrokerLabelAttribute(): ?string
    {
        if ($this->source !== 'broker') {
            return null;
        }
        $label = $this->channelPartner?->display_label ?? $this->broker_name;
        return $label !== null && $label !== '' ? $label : null;
    }
    public function isTerminal(): bool
    {
        return in_array($this->stage, config('crm.terminal_stages'));
    }
/* ---------------- scopes ---------------- */
    /**
     * The only thing protecting lead privacy.
     * Every lead query in the application must call this.
     *
     * `see_all_leads` rather than a role string, and it is the one permission
     * that changes what a query returns rather than what a button does. An
     * admin resolves it true from config('crm.permission_defaults'), so an
     * admin still sees everything without this method naming the role; a sales
     * manager given the toggle now sees the same rows, and everyone else is
     * scoped to what they own exactly as before.
     *
     * Because this is a data boundary and not a UI one, it is the check that
     * has to be right even when every screen above it is wrong.
     */
    public function scopeVisibleTo($query, User $user)
    {
        return $user->can_('see_all_leads')
            ? $query
            : $query->where('assigned_to', $user->id);
    }
    public function scopeOpen($query)
    {
        return $query->whereNotIn('stage', config('crm.terminal_stages'));
    }
}
