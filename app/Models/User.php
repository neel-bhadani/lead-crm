<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    /*
     | `approval_status` is deliberately not here. Two code paths write it —
     | SignupController (pending) and UserController::approve()/reject() — and
     | both use forceFill(), so no array of request input can ever carry it in.
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'name',
        'email',
        'mobile_number',
        'password',
        'role',
        'is_active',
        'permissions',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = ['display_name'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // the only thing that hashes a password in this application; a
            // controller that called Hash::make() itself would double-hash
            'password' => 'hashed',
            'is_active' => 'boolean',
            'permissions' => 'array',
        ];
    }

    /**
     * `leads.assigned_role` is the role of the person in `assigned_to`, and a
     * role change is the one write that can make them disagree without
     * touching a lead (QA-REPORT-2 MIN-2).
     *
     * On the model rather than in UserController, so tinker and a seeder keep
     * the column in step too; it runs inside whatever transaction saved the
     * user. Every lead they hold — closed and deleted ones too, so a restore
     * cannot bring a mismatch back. The leads themselves do not move: which
     * desk they SHOULD be on is the admin's call, and the Users screen already
     * warns before a demotion leaves somebody holding advanced leads.
     *
     * toBase(), because relabelling a lead is not activity on it and must not
     * touch its `updated_at`.
     */
    protected static function booted(): void
    {
        static::updated(function (User $user) {
            if ($user->wasChanged('role')) {
                Lead::withTrashed()
                    ->where('assigned_to', $user->id)
                    ->toBase()
                    ->update(['assigned_role' => $user->role]);
            }
        });
    }

    /* ---------------- role helpers ---------------- */

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isTelecaller(): bool
    {
        return $this->role === 'telecaller';
    }

    public function isSalesperson(): bool
    {
        return $this->role === 'salesperson';
    }

    /* ---------------- approval ---------------- */

    /** Signed up and not yet seen by an admin. */
    public function isPending(): bool
    {
        return $this->approval_status === 'pending';
    }

    public function isRejected(): bool
    {
        return $this->approval_status === 'rejected';
    }

    /**
     * The alert every admin gets when this account signs up, and the key that
     * clears it from all of their bells once one of them has answered.
     * Per-account, so two sign-ups on one day do not deduplicate into one.
     */
    public function approvalAlertType(): string
    {
        return 'account_pending.'.$this->id;
    }

    /* ---------------- permissions ---------------- */

    /**
     * Can this user do `$key`?
     *
     * The JSON column first, the role's default second. That order is the whole
     * design: a permission left unset is not "false", it is "whatever this role
     * has always done" — which is what lets the column be added to a live table
     * without changing how a single existing user behaves.
     *
     * Named `can_` rather than `can` because Authenticatable already has one:
     * `can()` is the Gate, it takes an ability and a model, and quietly
     * shadowing it would route every policy check in the framework through
     * this method. The two are meant to sit side by side — LeadPolicy is where
     * they meet.
     *
     * An unknown key is false. A typo should close a door, not open one.
     */
    public function can_(string $key): bool
    {
        $explicit = $this->permissions[$key] ?? null;

        if ($explicit !== null) {
            return (bool) $explicit;
        }

        return (bool) (config("crm.permission_defaults.{$this->role}.{$key}") ?? false);
    }

    /**
     * Every permission resolved to the boolean it currently has, explicit or
     * inherited. This is what the management screen renders — the admin has to
     * see the value that is in force, not a blank where a default is doing the
     * work.
     *
     * @return array<string, bool>
     */
    public function effectivePermissions(): array
    {
        return collect(array_keys(config('crm.permissions')))
            ->mapWithKeys(fn (string $key) => [$key => $this->can_($key)])
            ->all();
    }

    /* ---------------- relationships ---------------- */

    public function leads()
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function todos()
    {
        return $this->hasMany(Todo::class, 'assigned_to');
    }

    /** The projects this salesperson handles — see Project::salespeople(). */
    public function projects()
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    /** Only what a handover has to move: the work that is still live. */
    public function openLeads()
    {
        return $this->leads()->open();
    }

    /**
     * The bell. Addressed to this user by name — an admin does not read
     * everybody else's alerts, they read the ones raised to them.
     */
    public function alerts()
    {
        return $this->hasMany(Alert::class);
    }

    public function pendingTodos()
    {
        return $this->todos()->where('status', 'pending');
    }

    /* ---------------- accessors ---------------- */

    public function getDisplayNameAttribute(): string
    {
        $full = implode(' ', array_map(
            fn ($part) => trim((string) $part),
            array_filter(
                [$this->first_name, $this->last_name],
                fn ($part) => trim((string) $part) !== '',
            ),
        ));

        return $full !== '' ? $full : (string) $this->name;
    }

    /* ---------------- scopes ---------------- */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Accounts an admin agreed to, whether or not they are switched on today.
     * A sign-up still waiting, or one that was turned down, never worked here
     * and has no business in a staff dropdown.
     */
    public function scopeApproved($query)
    {
        return $query->where('approval_status', 'approved');
    }

    /**
     * The people a departing user's work can be handed to: active, still here,
     * and not the user being handed away from.
     */
    public function scopeAssignable($query)
    {
        return $query->active()->whereIn('role', ['admin', 'telecaller', 'salesperson']);
    }
}
