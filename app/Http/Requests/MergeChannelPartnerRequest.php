<?php

namespace App\Http\Requests;

use App\Models\ChannelPartner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Merging one channel partner into another — the cleanup for the duplicates
 * that inline creation will produce.
 *
 * It will produce them. Three people logging broker leads on the same afternoon
 * will enter "Shreeji", "Shreeji Realty" and "Shreeji Realty Pvt Ltd", the
 * typeahead and the near-match warning will catch most of it and not all of it,
 * and the broker report is only worth reading if somebody can put the strays
 * back together afterwards. That is this.
 *
 * The direction matters and the form is explicit about it: the SOURCE is the
 * row being merged away, and it is the one that ends up soft deleted. The
 * source is the bound {partner}; the target is what this request names.
 *
 * Admin only — the route sits inside the `role:admin` group and authorize()
 * says it again. Reassigning every lead that came through a broker is the one
 * operation on this table that rewrites attribution in bulk, which is the
 * number the whole feature exists to produce.
 */
class MergeChannelPartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'target_id' => [
                'required',
                Rule::exists('channel_partners', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $source = $this->route('partner');
            $target = ChannelPartner::find($this->input('target_id'));

            if (! $source || ! $target) {
                return;   // the exists rule has already said so
            }

            if ($source->id === $target->id) {
                $v->errors()->add('target_id', 'Choose a different partner to merge into.');

                return;
            }

            /*
             | Merging into a switched-off partner would move every lead onto a
             | row the lead form will not offer, so the next lead from that
             | broker cannot be attributed to the row all the others just moved
             | to. Reactivate it first, which is one click on this same page.
             */
            if (! $target->is_active) {
                $v->errors()->add('target_id',
                    'That partner is switched off. Reactivate it first, or merge into a different one.');

                return;
            }

            /*
             | The one shape that would break the hierarchy.
             |
             | A firm's brokers move with it — see ChannelPartnerController::merge()
             | — and if the target is a broker they would land under a broker,
             | which is the two-level nesting this table forbids. The admin is
             | told to move the brokers first rather than being handed a merge
             | that silently orphaned them.
             */
            $brokers = $source->brokers()->count();

            if ($brokers > 0 && ! $target->isFirm()) {
                $v->errors()->add('target_id', "\"{$source->name}\" has {$brokers} broker"
                    . ($brokers === 1 ? '' : 's') . ' filed under it, so it can only be merged into a firm.'
                    . ' Move them to another firm first, or choose a firm to merge into.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'target_id.required' => 'Choose the partner to merge into.',
            'target_id.exists'   => 'That partner no longer exists.',
        ];
    }
}
