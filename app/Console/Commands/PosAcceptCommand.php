<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\Pos\PosException;
use App\Services\Pos\PosRecoveryAccess;
use App\Services\Pos\PosService;
use App\Services\Pos\PosStore;
use App\Services\Pos\SquareClient;
use App\Services\Pos\SquareNativeProvider;
use Illuminate\Console\Command;

/** Requires an observed physical test plus fresh original-account provider evidence. */
final class PosAcceptCommand extends Command
{
    protected $signature = 'pos:accept {tenant} {sale} {--actor=} {--evidence=} {--apply}';

    protected $description = 'Verify original payment/refund/payout evidence and record physical exact-build acceptance';

    public function handle(PosStore $store, PosService $service, SquareClient $square, PaymentJournal $journal, SquareNativeProvider $provider): int
    {
        $tenant = (int) $this->argument('tenant');
        $actor = User::query()->find((int) $this->option('actor'));
        $member = $store->db->table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $actor?->id)->first();
        if (! $actor?->is_active || ! $actor->hasVerifiedEmail() || ! $member?->membership_active
            || ! in_array($member->role, ['owner', 'tenant_owner', 'admin'], true)) {
            throw new PosException('existing_owner_required', 403);
        }
        $path = $this->option('evidence');
        if (! is_string($path) || ! is_file($path) || is_link($path) || filesize($path) > 65536 || (fileperms($path) & 0077) !== 0) {
            throw new PosException('private_physical_evidence_required');
        }
        $evidence = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        $sale = $store->rows('sales', $tenant)->where('id', $this->argument('sale'))->firstOrFail();
        $snapshot = $service->snapshot($sale);
        if (($snapshot['binding']['provider'] ?? '') === 'stripe_terminal') {
            $result = app(\App\Services\Pos\PosStripeAcceptance::class)->verify($sale, $snapshot, $evidence, (int) $actor->id, hash_file('sha256', $path), (bool) $this->option('apply'));
            $this->line(json_encode($result));

            return self::SUCCESS;
        }
        if (($evidence['sale_id'] ?? null) !== $sale->id || ($evidence['build'] ?? null) !== ($snapshot['build'] ?? null)
            || empty($snapshot['build']) || empty($snapshot['device_id']) || ($evidence['physical_iphone_observed'] ?? null) !== true
            || ($evidence['receipt_shared_or_printed'] ?? null) !== true || ($evidence['stock_and_refund_reviewed'] ?? null) !== true
            || ! preg_match('/^[a-f0-9]{64}$/D', $evidence['signed_archive_sha256'] ?? '')
            || ! is_array($evidence['payout_ids'] ?? null) || ! $evidence['payout_ids'] || count($evidence['payout_ids']) > 5
            || $sale->state !== 'succeeded' || $sale->stock_state !== 'committed') {
            throw new PosException('physical_acceptance_evidence_incomplete');
        }
        $binding = MerchantBinding::restore($snapshot['binding']);
        if ($binding->environment !== 'live' || $binding->provider !== 'square_native') {
            throw new PosException('production_acceptance_required');
        }
        $connection = $square->connection($tenant, $snapshot['connection_id']);
        $square->verify($connection, false);
        $core = new PaymentCore($journal, ['square_native' => $provider], new PosRecoveryAccess($store));
        $payment = $core->recover($binding, $sale->attempt_id);
        $provider->audit($payment);
        if ($payment['state'] !== 'succeeded') {
            throw new PosException('original_payment_unconfirmed');
        }
        $refunds = $store->db->table('payment_core_operations')->where('tenant_id', $tenant)->where('original_id', $sale->attempt_id)->where('kind', 'refund')->get(['id']);
        $confirmedRefund = false;
        foreach ($refunds as $refund) {
            $result = $core->recover($binding, $refund->id);
            $provider->audit($result);
            $confirmedRefund = $confirmedRefund || $result['state'] === 'succeeded';
        }
        if (! $confirmedRefund || ! $store->rows('outbox', $tenant)->where('sale_id', $sale->id)->where('effect_key', 'receipt')->where('state', 'completed')->exists()
            || $store->rows('outbox', $tenant)->where('sale_id', $sale->id)->where('state', '!=', 'completed')->exists()) {
            throw new PosException('receipt_refund_and_sync_acceptance_required');
        }
        $settled = false;
        foreach (array_unique($evidence['payout_ids']) as $id) {
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $id)) {
                throw new PosException('payout_reference_invalid');
            }
            $payout = $square->get($connection, '/v2/payouts/'.rawurlencode($id))['payout'] ?? [];
            if (($payout['id'] ?? '') !== $id || ($payout['location_id'] ?? '') !== $binding->locationId || ($payout['status'] ?? '') !== 'PAID') {
                throw new PosException('original_location_settlement_unconfirmed');
            }
            $cursor = null;
            for ($page = 0; $page < 5; $page++) {
                $entries = $square->get($connection, '/v2/payouts/'.rawurlencode($id).'/payout-entries', array_filter(['limit' => 100, 'cursor' => $cursor]));
                foreach ($entries['payout_entries'] ?? [] as $entry) {
                    if (($entry['type_charge_details']['payment_id'] ?? null) === $payment['provider_id']
                        && ($entry['gross_amount_money']['amount'] ?? null) === $payment['amount_minor']) {
                        $settled = true;
                    }
                }
                $cursor = $entries['cursor'] ?? null;
                if (! $cursor) {
                    break;
                }
            }
            if ($cursor) {
                throw new PosException('payout_search_incomplete');
            }
        }
        if (! $settled) {
            throw new PosException('sale_settlement_unconfirmed');
        }
        $this->line(json_encode(['mode' => $this->option('apply') ? 'apply' : 'preview', 'sale_id' => $sale->id,
            'physical_observation' => 'owner_attested', 'payment_refund_settlement' => 'provider_verified', 'build' => $snapshot['build']]));
        if ($this->option('apply')) {
            $store->db->transaction(function () use ($store, $tenant, $connection, $snapshot, $path, $sale, $actor): void {
                $store->rows('connections', $tenant)->where('id', $connection->id)->update(['acceptance_at' => now(), 'acceptance_build' => $snapshot['build']]);
                $store->event($tenant, $sale->id, 'physical_acceptance_recorded', ['actor_id' => $actor->id,
                    'build' => $snapshot['build'], 'evidence_hash' => hash_file('sha256', $path)]);
            });
        }

        return self::SUCCESS;
    }
}
