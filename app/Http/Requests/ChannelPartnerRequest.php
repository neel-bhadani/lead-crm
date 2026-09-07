<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesChannelPartner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Editing a channel partner from the Channel Partners page.
 *
 * EDIT ONLY. There is no create path through this class any more: partners are
 * created from inside the Add lead modal, by whoever is logging the lead, and
 * the admin page manages what already exists. The route this serves is a PUT
 * with a bound {partner}, and the absence of a POST beside it is deliberate —
 * see routes/web.php.
 *
 * Every field, unlike QuickChannelPartnerRequest's four. This is the screen
 * where the contact person, the email and the address get filled in, at a desk,
 * after the lead that produced the row has been logged.
 *
 * The rules about what a partner IS — the hierarchy and the name that cannot
 * already be taken — are shared with the quick door through
 * ValidatesChannelPartner, so the two cannot disagree about the shape of a row
 * while disagreeing about who may write one.
 */
class ChannelPartnerRequest extends FormRequest
{
    use ValidatesChannelPartner;

    /**
     * The route is already behind `role:admin`. This is the second lock on the
     * same door, exactly as UserRequest is: a route added to that group later
     * without the middleware, or moved out of it by accident, still cannot
     * reach here.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return $this->partnerIdentityRules() + [
            'contact_person' => ['nullable', 'string', 'max:150'],
            'alt_phone'      => ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\-\s]{6,20}$/'],
            'email'          => ['nullable', 'email', 'max:150'],
            'address'        => ['nullable', 'string', 'max:255'],
            'is_active'      => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validatePartnerShape($validator, $this->route('partner'));
    }

    public function messages(): array
    {
        return $this->partnerMessages() + [
            'alt_phone.regex' => 'Enter a valid phone number.',
        ];
    }

    /**
     * The row's own columns, with the one field that depends on another
     * normalised here rather than in the controller.
     *
     * `parent_id` is forced to null for a firm. validatePartnerShape() has
     * already refused a submission that carried one, so this is not the guard —
     * it is what stops a stale value being saved in the case the guard cannot
     * see: a row edited from broker to firm, where the modal cleared the field
     * on screen and the type is the only thing that says so.
     */
    public function channelPartnerAttributes(): array
    {
        $data = $this->safe()->only([
            'name', 'type', 'parent_id', 'contact_person',
            'phone', 'alt_phone', 'email', 'address',
        ]);

        $data['parent_id'] = $data['type'] === 'firm' ? null : ($data['parent_id'] ?? null);
        $data['is_active'] = $this->boolean('is_active');

        return $data;
    }
}
