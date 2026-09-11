<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\SchedulesFollowUp;
use App\Http\Requests\Concerns\ValidatesTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteTodoRequest extends FormRequest
{
    use SchedulesFollowUp;
    use ValidatesTaxonomy;

    public function authorize(): bool
    {
        $todo = $this->route('todo');

        return $this->user()->isAdmin() || $todo->assigned_to === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            /*
             | Two remarks fields on this form, and they are not the same note.
             | This one is the call that just happened and is stamped onto the
             | to-do being closed, which is what the Completed tab and the whole
             | history read. `follow_up_remarks` is a note for the task being
             | booked and belongs to a call nobody has made yet.
             */
            'remarks'      => ['required', 'string', 'max:1000'],
            /*
             | Active stages, plus the one the lead is standing in — a call
             | logged against a lead in a stage that has since been retired must
             | still be closeable, including by leaving the stage where it is.
             */
            'stage'        => ['required', $this->activeStageRule($this->route('todo')?->lead?->stage)],

            'reason'       => [
                'nullable',
                'required_if:stage,lost',
                Rule::in(array_keys(config('crm.lost_reasons'))),
            ],

            'booked_unit'  => ['nullable', 'required_if:stage,booking_done', 'string', 'max:50'],
            'booking_date' => ['nullable', 'date'],
        ] + $this->followUpRules();
    }

    /**
     * Every call that leaves the lead open books the next one. Only booking or
     * losing it ends the chain, and those are the two stages where a pending
     * to-do must not exist at all.
     */
    protected function needsFollowUp(): bool
    {
        return ! $this->stageIsTerminal();
    }

    public function messages(): array
    {
        return [
            'remarks.required'        => 'Write a short remark about the call.',
            'reason.required_if'      => 'Select why this lead was lost.',
            'booked_unit.required_if' => 'Enter the unit that was booked.',
        ] + $this->followUpMessages();
    }
}
