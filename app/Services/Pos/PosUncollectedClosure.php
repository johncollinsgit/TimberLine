<?php

namespace App\Services\Pos;

use App\Models\User;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\PaymentJournal;
use Illuminate\Support\Facades\Cache;

/** Retire a reviewed legacy lane without fabricating a terminal Square payment. */
final class PosUncollectedClosure
{
    public function __construct(private PosStore $store, private PaymentJournal $journal, private SquareClient $square,
        private SquareNativeProvider $provider, private ShopifyPosCommerce $shopify) {}

    public function closed(object $sale): bool
    {
        return $sale->state === 'unknown' && $sale->stock_state === 'released' && $this->recorded($sale);
    }

    public function recorded(object $sale): bool
    {
        return $this->store->rows('events', (int) $sale->tenant_id)->where('sale_id', $sale->id)->where('code', 'uncollected_review_closed')->exists();
    }

    public function excludeClosed(\Illuminate\Database\Query\Builder $query): void
    {
        $query->where(function ($q): void {
            $q->where('pos_live_sales.state', '!=', 'unknown')->orWhere('pos_live_sales.stock_state', '!=', 'released')
                ->orWhereNotExists(function ($events): void {
                    $events->selectRaw('1')->from('pos_live_events as closed')->whereColumn('closed.runtime_id', 'pos_live_sales.runtime_id')
                        ->whereColumn('closed.tenant_id', 'pos_live_sales.tenant_id')->whereColumn('closed.sale_id', 'pos_live_sales.id')->where('closed.code', 'uncollected_review_closed');
                });
        });
    }

    public function review(int $tenant, string $id, int $actorId, array $device, bool $apply = false): array
    {
        return Cache::lock('pos-legacy-review-'.$tenant.'-'.$id, 180)->block(5, function () use ($tenant, $id, $actorId, $device, $apply): array {
            $actor = User::query()->find($actorId);
            $membership = $this->store->db->table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $actorId)->first();
            if (! $actor?->is_active || ! $actor->hasVerifiedEmail() || ! $membership?->membership_active
                || ! in_array($membership->role, ['owner', 'tenant_owner', 'admin'], true) || config('pos_live.servicing_enabled') !== true) {
                throw new PosException('owner_review_required', 403);
            }
            $sale = $this->store->rows('sales', $tenant)->where('id', $id)->firstOrFail();
            $snapshot = app(PosService::class)->snapshot($sale);
            $register = $this->store->rows('registers', $tenant)->where('id', $sale->register_id)->firstOrFail();
            $operation = $sale->attempt_id ? $this->journal->get($tenant, $sale->attempt_id) : null;
            if (! $register->servicing_enabled || ! $operation || $sale->state !== 'unknown' || $operation['state'] !== 'unknown'
                || $operation['provider_id'] !== null || $operation['request_id'] !== $sale->payment_request_id || $operation['sale_id'] !== $id
                || (int) $operation['amount_minor'] !== (int) $sale->amount_minor || Contract::hash($operation['binding']) !== Contract::hash($snapshot['binding'])
                || $snapshot['terms']['source'] !== 'shopify') {
                throw new PosException('legacy_uncollected_review_required');
            }
            $ended = strtotime($device['app_terminated_at'] ?? '');
            $absent = strtotime($device['app_process_absent_at'] ?? '');
            if (($device['owner_confirmed_no_card_presented'] ?? false) !== true || ! $ended || $ended <= strtotime($sale->descriptor_issued_at ?? $sale->created_at)
                || $ended > time() - 300 || ! $absent || $absent < $ended || $absent < time() - 120 || $absent > time() + 5
                || ($device['app_device_id'] ?? '') !== ($snapshot['device_id'] ?? '')
                || ! preg_match('/^[a-f0-9]{64}$/D', $device['process_evidence_sha256'] ?? '')) {
                throw new PosException('fresh_device_end_evidence_required');
            }
            $connection = $this->square->connection($tenant, $snapshot['connection_id']);
            $this->square->verify($connection, false);
            if ($this->provider->findPayment($operation) !== null) {
                throw new PosException('square_payment_exists_review_required');
            }
            $originalRegister = clone $register;
            $originalRegister->configuration = $this->store->encrypt($snapshot['configuration']);
            $scope = ['tenant_id' => $tenant, 'actor_id' => $actorId, 'register' => $originalRegister, 'connection' => $connection];
            $order = $this->shopify->reviewUncollected($scope, $sale, $snapshot);
            $result = ['sale_id' => $id, 'square_payment_found' => false, 'financial_state_retained' => 'unknown', ...$order];
            if (! $apply || $this->closed($sale)) {
                return [...$result, 'applied' => $this->closed($sale)];
            }
            $this->store->db->transaction(function () use ($tenant, $actorId, $sale, $register, $device): void {
                $current = $this->store->rows('sales', $tenant)->where('id', $sale->id)->lockForUpdate()->firstOrFail();
                if ($current->state !== 'unknown') {
                    throw new PosException('legacy_review_state_changed');
                }
                $this->store->rows('registers', $tenant)->where('id', $register->id)->update(['active' => false, 'collection_enabled' => false]);
                $this->store->event($tenant, $sale->id, 'uncollected_close_intent', ['actor_id' => $actorId, 'device_evidence_hash' => Contract::hash($device), 'square_absence_checked_at' => now()->toIso8601String()]);
            });
            $this->shopify->reviewUncollected($scope, $sale, $snapshot, true);
            $latest = $this->journal->get($tenant, $operation['id']);
            if ($latest['state'] !== 'unknown' || $latest['provider_id'] !== null) {
                throw new PosException('legacy_review_state_changed');
            }
            $this->store->event($tenant, $sale->id, 'uncollected_review_closed', ['actor_id' => $actorId, 'device_evidence_hash' => Contract::hash($device),
                'original_operation_id' => $operation['id'], 'original_register_id' => $register->id, 'financial_state_retained' => 'unknown', 'closed_at' => now()->toIso8601String()]);

            return [...$result, 'applied' => true];
        });
    }
}
