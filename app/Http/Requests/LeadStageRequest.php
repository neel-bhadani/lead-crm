<?php

namespace App\Http\Requests;

use App\Models\LeadStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding a stage, and editing one.
 *
 * THE KEY IS NOT IN THESE RULES ON UPDATE, and that absence is the lock. A key
 * that is not validated is not in validated(), so the controller cannot write
 * it however the form is tampered with — the same shape LeadRequest uses to
 * protect the legacy `broker_name` column. Re-keying a stage would leave every
 * `leads.stage` and every `todos.outcome_stage` holding a word that no longer
 * names anything, with no way back: the old string is gone from the only table
 * that could have translated it.
 *
 * On CREATE the key is not in the rules either. It is slugged from the label by
 * the controller, because a key the user types is a key the user gets wrong
 * once and lives with forever.
 */
class LeadStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // `role:admin` on the route group is the real refusal; this says the
        // same thing again so it survives somebody reorganising routes/web.php
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        $stage = $this->route('stage');

        return [
            'label' => [
                'required', 'string', 'max:60',
                /*
                 | Two stages called "Site visit" would be one column of the
                 | pipeline chart drawn twice, and a dropdown nobody can choose
                 | from correctly. The keys would differ, so the database would
                 | happily hold both.
                 */
                Rule::unique('lead_stages', 'label')->ignore($stage?->id),
            ],

            // from the fixed palette, never a free hex — see config('crm.stage_palette')
            'color' => ['required', 'string', Rule::in(config('crm.stage_palette'))],

            'is_terminal' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],

            /*
             | Which desk a new lead at this stage goes to. A staff role and
             | nothing else: an admin is not a desk, and a lead routed "to the
             | admins" would be on the first admin's list by accident.
             |
             | Absent keeps what the row has; empty takes the seeded default;
             | a terminal stage drops it whatever was sent — LeadStage::booted()
             | settles the last two. Putting a stage past the
             | handover on the telecaller desk is allowed; PipelineController
             | saves it and says what it means.
             */
            'owner_role' => ['sometimes', 'nullable', 'string', Rule::in(config('crm.staff_roles'))],
        ];
    }

    public function messages(): array
    {
        return [
            'label.required' => 'Give the stage a name.',
            'label.unique' => 'A stage with that name already exists.',
            'color.in' => 'Choose one of the offered colours.',
            'owner_role.in' => 'New leads can go to a telecaller or a salesperson.',
        ];
    }

    /**
     * The label, slugged, unique, and only ever used on create.
     *
     * Underscores rather than hyphens because that is what every existing key
     * is — `site_visit_done`, not `site-visit-done` — and a vocabulary written
     * two ways is one somebody eventually types wrong in a seeder.
     */
    public function slugKey(): string
    {
        $base = str($this->input('label'))->slug('_')->limit(50, '')->toString();

        // a label of nothing but punctuation still has to produce a key
        $base = $base !== '' ? $base : 'stage';
        $key = $base;
        $n = 1;

        while (LeadStage::where('key', $key)->exists()) {
            $key = $base.'_'.(++$n);
        }

        return $key;
    }
}
