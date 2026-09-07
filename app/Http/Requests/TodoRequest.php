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

            /*
             | `after:now`, and it holds on the reschedule route too, which is
             | the case worth being explicit about: a task being rescheduled is
             | overdue more often than not, so the row this request is editing
             | already carries a datetime in the past. Nothing here reads that
             | row. A rule sees the value that was submitted and nothing else,
             | so the stale one cannot fail the form — only the new one is
             | asked to be in the future, which is the whole point of opening
             | the form. TodoFormModal prefills a future default rather than
             | echoing the past date back, so the two agree.
             |
             | Read in Asia/Kolkata (config/app.php), the same clock the
             | datetime-local input on the form is showing.
             */
            'scheduled_at' => ['required', 'date', 'after:now'],

            'remarks'      => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'scheduled_at.required' => 'Pick the date and time of the follow-up.',
            'scheduled_at.after'    => 'The follow-up must be in the future.',
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
                $v->errors()->add('lead_id', 'This lead already has a follow-up scheduled.');
            }
        });
    }
}
