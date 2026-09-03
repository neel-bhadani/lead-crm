<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteTodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $todo = $this->route('todo');

        return $this->user()->isAdmin() || $todo->assigned_to === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'remarks'      => ['required', 'string', 'max:1000'],
            'stage'        => ['required', Rule::in(array_keys(config('crm.stages')))],

            'visit_at'     => ['nullable', 'required_if:stage,site_visit_scheduled', 'date', 'after:now'],

            'reason'       => [
                'nullable',
                'required_if:stage,lost',
                Rule::in(array_keys(config('crm.lost_reasons'))),
            ],

            'booked_unit'  => ['nullable', 'required_if:stage,booking_done', 'string', 'max:50'],
            'booking_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'remarks.required'      => 'Write a short remark about the call.',
            'visit_at.required_if'  => 'Pick the site visit date and time.',
            'visit_at.after'        => 'The site visit must be in the future.',
            'reason.required_if'    => 'Select why this lead was lost.',
            'booked_unit.required_if' => 'Enter the unit that was booked.',
        ];
    }
}
