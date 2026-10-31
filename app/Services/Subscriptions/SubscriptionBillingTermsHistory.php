<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use Illuminate\Support\Arr;

/** Original authorization plus an unbroken, signed history of separately accepted future terms. */
class SubscriptionBillingTermsHistory
{
    public static function assertCurrent(SubscriptionBillingAgreement $row, array $original, bool $currentRead = false): void
    {
        $terms = $original['terms'];
        $hash = $original['terms_hash'];
        $revision = 0;
        foreach (SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
            ->where('kind', 'terms_amended')->orderBy('id')->when($currentRead, fn ($q) => $q->lockForUpdate())->get() as $action) {
            $request = $action->request;
            $after = $action->after_state;
            $proof = $after['authorization_hash'] ?? null;
            unset($after['authorization_hash']);
            abort_unless($row->processor === 'braintree' && $action->actor_type === 'user' && ctype_digit($action->actor_reference)
                && $action->expected_revision >= 1 && $action->expected_revision >= $revision && ($after['revision'] ?? null) === $action->expected_revision + 1
                && ($request['version'] ?? null) === 1 && ($request['original_terms_hash'] ?? null) === $original['terms_hash']
                && ($request['agreement_public_id'] ?? null) === $row->public_id && ($request['member_id'] ?? null) === $row->member_id
                && ($request['accepted_by'] ?? null) === (int) $action->actor_reference
                && ($request['approval']['quoted_total_reviewed'] ?? null) === true
                && ($request['approval']['continuing_authorization_reviewed'] ?? null) === true
                && is_string($request['approval']['reference'] ?? null)
                && is_array($after['terms'] ?? null) && is_string($after['terms_hash'] ?? null)
                && SubscriptionEvidence::canonical($action->before_state) === SubscriptionEvidence::canonical(['terms' => $terms, 'terms_hash' => $hash])
                && is_string($proof) && hash_equals(self::seal($action), $proof)
                && hash_equals(self::termsHash($row, $after['terms'], $action->request_uuid), $after['terms_hash']),
                409, 'The accepted future terms history no longer matches its original review.');
            self::allowed($original['terms'], $after['terms']);
            $terms = $after['terms'];
            $hash = $after['terms_hash'];
            $revision = $after['revision'];
        }
        abort_unless($row->revision >= $revision && hash_equals($hash, $row->terms_hash)
            && SubscriptionEvidence::canonical($terms) === SubscriptionEvidence::canonical($row->terms),
            409, 'Current billing terms require a separately reviewed amendment before collection or contract adoption.');
    }

    public static function termsHash(SubscriptionBillingAgreement $row, array $terms, string $uuid): string
    {
        return SubscriptionEvidence::digest(SubscriptionEvidence::canonical(['version' => 1, 'agreement' => $row->public_id,
            'original_terms_hash' => SubscriptionBillingAuthorization::original($row)['terms_hash'],
            'binding' => $row->method_binding, 'amendment_uuid' => $uuid, 'terms' => $terms]));
    }

    public static function seal(SubscriptionBillingAction $action): string
    {
        $after = $action->after_state;
        unset($after['authorization_hash']);

        return SubscriptionEvidence::digest(SubscriptionEvidence::canonical(['version' => 1, 'tenant_id' => $action->tenant_id,
            'agreement_id' => $action->agreement_id, 'request_uuid' => $action->request_uuid, 'kind' => $action->kind,
            'actor_type' => $action->actor_type, 'actor_reference' => $action->actor_reference, 'expected_revision' => $action->expected_revision,
            'request' => $action->request, 'before_state' => $action->before_state, 'after_state' => $after]));
    }

    public static function allowed(array $original, array $terms): void
    {
        $mutable = ['shipping_address', 'lines', 'amount_cents', 'tax_cents', 'tax_lines'];
        abort_unless(SubscriptionEvidence::canonical(Arr::except($original, $mutable)) === SubscriptionEvidence::canonical(Arr::except($terms, $mutable))
            && is_array($terms['lines'] ?? null) && count($original['lines']) === count($terms['lines']), 409, 'An amendment cannot replace original customer, pricing, shipping or billing identity.');
        foreach ($original['lines'] as $index => $line) {
            $current = $terms['lines'][$index];
            abort_unless(SubscriptionEvidence::canonical(Arr::except($line, ['variant_id', 'label'])) === SubscriptionEvidence::canonical(Arr::except($current, ['variant_id', 'label']))
                && preg_match('/\A[1-9][0-9]*\z/', (string) ($current['variant_id'] ?? ''))
                && is_string($current['label'] ?? null) && strlen($current['label']) <= 255, 409, 'Original membership unit pricing, quantities and source lines must remain unchanged.');
        }
        app(SubscriptionAmendmentProposal::class)->normalize(['shipping_address' => $terms['shipping_address'] ?? [],
            'fixed_shipping_reviewed' => true, 'policy_reference' => 'retained-amendment']);
        abort_unless(is_int($terms['tax_cents'] ?? null) && $terms['tax_cents'] >= 0 && is_array($terms['tax_lines'] ?? null)
            && count($terms['tax_lines']) <= 25 && is_int($terms['amount_cents'] ?? null) && $terms['amount_cents'] > 0
            && $terms['amount_cents'] <= 10000000 && $terms['amount_cents'] === $terms['subtotal_cents'] + $terms['shipping_cents'] + $terms['tax_cents'], 409, 'Accepted tax and total evidence is incomplete.');
        foreach ($terms['tax_lines'] as $tax) {
            abort_unless(is_string($tax['title'] ?? null) && strlen($tax['title']) <= 255 && is_int($tax['price_cents'] ?? null) && $tax['price_cents'] >= 0
                && (is_int($tax['rate'] ?? null) || is_float($tax['rate'] ?? null)) && $tax['rate'] >= 0 && $tax['rate'] <= 1, 409, 'Retain the accepted tax breakdown.');
        }
        abort_unless(array_sum(array_column($terms['tax_lines'], 'price_cents')) === $terms['tax_cents'], 409, 'Accepted tax lines and total differ.');
    }
}
