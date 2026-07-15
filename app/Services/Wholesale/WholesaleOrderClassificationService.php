<?php

namespace App\Services\Wholesale;

use App\Models\Order;
use App\Models\User;
use App\Models\WholesaleOrderClassification;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class WholesaleOrderClassificationService
{
    public function __construct(protected LandlordOperatorActionAuditService $audit) {}

    /** @param array<string,mixed> $evidence */
    public function classify(int $tenantId, int $orderId, string $status, User $actor, string $basis, array $evidence): WholesaleOrderClassification
    {
        if (! in_array($status, ['confirmed', 'manual_override', 'retail_only'], true)) {
            throw new DomainException('Unsupported wholesale classification status.');
        }
        if (trim($basis) === '' || $evidence === []) {
            throw new DomainException('Classification basis and evidence are required.');
        }

        $order = Order::query()->where('tenant_id', $tenantId)->findOrFail($orderId);
        $before = WholesaleOrderClassification::query()->forAllTenants()
            ->where('tenant_id', $tenantId)->where('order_id', $order->id)->first()?->toArray();

        $classification = DB::transaction(fn (): WholesaleOrderClassification => WholesaleOrderClassification::query()->updateOrCreate([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
        ], [
            'status' => $status,
            'classification_basis' => trim($basis),
            'evidence' => $evidence,
            'classified_by_user_id' => $actor->id,
            'classified_at' => now(),
        ]));

        $this->audit->record($tenantId, (int) $actor->id, 'wholesale.order_classification.changed', targetType: 'order', targetId: $order->id, context: [
            'surface' => 'wholesale_classification_review',
            'billing_impact' => 'none',
        ], beforeState: $before, afterState: $classification->toArray());

        return $classification;
    }
}
