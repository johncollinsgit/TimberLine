<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAgreement;
use Illuminate\Support\Facades\Validator;

/** Changes delivery details or scent identity while retaining the original member's unit pricing and source IDs. */
class SubscriptionAmendmentProposal
{
    public function review(SubscriptionBillingAgreement $row, array $input): array
    {
        SubscriptionBillingAuthorization::assertCurrent($row);
        app(SubscriptionReplacementAccess::class)->member($row);
        $data = Validator::make(['change' => $input], [
            'change' => ['required', 'array:shipping_address,swaps,fixed_shipping_reviewed,policy_reference'],
            'change.fixed_shipping_reviewed' => ['required', 'accepted'],
            'change.policy_reference' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.:\/-]{1,190}\z/'],
            'change.shipping_address' => ['sometimes', 'array:firstName,lastName,company,address1,address2,city,provinceCode,countryCode,zip,phone'],
            'change.shipping_address.firstName' => ['required_with:change.shipping_address', 'string', 'max:100'],
            'change.shipping_address.lastName' => ['required_with:change.shipping_address', 'string', 'max:100'],
            'change.shipping_address.address1' => ['required_with:change.shipping_address', 'string', 'max:255'],
            'change.shipping_address.address2' => ['nullable', 'string', 'max:255'],
            'change.shipping_address.city' => ['required_with:change.shipping_address', 'string', 'max:100'],
            'change.shipping_address.provinceCode' => ['required_with:change.shipping_address', 'regex:/\A[A-Z]{2}\z/'],
            'change.shipping_address.countryCode' => ['required_with:change.shipping_address', 'in:US'],
            'change.shipping_address.zip' => ['required_with:change.shipping_address', 'regex:/\A[0-9]{5}(?:-[0-9]{4})?\z/'],
            'change.shipping_address.company' => ['nullable', 'string', 'max:255'], 'change.shipping_address.phone' => ['nullable', 'string', 'max:30'],
            'change.swaps' => ['sometimes', 'array', 'min:1', 'max:20'],
            'change.swaps.*' => ['array:subscription_id,variant_id'],
            'change.swaps.*.subscription_id' => ['required', 'string', 'distinct', 'regex:/\A[1-9][0-9]*\z/'],
            'change.swaps.*.variant_id' => ['required', 'string', 'regex:/\A[1-9][0-9]*\z/'],
        ])->validate()['change'];
        $terms = $row->terms;
        if (isset($data['shipping_address'])) {
            $terms['shipping_address'] = $data['shipping_address'];
        }
        foreach ($data['swaps'] ?? [] as $swap) {
            abort_unless(app(SubscriptionMembershipReviewService::class)->program($row) === 'candle_club', 409, 'Review Candle Club membership before selecting another club scent.');
            $index = array_search($swap['subscription_id'], array_column($terms['lines'], 'subscription_id'), true);
            abort_unless($index !== false, 409, 'Choose an original line from this subscription.');
            $terms['lines'][$index]['variant_id'] = $swap['variant_id'];
        }
        abort_if(SubscriptionEvidence::canonical($terms) === SubscriptionEvidence::canonical($row->terms), 422, 'Choose a different delivery address or scent.');

        return ['terms' => $terms, 'policy_review' => ['fixed_shipping_reviewed' => true, 'reference' => $data['policy_reference']],
            'subject' => ['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'agreement_public_id' => $row->public_id,
                'member_id' => $row->member_id, 'revision' => $row->revision, 'current_terms_hash' => $row->terms_hash,
                'original_terms_hash' => SubscriptionBillingAuthorization::original($row)['terms_hash']]];
    }
}
