<?php

namespace App\Services\Team\Operations;

use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/** Read-only reconciliation over the established tenant-scoped Square history. */
class OperationsSquare
{
    public function event(Tenant $tenant, User $user, TeamOperationRecord $event, int $page = 1): array
    {
        $access = app(OperationsAccess::class);
        $access->financial($tenant, $user);
        $access->record($tenant, $user, $event);
        abort_unless($event->kind === 'event' && Schema::hasTable('square_orders') && Schema::hasTable('square_payments'), 503, 'The tenant Square history is unavailable.');
        $selected = $event->data['_teams_enrichment']['square_orders']['ids'] ?? [];
        $conflicts = [];
        foreach (TeamOperationRecord::where('tenant_id', $tenant->id)->where('kind', 'event')->where('id', '!=', $event->id)->where('status', '!=', 'withdrawn')->cursor() as $other) {
            foreach ($other->data['_teams_enrichment']['square_orders']['ids'] ?? [] as $id) {
                $conflicts[$id] = true;
            }
        }
        $query = SquareOrder::forTenantId($tenant->id)->orderByDesc('closed_at')->orderBy('id');
        if ($event->starts_on) {
            $query->whereBetween('closed_at', [$event->starts_on->copy()->subDays(7)->startOfDay(), ($event->ends_on ?? $event->starts_on)->copy()->addDays(7)->endOfDay()]);
        }
        $p = $query->paginate(30, ['*'], 'page', $page);
        $items = [];
        foreach ($p->items() as $order) {
            $items[] = $this->order($tenant, $order, in_array($order->square_order_id, $selected, true), isset($conflicts[$order->square_order_id]));
        }
        $mapped = SquareOrder::forTenantId($tenant->id)->whereIn('square_order_id', $selected)->get();
        $evidence = [];
        foreach ($mapped as $order) {
            $evidence[] = $this->order($tenant, $order, true, isset($conflicts[$order->square_order_id]));
        }

        return ['items' => $items, 'selected' => $evidence, 'page' => $page, 'has_more' => $p->hasMorePages(), 'complete' => false, 'notice' => 'Square is reconciliation evidence. Order totals and recorded refunds are separate from spreadsheet net sales; they are never added to event revenue. Date-window matches require review. Offline orders and later refunds can arrive after the event.', 'source' => 'existing_tenant_square_history', 'as_of' => now()->toIso8601String()];
    }

    private function order(Tenant $tenant, SquareOrder $order, bool $selected, bool $conflict): array
    {
        $refunds = 0;
        $refundKnown = true;
        $payments = 0;
        $offline = false;
        foreach (SquarePayment::forTenantId($tenant->id)->where('square_order_id', $order->square_order_id)->get() as $payment) {
            $payments++;
            $money = $payment->raw_payload['refunded_money'] ?? null;
            if (isset($money['amount'], $money['currency']) && $money['currency'] === $order->total_money_currency && is_numeric($money['amount'])) {
                $refunds += (int) $money['amount'];
            } else {
                $refundKnown = false;
            }
            $offline = $offline || ($payment->raw_payload['is_offline_payment'] ?? false);
        }

        return ['id' => $order->square_order_id, 'status' => $order->state, 'location_id' => $order->location_id, 'closed_at' => $order->closed_at?->toIso8601String(), 'order_total_cents' => $order->total_money_amount === null ? null : (int) $order->total_money_amount, 'currency' => $order->total_money_currency, 'recorded_refunds_cents' => $refundKnown && $payments > 0 ? $refunds : null, 'offline' => $offline, 'selected' => $selected, 'allocation_conflict' => $conflict, 'last_synced_at' => $order->synced_at?->toIso8601String()];
    }
}
