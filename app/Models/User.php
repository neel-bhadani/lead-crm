<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

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
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'permissions'       => 'array',
        ];
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
     * The people a departing user's work can be handed to: active, still here,
     * and not the user being handed away from.
     */
    public function scopeAssignable($query)
    {
        return $query->active()->whereIn('role', ['admin', 'telecaller', 'salesperson']);
    }
}
