<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use App\Support\CrmTaxonomy;

/**
 * The three fields that book the next follow-up, shared by the two forms that
 * ask for them: the lead form and the log-call modal.
 *
 * They are one rule in one place because they are one decision. Nothing
 * generates a follow-up date any more, so these fields are the only thing
 * standing between an open lead and having no task at all — and a copy of the
 * rules that drifted in either form would let that lead through.
 *
 * The host request decides *when* they are demanded by implementing
 * needsFollowUp(); the terminal stages are the case where the answer is no,
 * because a booked or lost lead should end up with no pending to-do.
 */
trait SchedulesFollowUp
{
    /** True when this request must carry a next follow-up. */
    abstract protected function needsFollowUp(): bool;

    /** @return array<string, array<mixed>> */
    protected function followUpRules(): array
    {
        $required = Rule::requiredIf(fn () => $this->needsFollowUp());

        return [
            'follow_up_type' => [
                $required, 'nullable',
                Rule::in(array_keys(config('crm.todo_types'))),
            ],

            /*
             | `after:now` rather than `after:today`. The user is picking a
             | moment, not a day, and a task due at 10 AM typed in at 3 PM is
             | overdue before it is saved. Read in Asia/Kolkata, which is what
             | the datetime-local input on the form is showing them.
             */
            'follow_up_at' => [$required, 'nullable', 'date', 'after:now'],

            'follow_up_remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    protected function followUpMessages(): array
    {
        return [
            'follow_up_type.required' => 'Choose what the next follow-up is.',
            'follow_up_at.required'   => 'Pick the date and time of the next follow-up.',
            'follow_up_at.after'      => 'The next follow-up must be in the future.',
        ];
    }

    /** The stage this request is moving the lead to closes it. */
    protected function stageIsTerminal(): bool
    {
        return CrmTaxonomy::isTerminal($this->input('stage'));
    }
}
