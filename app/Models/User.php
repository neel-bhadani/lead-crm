<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;
    protected $fillable = [
        'first_name',
        'last_name',
        'name',
        'email',
        'mobile_number',
        'password',
        'role',
        'is_active',
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
            'password' => 'hashed',
            'is_active' => 'boolean',
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
    /* ---------------- relationships ---------------- */
    public function leads()
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }
    public function todos()
    {
        return $this->hasMany(Todo::class, 'assigned_to');
    }
    /* ---------------- accessors ---------------- */
    public function getDisplayNameAttribute(): string
    {
        $full = trim("{$this->first_name} {$this->last_name}");
        return $full !== '' ? $full : (string) $this->name;
    }
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
