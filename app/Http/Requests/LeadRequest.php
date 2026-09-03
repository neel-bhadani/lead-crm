<?php

namespace App\Http\Requests;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Used for both store and update.
 * Route model binding gives us {lead} on update, which we ignore in the unique rule.
 */
class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()->role, ['admin', 'salesperson'], true);
    }

    public function rules(): array
    {
        $leadId = $this->route('lead')?->id;

        return [
            'first_name'    => ['required', 'string', 'max:100'],
            'middle_name'   => ['nullable', 'string', 'max:100'],
            'last_name'     => ['required', 'string', 'max:100'],

            /*
             | The index on the table is a plain unique (mobile_number,
             | project_id) — it knows nothing about soft deletes. A rule that
             | skipped trashed rows therefore passed a number the index went
             | on to reject, and the insert came back as a 500 instead of a
             | message. This looks at exactly the rows the index looks at, and
             | says which kind of lead is holding the number.
             */
            'mobile_number' => [
                'required', 'digits:10',
                function (string $attribute, mixed $value, \Closure $fail) use ($leadId) {
                    $clash = Lead::withTrashed()
                        ->where('mobile_number', $value)
                        ->where('project_id', $this->project_id)
                        ->when($leadId, fn ($q, $id) => $q->where('id', '!=', $id))
                        ->first();

                    if (! $clash) {
                        return;
                    }

                    $fail($clash->trashed()
                        ? 'This number belongs to a deleted lead on this project. Restore that lead instead of adding it again.'
                        : 'This number already exists for this project.');
                },
            ],

            'email'         => ['nullable', 'email', 'max:150'],
            'project_id'    => ['required', 'exists:projects,id'],

            'source'        => ['required', Rule::in(array_keys(config('crm.sources')))],
            'broker_name'   => ['nullable', 'required_if:source,broker', 'string', 'max:150'],

            'stage'         => ['required', Rule::in(array_keys(config('crm.stages')))],
            'reason'        => [
                'nullable', 'required_if:stage,lost',
                Rule::in(array_keys(config('crm.lost_reasons'))),
            ],

            'requirement'   => ['nullable', 'string', 'max:100'],
            'booked_unit'   => ['nullable', 'required_if:stage,booking_done', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile_number.digits'    => 'Enter a 10 digit mobile number.',
            'broker_name.required_if' => 'Broker name is required when the source is broker.',
            'reason.required_if'      => 'Select why this lead was lost.',
        ];
    }
}
