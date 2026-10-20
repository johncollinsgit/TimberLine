<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Market;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\OrderLine;
use App\Models\Marketplace\StoreLink;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class MarketplaceReporting
{
    public function overview(Request $request): array
    {
        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'maker' => 'nullable|integer', 'customer' => 'nullable|email:rfc|max:254']);
        $zone = config('marketplace.timezone', 'America/New_York');
        $from = CarbonImmutable::parse($data['from'] ?? now($zone)->startOfMonth()->toDateString(), $zone)->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? now($zone)->toDateString(), $zone)->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose a date range of one year or less.');
        $query = Order::query()->where('test_mode', false)->whereBetween('created_at', [$from->utc(), $to->utc()]);
        if (! empty($data['maker'])) {
            $maker = Maker::findOrFail($data['maker']);
            $query->whereHas('lines', fn ($q) => $q->where('maker_id', $maker->id));
        }
        if (! empty($data['customer'])) {
            $query->where('email_hash', hash('sha256', strtolower(trim($data['customer']))));
        }
        $orders = $query->with('lines.maker')->latest('id')->limit(2000)->get();
        $lines = $orders->flatMap->lines;
        if (! empty($data['maker'])) {
            $lines = $lines->where('maker_id', (int) $data['maker']);
        }
        $makerSales = $lines->groupBy('maker_id')->map(fn ($rows) => ['maker' => $rows->first()->maker, 'units' => $rows->sum('quantity'), 'refunded_units' => $rows->sum('refunded_quantity'), 'merchandise_cents' => $rows->sum(fn ($line) => $line->unit_price_cents * $line->quantity), 'discount_cents' => $rows->sum('discount_cents'), 'supplier_cents' => $rows->sum(fn ($line) => $line->maker_unit_cents * ($line->quantity - $line->refunded_quantity))])->sortByDesc('merchandise_cents');
        $customers = $orders->groupBy('email_hash')->map(fn ($rows) => ['email' => $rows->first()->customer['email'] ?? '', 'orders' => $rows->count(), 'paid_cents' => $rows->sum('total_cents'), 'last_order' => $rows->first(), 'maker_count' => $rows->flatMap->lines->pluck('maker_id')->unique()->count()])->sortByDesc('paid_cents');

        return ['from' => $from, 'to' => $to, 'salesOrders' => $orders, 'salesLines' => $lines, 'makerSales' => $makerSales, 'customers' => $customers, 'metrics' => ['paid_cents' => $orders->sum('total_cents'), 'merchandise_cents' => $lines->sum(fn ($l) => $l->unit_price_cents * $l->quantity), 'discount_cents' => $lines->sum('discount_cents'), 'units' => $lines->sum('quantity'), 'refund_units' => $lines->sum('refunded_quantity'), 'customers' => $customers->count(), 'orders' => $orders->count(), 'tax_cents' => $orders->sum('tax_cents'), 'shipping_cents' => $orders->sum('shipping_cents')], 'upcomingMarkets' => Market::where('ends_at', '>=', now())->withCount(['makers as approved_makers_count' => fn ($q) => $q->where('marketplace_market_makers.status', 'approved')])->orderBy('starts_at')->limit(30)->get(), 'pendingPickup' => OrderLine::whereHas('order', fn ($q) => $q->where('test_mode', false)->where('group_key', '!=', 'shipping'))->whereIn('fulfillment_status', ['unfulfilled', 'ready'])->with('maker', 'order')->latest('id')->limit(100)->get(), 'storeLinks' => StoreLink::with('maker')->latest('id')->limit(300)->get(), 'reportTruncated' => $orders->count() === 2000];
    }
}
