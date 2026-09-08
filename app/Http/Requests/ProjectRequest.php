<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding and editing a project. Admin only.
 *
 * The route group already says `role:admin`; authorize() says it again, and
 * that repetition is the lock that survives somebody reorganising
 * routes/web.php.
 *
 * The only interesting rule is the unique name, and specifically what it does
 * NOT count. A soft-deleted project is one no lead ever pointed at — that is
 * the only kind the Projects screen will delete — so its name is genuinely
 * free, and holding it hostage would mean an admin who deleted a typo'd
 * "Skyline Residancy" could never create "Skyline Residency" without a trip to
 * the database. The `whereNull('deleted_at')` is what releases it.
 */
class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('projects', 'name')
                    // the row being edited is not a clash with itself
                    ->ignore($this->route('project'))
                    // a deleted project's name is free again; see the class note
                    ->whereNull('deleted_at'),
            ],
            'location'    => ['nullable', 'string', 'max:160'],
            'type'        => ['required', Rule::in(array_keys(config('crm.project_types')))],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active'   => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Give the project a name.',
            'name.unique'   => 'There is already a project with that name.',
            'type.required' => 'Choose whether this is residential or commercial.',
        ];
    }

    /**
     * What actually gets stored.
     *
     * `created_by` is not here: it is stamped once, by the controller, on
     * store. Taking it from the request would let an edit reassign authorship,
     * and letting an update touch it at all would rewrite who added a project
     * every time somebody fixed its location.
     *
     * @return array<string, mixed>
     */
    public function projectAttributes(): array
    {
        return [
            'name'        => trim($this->input('name')),
            'location'    => $this->filled('location') ? trim($this->input('location')) : null,
            'type'        => $this->input('type'),
            'description' => $this->filled('description') ? trim($this->input('description')) : null,
            'is_active'   => $this->boolean('is_active'),
        ];
    }
}
