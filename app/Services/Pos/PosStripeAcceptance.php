<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentJournal;

/** Owner-observed hardware evidence plus fresh original-account payment/refund/payout proof. */
final class PosStripeAcceptance
{
    public function __construct(private PosStore $store, private StripeTerminalClient $stripe, private PaymentJournal $journal) {}

    public function verify(object $sale, array $snapshot, array $evidence, int $owner, string $evidenceHash, bool $apply): array
    {
        $binding = MerchantBinding::restore($snapshot['binding']);
        $reader = $this->store->rows('readers', (int) $sale->tenant_id)->where('register_id', $sale->register_id)->firstOrFail();
        if ($binding->provider !== 'stripe_terminal' || $binding->environment !== 'live' || $sale->state !== 'succeeded' || $sale->stock_state !== 'committed'
            || ($evidence['sale_id'] ?? '') !== $sale->id || ($evidence['build'] ?? '') !== $snapshot['build'] || $snapshot['build'] !== config('pos_live.web_build')
            || ($evidence['reader_id'] ?? '') !== $reader->reader_id || ($evidence['physical_reader_observed'] ?? false) !== true
            || ($evidence['receipt_shared_or_printed'] ?? false) !== true || ($evidence['stock_and_refund_reviewed'] ?? false) !== true
            || ! is_array($evidence['payout_ids'] ?? null) || count($evidence['payout_ids']) < 1 || count($evidence['payout_ids']) > 5) {
            throw new PosException('stripe_physical_acceptance_incomplete');
        }
        $connection = $this->store->rows('connections', (int) $sale->tenant_id)->where('id', $snapshot['connection_id'])->firstOrFail();
        $this->stripe->verify($connection, false);
        $this->stripe->reader($connection, $reader->reader_id);
        $provider = app(PosProviders::class)->get('stripe_terminal');
        $core = new PaymentCore($this->journal, app(PosProviders::class)->all(), new PosRecoveryAccess($this->store));
        $payment = $core->recover($binding, $sale->attempt_id);
        $provider->audit($payment);
        if ($payment['state'] !== 'succeeded') {
            throw new PosException('original_payment_unconfirmed');
        }
        $refunds = $this->store->db->table('payment_core_operations')->where('tenant_id', $sale->tenant_id)->where('original_id', $sale->attempt_id)->where('kind', 'refund')->get();
        $confirmed = false;
        foreach ($refunds as $refund) {
            $result = $core->recover($binding, $refund->id);
            $provider->audit($result);
            $confirmed = $confirmed || $result['state'] === 'succeeded';
        }
        if (! $confirmed || ! $this->store->rows('outbox', (int) $sale->tenant_id)->where('sale_id', $sale->id)->where('effect_key', 'receipt')->where('state', 'completed')->exists()
            || $this->store->rows('outbox', (int) $sale->tenant_id)->where('sale_id', $sale->id)->where('state', '!=', 'completed')->exists()) {
            throw new PosException('receipt_refund_and_sync_acceptance_required');
        }
        $intent = $this->stripe->request($connection, 'GET', '/v1/payment_intents/'.$payment['provider_id'], ['expand' => ['latest_charge']]);
        $charge = $intent['latest_charge']['id'] ?? null;
        $settled = false;
        foreach (array_unique($evidence['payout_ids']) as $id) {
            if (! is_string($id) || ! preg_match('/^po_[A-Za-z0-9]+$/D', $id)) {
                throw new PosException('payout_reference_invalid');
            }
            $payout = $this->stripe->request($connection, 'GET', '/v1/payouts/'.$id);
            if (($payout['id'] ?? '') !== $id || ($payout['status'] ?? '') !== 'paid' || ($payout['currency'] ?? '') !== 'usd' || ($payout['livemode'] ?? null) !== true) {
                throw new PosException('original_account_settlement_unconfirmed');
            }
            $cursor = null;
            for ($page = 0; $page < 5; $page++) {
                $entries = $this->stripe->request($connection, 'GET', '/v1/balance_transactions', array_filter(['payout' => $id, 'limit' => 100, 'starting_after' => $cursor]));
                if (! is_array($entries['data'] ?? null) || ! is_bool($entries['has_more'] ?? null)) {
                    throw new PosException('payout_search_incomplete');
                }
                foreach ($entries['data'] as $entry) {
                    if (($entry['source'] ?? '') === $charge && ($entry['amount'] ?? null) === $payment['amount_minor'] && ($entry['currency'] ?? '') === 'usd' && ($entry['status'] ?? '') === 'available') {
                        $settled = true;
                    }
                }
                if (! $entries['has_more']) {
                    $cursor = null;
                    break;
                }
                $cursor = $entries['data'][count($entries['data']) - 1]['id'] ?? null;
                if (! $cursor) {
                    throw new PosException('payout_search_incomplete');
                }
            }
            if ($cursor) {
                throw new PosException('payout_search_incomplete');
            }
        }
        if (! $settled) {
            throw new PosException('sale_settlement_unconfirmed');
        }
        if ($apply) {
            $this->store->rows('readers', (int) $sale->tenant_id)->where('id', $reader->id)->update(['acceptance_at' => now(), 'acceptance_build' => $snapshot['build']]);
            $this->store->event((int) $sale->tenant_id, $sale->id, 'stripe_physical_acceptance_recorded', ['owner_id' => $owner, 'reader_id' => $reader->reader_id, 'build' => $snapshot['build'], 'evidence_hash' => $evidenceHash]);
        }

        return ['mode' => $apply ? 'apply' : 'preview', 'sale_id' => $sale->id, 'reader_id' => $reader->reader_id, 'build' => $snapshot['build'],
            'physical_observation' => 'owner_attested', 'payment_refund_settlement' => 'provider_verified'];
    }
}
