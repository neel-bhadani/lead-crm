<?php

namespace App\Http\Requests;

use App\Models\LeadSource;
use App\Support\CrmTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding a source, and editing one. The key is absent from the rules for
 * exactly the reason LeadStageRequest gives.
 */
class LeadSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        $source = $this->route('source');

        return [
            'label' => [
                'required', 'string', 'max:60',
                Rule::unique('lead_sources', 'label')->ignore($source?->id),
            ],

            /*
             | The two `source_defaults` columns. Both nullable — "no default"
             | is the normal answer and the one every seeded row carries.
             |
             | The stage must be an ACTIVE one, with no already-holds exception
             | like the lead forms have: this is not a record's own history, it
             | is an instruction about leads that do not exist yet, and pointing
             | new work at a retired stage is never what somebody means.
             */
            'default_stage_key' => [
                'nullable', 'string',
                Rule::in(CrmTaxonomy::activeStageKeys()),
            ],

            'default_owner_role' => [
                'nullable', 'string',
                Rule::in(config('crm.staff_roles')),
            ],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'label.required'         => 'Give the source a name.',
            'label.unique'           => 'A source with that name already exists.',
            'default_stage_key.in'   => 'That stage is not in use. Choose an active one.',
            'default_owner_role.in'  => 'That is not a role a lead can be filed under.',
        ];
    }

    public function slugKey(): string
    {
        $base = str($this->input('label'))->slug('_')->limit(50, '')->toString();
        $base = $base !== '' ? $base : 'source';
        $key  = $base;
        $n    = 1;

        while (LeadSource::where('key', $key)->exists()) {
            $key = $base . '_' . (++$n);
        }

        return $key;
    }
}
