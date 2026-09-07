<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Facebook settings form.
 *
 * Two things here are not ordinary validation. The token fields are `nullable`
 * even though the integration cannot work without them, because the form never
 * sends the stored values back — an empty token field means "leave it alone",
 * not "clear it", and only `changedSecrets()` below can tell those apart. And
 * `is_active` is checked against readiness in withValidator(), because
 * switching on an integration with no token would leave the card saying
 * Connected while every delivery failed.
 */
class IntegrationSettingsRequest extends FormRequest
{
    /**
     * Route-level `role:admin` has already run. Repeating it here would be a
     * second answer to a question already settled, and the two could drift.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // never required: blank means "keep the stored one"
            'page_access_token'  => ['nullable', 'string', 'max:1000'],
            'app_secret'         => ['nullable', 'string', 'max:255'],

            'page_id'            => ['nullable', 'string', 'max:100'],
            'default_project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('is_active', true)],

            /*
             | Anyone who can hold a lead. Admin included — a small office may
             | genuinely want new Facebook leads landing on the owner's own list
             | — but inactive users are excluded, because a lead assigned to a
             | switched-off account is a lead nobody will ever see.
             */
            'assign_to_user_id'  => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],

            'is_active'          => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'default_project_id.required' => 'Choose the project these leads belong to.',
            'default_project_id.exists'   => 'Choose an active project.',
            'assign_to_user_id.required'  => 'Choose who these leads should be assigned to.',
            'assign_to_user_id.exists'    => 'Choose an active member of staff.',
        ];
    }

    /**
     * The secrets the admin actually typed, ready to merge.
     *
     * A field left blank is dropped rather than saved, which is what makes the
     * masked display safe: an admin editing the page ID does not have to
     * re-paste a 200-character token to avoid wiping it.
     *
     * @return array<string, string>
     */
    public function changedSecrets(): array
    {
        return collect(Integration::SECRET_KEYS)
            ->mapWithKeys(fn (string $key) => [$key => trim((string) $this->input($key))])
            ->filter(fn (string $value) => $value !== '')
            ->all();
    }

    /**
     * Switching it on is a claim that it will work, so it is checked.
     *
     * The tokens may be arriving in this same request, so readiness is judged
     * against what the row WILL hold once saved, not against what it holds now.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->boolean('is_active')) {
                return;
            }

            $integration = Integration::forProvider($this->route('provider'));
            $integration->mergeSettings($this->changedSecrets() + [
                'default_project_id' => $this->input('default_project_id'),
                'assign_to_user_id'  => $this->input('assign_to_user_id'),
            ]);

            if (! $integration->isConfigured()) {
                $validator->errors()->add(
                    'is_active',
                    'Add the page access token and app secret before switching this on.'
                );
            }
        });
    }
}
