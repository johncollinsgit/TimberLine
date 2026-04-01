<?php

namespace App\Services\Search\Providers;

use App\Models\Order;
use App\Models\User;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\GlobalSearchProvider;
use Illuminate\Support\Facades\Schema;

class OrdersSearchProvider implements GlobalSearchProvider
{
    use BuildsSearchResults;

    public function search(string $query, array $context = []): array
    {
        $tenantId = is_numeric($context['tenant_id'] ?? null) ? (int) $context['tenant_id'] : null;
        if ($tenantId === null || ! Schema::hasTable('orders')) {
            return [];
        }

        $user = $context['user'] ?? null;
        if (! $user instanceof User || (! $user->isAdmin() && ! $user->isManager() && ! $user->canAccessMarketing())) {
            return [];
        }

        $normalized = trim($query);
        $rows = Order::query()
            ->forTenantId($tenantId)
            ->select(['id', 'order_number', 'order_label', 'customer_name', 'shopify_name', 'status', 'total_price'])
            ->when($normalized !== '', function ($builder) use ($normalized): void {
                $builder->where(function ($query) use ($normalized): void {
                    $like = '%'.$normalized.'%';
                    $query->where('order_number', 'like', $like)
                        ->orWhere('order_label', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('shopify_name', 'like', $like);
                });
            })
            ->limit(5)
            ->get();

        return $rows->map(function (Order $order) use ($normalized, $user): array {
            $title = trim((string) ($order->order_label ?: $order->shopify_name ?: $order->order_number ?: 'Order'));
            $subtitle = trim(implode(' • ', array_filter([
                $order->customer_name,
                ucfirst((string) $order->status),
                '$'.number_format((float) ($order->total_price ?? 0), 2),
            ])));

            return $this->result([
                'type' => 'order',
                'subtype' => 'record',
                'title' => $title,
                'subtitle' => $subtitle,
                'url' => $this->destinationUrl($order, $user),
                'badge' => 'Order',
                'score' => $this->matchScore($normalized, [$title, $order->customer_name, $order->order_number], 260),
                'icon' => 'shopping-bag',
                'meta' => [
                    'order_id' => (int) $order->id,
                ],
            ]);
        })->all();
    }

    protected function destinationUrl(Order $order, mixed $user): string
    {
        if ($user instanceof User && ($user->isAdmin() || $user->isManager())) {
            return route('shipping.orders', ['search' => $order->order_number ?: $order->order_label ?: $order->id]);
        }

        return route('marketing.orders', ['search' => $order->order_number ?: $order->order_label ?: $order->id]);
    }
}
