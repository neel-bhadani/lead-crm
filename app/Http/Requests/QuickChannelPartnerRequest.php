<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesChannelPartner;
use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Creating a channel partner from inside the Add lead modal — the only way a
 * partner is created at all.
 *
 * FOUR FIELDS, and the shortness is the feature. Type, name, parent firm and a
 * phone number is what somebody can answer while a client is still on the line;
 * asking for a contact person, an email and a postal address in the middle of
 * logging a lead gets you an abandoned form or six fields of rubbish, and the
 * rubbish is worse because it looks like data. The rest is filled in later from
 * the Channel Partners page, by an admin, at a desk.
 *
 * THE DOOR IS THE LEAD DOOR. Not `role:admin` — a salesperson logging a broker
 * lead has to be able to name the broker, and requiring an admin for that would
 * either stop the lead being logged or get the broker typed into the wrong
 * field. So the test is exactly the one that decides whether this user could
 * have opened the form this request comes from: may they create a lead?
 *
 * That is `add_leads`, resolved through LeadPolicy, which a telecaller does not
 * have by default. A telecaller therefore never sees the Add lead button, never
 * sees the inline form, and is refused here as well if they post to it directly
 * — three answers to one question, and this one is the answer that counts. An
 * admin who grants a telecaller `add_leads` gets all three moving together,
 * which is the point of the permission being the test rather than the role.
 */
class QuickChannelPartnerRequest extends FormRequest
{
    use ValidatesChannelPartner;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Lead::class) ?? false;
    }

    public function rules(): array
    {
        return $this->partnerIdentityRules();
    }

    public function withValidator(Validator $validator): void
    {
        // no bound partner: this door only ever creates
        $this->validatePartnerShape($validator, null);
    }

    public function messages(): array
    {
        return $this->partnerMessages();
    }

    /**
     * The row, as the four fields describe it.
     *
     * `is_active` is true and is not taken from the request. A partner created
     * to attribute the lead being typed is a partner in use — offering an
     * "active" toggle on a four-field form would be a fifth field whose only
     * useful answer is yes. Switching one off is a deliberate act, and it
     * happens on the Channel Partners page.
     *
     * `parent_id` is forced to null for a firm. validatePartnerShape() has
     * already refused a submission that carried one, so this is not the guard
     * — it is what stops a stale value being saved in the case the guard cannot
     * see: the type changed on screen and the hidden field kept its last value.
     */
    public function channelPartnerAttributes(): array
    {
        $data = $this->safe()->only(['name', 'type', 'parent_id', 'phone']);

        $data['parent_id'] = $data['type'] === 'firm' ? null : ($data['parent_id'] ?? null);
        $data['is_active'] = true;

        return $data;
    }
}
