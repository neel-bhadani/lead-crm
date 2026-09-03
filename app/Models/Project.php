<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
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
}
