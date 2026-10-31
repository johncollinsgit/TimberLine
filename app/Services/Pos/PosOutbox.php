<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentJournal;
use Illuminate\Support\Facades\Cache;

final class PosOutbox
{
    public function __construct(private PosStore $store, private PaymentJournal $journal,
        private NativePosCommerce $native, private ShopifyPosCommerce $shopify) {}

    public function drainSale(array $scope, string $saleId): void
    {
        $rows = $this->store->rows('outbox', $scope['tenant_id'])->where('sale_id', $saleId)
            ->whereIn('state', ['pending', 'retry', 'processing'])->where('available_at', '<=', now())->orderBy('effect_key')->limit(30)->get();
        foreach ($rows as $effect) {
            Cache::lock('pos-outbox-'.$effect->id, 180)->get(function () use ($scope, $effect): void {
                $current = $this->store->rows('outbox', $scope['tenant_id'])->where('id', $effect->id)->firstOrFail();
                if ($current->state === 'completed' || $current->state === 'review'
                    || ($current->state === 'processing' && $current->claimed_at && strtotime($current->claimed_at) > time() - 180)) {
                    return;
                }
                $this->store->rows('outbox', $scope['tenant_id'])->where('id', $effect->id)
                    ->update(['state' => 'processing', 'claimed_at' => now(), 'attempts' => $current->attempts + 1]);
                try {
                    $this->handle($scope, $current);
                    $this->store->rows('outbox', $scope['tenant_id'])->where('id', $effect->id)
                        ->update(['state' => 'completed', 'completed_at' => now(), 'error_code' => null]);
                } catch (\Throwable $error) {
                    $code = $error instanceof PosException ? $error->blocker : 'outbox_processing_failed';
                    $this->store->rows('outbox', $scope['tenant_id'])->where('id', $effect->id)->update([
                        'state' => $current->attempts >= 7 || in_array($code, ['customer_rewards_shortfall_review', 'customer_identity_review_required', 'customer_rewards_historical_review'], true) ? 'review' : 'retry', 'error_code' => $code,
                        'available_at' => now()->addSeconds(min(300, 5 * (2 ** min(6, $current->attempts)))),
                    ]);
                    $this->store->event($scope['tenant_id'], $effect->sale_id, 'effect_needs_reconciliation', ['effect' => $effect->effect_key, 'code' => $code]);
                }
            });
        }
    }

    private function handle(array $scope, object $effect): void
    {
        $sale = $this->store->rows('sales', $scope['tenant_id'])->where('id', $effect->sale_id)->firstOrFail();
        $service = app(PosService::class);
        $snapshot = $service->snapshot($sale);
        // Historic commerce effects use their original store/configuration.
        $register = clone $scope['register'];
        $register->configuration = $this->store->encrypt($snapshot['configuration']);
        $scope['register'] = $register;
        $payload = $this->store->decrypt($effect->payload);
        if (str_starts_with($effect->effect_key, 'reconcile_')) {
            $operation = $this->journal->findByRequest($scope['tenant_id'], $sale->payment_request_id);
            if ($operation) {
                $binding = MerchantBinding::restore($operation['binding']);
                $core = new PaymentCore($this->journal, ['square_native' => app(SquareNativeProvider::class)], new PosRecoveryAccess($this->store));
                if ($operation['state'] === 'succeeded') {
                    app(SquareNativeProvider::class)->audit($operation);
                }
                $service->recordOutcome($sale, $core->recover($binding, $operation['id']));
            }

            return;
        }
        if (str_starts_with($effect->effect_key, 'customer_refunds_')) {
            app(PosCustomerRewards::class)->apply($sale);
            $this->store->event($scope['tenant_id'], $sale->id, 'effect_completed', ['effect' => $effect->effect_key]);

            return;
        }
        $operation = $this->journal->get($scope['tenant_id'], $payload['operation_id']);
        if ($effect->effect_key === 'customer_purchase' || str_starts_with($effect->effect_key, 'customer_refund_') || str_starts_with($effect->effect_key, 'customer_association_')) {
            app(PosCustomerRewards::class)->apply($sale, $operation['kind'] === 'refund' ? $operation : null);
        } elseif ($effect->effect_key === 'finalize') {
            if ($operation['state'] !== 'succeeded') {
                throw new PosException('paid_evidence_required');
            }
            if ($snapshot['terms']['source'] === StockPosCommerce::VERSION) {
                app(StockPosCommerce::class)->finalize($sale, $snapshot, $operation);
            } elseif ($snapshot['terms']['source'] === 'shopify') {
                $this->shopify->finalize($scope, $sale, $snapshot, $operation);
            } else {
                $this->native->finalize($sale, $snapshot, $operation);
            }
            $service->enqueue($scope['tenant_id'], $sale->id, 'receipt', ['operation_id' => $operation['id']]);
        } elseif ($effect->effect_key === 'release') {
            if (! in_array($operation['state'], ['failed', 'cancelled'], true)) {
                throw new PosException('unpaid_evidence_required');
            }
            if ($snapshot['terms']['source'] === StockPosCommerce::VERSION) {
                app(StockPosCommerce::class)->release($sale, $snapshot);
            } elseif ($snapshot['terms']['source'] === 'shopify') {
                $this->shopify->release($scope, $sale);
            } else {
                $this->native->release($sale, $snapshot);
            }
        } elseif (str_starts_with($effect->effect_key, 'refund_')) {
            if ($operation['kind'] !== 'refund' || $operation['state'] !== 'succeeded') {
                throw new PosException('confirmed_refund_required');
            }
            if ($snapshot['terms']['source'] === 'shopify' && ! app(PosUncollectedClosure::class)->recorded($sale)) {
                $this->shopify->refund($scope, $sale, $operation);
            } elseif ($snapshot['terms']['source'] === 'website_invoice') {
                $this->invoiceRefund($sale, $snapshot, $operation);
            }
        } elseif ($effect->effect_key !== 'receipt') {
            throw new PosException('effect_unrecognized');
        }
        $this->store->event($scope['tenant_id'], $sale->id, 'effect_completed', ['effect' => $effect->effect_key]);
    }

    private function invoiceRefund(object $sale, array $snapshot, array $refund): void
    {
        $this->store->db->transaction(function () use ($sale, $snapshot, $refund): void {
            $order = WebsiteOrder::forTenantId($sale->tenant_id)->whereKey($snapshot['terms']['source_order_id'])->lockForUpdate()->firstOrFail();
            $payment = WebsitePayment::forTenantId($sale->tenant_id)->where('website_order_id', $order->id)->where('provider', 'square')
                ->where('metadata->pos_sale_id', $sale->id)->lockForUpdate()->firstOrFail();
            $metadata = $payment->metadata;
            if (isset($metadata['refunds'][$refund['id']])) {
                return;
            }
            $metadata['refunds'][$refund['id']] = ['status' => 'succeeded', 'amount_cents' => $refund['amount_minor'], 'provider_id' => $refund['provider_id']];
            $refunded = array_sum(array_column($metadata['refunds'], 'amount_cents'));
            $payment->update(['metadata' => $metadata, 'status' => $refunded === $payment->amount_cents ? 'refunded' : 'partially_refunded']);
            $order->update(['refunded_cents' => $refunded, 'payment_status' => $refunded === $order->total_cents ? 'refunded' : 'partially_refunded']);
        }, 3);
    }
}
