<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A WhatsApp message written once and sent many times.
 *
 * The body carries named placeholders. Rendering it against a lead is
 * App\Services\WhatsApp\TemplateRenderer — this model holds the text and the
 * numbering Meta will want, and nothing else.
 */
class MessageTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'placeholder_map' => 'array',
        'is_active'       => 'boolean',
    ];

    public function messages()
    {
        return $this->hasMany(MessageLog::class, 'template_id');
    }

    /**
     * What this template costs to send, in words rather than rupees.
     *
     * Deliberately relative and deliberately vague. Meta's per-conversation
     * pricing moves by country and by month, so a number printed here would be
     * wrong within a quarter; the ratio — utility is roughly an eighth of
     * marketing — is what actually changes the admin's mind, and it has been
     * stable for years.
     */
    public function costNote(): string
    {
        return (string) config("automation.whatsapp.categories.{$this->category}.cost_note", '');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
