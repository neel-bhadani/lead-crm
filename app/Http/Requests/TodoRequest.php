<?php

namespace App\Http\Requests;

use App\Models\Todo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lead_id'      => ['required', 'exists:leads,id'],
            'type'         => ['required', Rule::in(array_keys(config('crm.todo_types')))],
            'scheduled_at' => ['required', 'date'],
            'remarks'      => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * A lead may hold only one pending task. Without this rule the
     * to-do charts double-count and staff see the same lead twice.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $editingId = $this->route('todo')?->id;

            $exists = Todo::where('lead_id', $this->lead_id)
                ->where('status', 'pending')
                ->when($editingId, fn ($q) => $q->where('id', '!=', $editingId))
                ->exists();

            if ($exists) {
                $v->errors()->add('lead_id', 'This lead already has a pending task.');
            }
        });
    }
}
