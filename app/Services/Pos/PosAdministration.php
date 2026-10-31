<?php

namespace App\Services\Pos;

use App\Models\User;
use Illuminate\Http\Request;

final class PosAdministration
{
    public function __construct(private PosStore $store, private Request $request) {}

    public function scope(): array
    {
        $user = $this->request->user();
        $tenant = (int) ($this->request->attributes->get('current_tenant_id') ?: $this->request->attributes->get('current_tenant')?->id);
        $current = $user instanceof User ? User::query()->find($user->id) : null;
        $membership = $this->store->db->table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $user?->id)->first();
        if (config('pos_live.enabled') !== true || ! $current?->is_active || ! $current->hasVerifiedEmail()
            || ! $membership?->membership_active || ! in_array($membership->role, ['owner', 'tenant_owner', 'admin', 'manager'], true)) {
            throw new PosException('manager_access_required', 403);
        }
        $admin = in_array($membership->role, ['owner', 'tenant_owner', 'admin'], true);
        $registers = $this->store->rows('registers', $tenant);
        if (! $admin) {
            $ids = $this->store->rows('assignments', $tenant)->where('user_id', $user->id)->where('active', true)
                ->whereIn('role', ['manager', 'admin'])->pluck('register_id');
            $registers->whereIn('id', $ids);
        }

        return ['tenant_id' => $tenant, 'actor_id' => $user->id, 'admin' => $admin, 'registers' => $registers->get()];
    }

    public function report(array $scope, string $from, string $to): array
    {
        $locations = $scope['registers']->pluck('location_id')->unique()->values();
        $query = $this->store->rows('sales', $scope['tenant_id'])->whereIn('register_id', $scope['registers']->pluck('id'))
            ->whereBetween('created_at', [$from, $to]);
        $sales = $query->orderBy('created_at')->limit(1001)->get();
        if ($sales->count() > 1000) {
            throw new PosException('report_range_too_large', 422);
        }
        $rows = [];
        foreach ($locations as $location) {
            $group = $sales->where('location_id', $location);
            $paid = $group->where('state', 'succeeded');
            $refunded = $paid->sum(fn ($sale) => app(PosCustomerPurchases::class)->confirmedRefunds($sale));
            $rows[] = ['location_id' => $location, 'name' => $scope['registers']->firstWhere('location_id', $location)->location_name,
                'paid_sales' => $paid->count(), 'gross_minor' => (int) $paid->sum('amount_minor'), 'refunded_minor' => $refunded,
                'unresolved_payments' => $group->whereIn('state', ['processing', 'unknown', 'review'])->count(),
                'paid_orders_pending_sync' => $paid->where('stock_state', '!=', 'committed')->count()];
        }

        return ['currency' => 'USD', 'from_utc' => $from, 'to_utc' => $to, 'locations' => $rows,
            'gross_minor' => array_sum(array_column($rows, 'gross_minor')), 'refunded_minor' => array_sum(array_column($rows, 'refunded_minor')),
            'basis' => 'Confirmed operational payments; not additional accounting revenue or settlement net.'];
    }

    public function inventory(array $scope): array
    {
        return $this->store->rows('items', $scope['tenant_id'])->whereIn('location_id', $scope['registers']->pluck('location_id'))
            ->orderBy('location_id')->orderBy('sku')->limit(500)->get(['id', 'location_id', 'sku', 'title', 'quantity', 'tracked'])->all();
    }

    public function adjust(array $scope, string $itemId, array $input): void
    {
        $this->store->db->transaction(function () use ($scope, $itemId, $input): void {
            $item = $this->store->rows('items', $scope['tenant_id'])->whereIn('location_id', $scope['registers']->pluck('location_id'))
                ->where('id', $itemId)->lockForUpdate()->first();
            if (! $item || ! $item->tracked) {
                throw new PosException('stock_item_unavailable', 404);
            }
            $key = 'stock_adjustment_'.$input['request_id'];
            $payload = ['actor_id' => $scope['actor_id'], 'item_id' => $itemId, 'delta' => $input['delta'], 'reason' => $input['reason']];
            $old = $this->store->rows('outbox', $scope['tenant_id'])->where('sale_id', $itemId)->where('effect_key', $key)->first();
            if ($old) {
                if ($this->store->decrypt($old->payload) !== $payload) {
                    throw new PosException('adjustment_request_conflict');
                }

                return;
            }
            $held = $this->store->rows('holds', $scope['tenant_id'])->where('item_id', $itemId)->where('state', 'held')->lockForUpdate()->get()->sum('quantity');
            $quantity = (int) $item->quantity + $input['delta'];
            if ($quantity < $held || $quantity < 0 || $quantity > 1000000) {
                throw new PosException('stock_adjustment_conflicts_with_holds');
            }
            $this->store->rows('items', $scope['tenant_id'])->where('id', $itemId)->update(['quantity' => $quantity]);
            $this->store->rows('outbox', $scope['tenant_id'])->insert($this->store->values($scope['tenant_id'], [
                'id' => bin2hex(random_bytes(16)), 'sale_id' => $itemId, 'effect_key' => $key, 'payload' => $this->store->encrypt($payload),
                'state' => 'completed', 'available_at' => now(), 'completed_at' => now(),
            ]));
            $this->store->event($scope['tenant_id'], $itemId, 'inventory_adjusted', [...$payload, 'before' => (int) $item->quantity, 'after' => $quantity]);
        }, 3);
    }

    public function pause(array $scope): void
    {
        if (! $scope['admin']) {
            throw new PosException('organization_admin_required', 403);
        }
        $this->store->db->transaction(function () use ($scope): void {
            $this->store->rows('registers', $scope['tenant_id'])->update(['collection_enabled' => false]);
            $this->store->event($scope['tenant_id'], 'all_registers', 'merchant_collection_paused', ['actor_id' => $scope['actor_id']]);
        });
    }
}
