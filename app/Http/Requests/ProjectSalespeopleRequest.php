<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The salespeople ticked on a project's detail page. Admin only, said again
 * here for the same reason ProjectRequest says it.
 *
 * Only an active salesperson can be ticked — the only people the page lists.
 * Anybody else in the list is refused rather than dropped, so a stale page or
 * a tampered request cannot put a telecaller on a project's round robin.
 */
class ProjectSalespeopleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'salesperson_ids' => ['present', 'array'],
            'salesperson_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')
                    ->where('role', 'salesperson')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'salesperson_ids.*.exists' => 'Only an active salesperson can be assigned to a project.',
        ];
    }
}
