<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteOrder;
use App\Models\WebsiteShipment;
use App\Services\ManagedWebsite\WebsiteShippingHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/** Read-only runtime evidence. Never sends provider requests or exposes credentials/addresses. */
class AuditWebsiteShipping extends Command
{
    protected $signature = 'website:audit-shipping {--tenant= : Exact workspace slug} {--all : Explicitly inspect every workspace}
        {--since= : First complete calendar day, YYYY-MM-DD} {--until= : Last complete calendar day, YYYY-MM-DD}';

    protected $description = 'Export tenant shipping readiness and native purchased-label cost evidence as redacted JSON';

    public function handle(WebsiteShippingHealthService $health): int
    {
        if ((bool) $this->option('all') === filled($this->option('tenant'))) {
            $this->error('Choose exactly one of --tenant=workspace-slug or --all.');

            return self::FAILURE;
        }
        $dates = ['since' => $this->option('since') ?: CarbonImmutable::today()->subDays(90)->toDateString(),
            'until' => $this->option('until') ?: CarbonImmutable::yesterday()->toDateString()];
        $validator = Validator::make($dates, ['since' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:since', 'before:today']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $start = CarbonImmutable::parse($dates['since'])->startOfDay();
        $end = CarbonImmutable::parse($dates['until'])->addDay()->startOfDay();
        $tenants = Tenant::query()->when(! $this->option('all'), fn ($query) => $query->where('slug', $this->option('tenant')))->orderBy('id')->get();
        if ($tenants->isEmpty()) {
            $this->error('Workspace not found.');

            return self::FAILURE;
        }
        $rows = [];
        foreach ($tenants as $tenant) {
            $sites = TenantSite::query()->forTenant($tenant)->get();
            $accounts = IntegrationConnection::query()->forTenant($tenant)->whereIn('provider', ['easypost', 'shippo', 'shipstation', 'freightview', 'freightquote', 'goship'])
                ->orderBy('id')->get()->map(fn ($connection) => ['id' => $connection->id, 'provider' => $connection->provider,
                    'status' => $connection->status, 'credential_present' => filled($connection->access_token),
                    'webhook_secret_present' => filled($connection->external_account_secret),
                    'mode' => data_get($connection->metadata, 'native_shipping.mode'),
                    'last_rate_verified_at' => data_get($connection->metadata, 'native_shipping.verified_at')])->all();
            $serviceTotals = [];
            $orderIds = [];
            $labelCost = $refundCost = $unknownCost = $labels = 0;
            foreach (WebsiteShipment::query()->forTenant($tenant)->where('provider', 'easypost')->where('parcel->_native->version', 1)
                ->where('parcel->_native->mode', 'production')
                ->where('currency', 'usd')->where('purchased_at', '>=', $start)->where('purchased_at', '<', $end)->cursor() as $shipment) {
                $labels++;
                $cost = (int) $shipment->label_cost_cents;
                $labelCost += $cost;
                $unknownCost += $shipment->label_cost_cents === null ? 1 : 0;
                $orderIds[$shipment->website_order_id] = true;
                $key = $shipment->carrier.':'.$shipment->service;
                $serviceTotals[$key] ??= ['labels' => 0, 'purchased_label_cents' => 0];
                $serviceTotals[$key]['labels']++;
                $serviceTotals[$key]['purchased_label_cents'] += $cost;
                if (data_get($shipment->parcel, '_native.refund_state') === 'refunded') {
                    $refundCost += $cost;
                }
            }
            $customerCharges = (int) WebsiteOrder::query()->forTenant($tenant)->where('currency', 'usd')
                ->whereIn('id', array_keys($orderIds))->sum('shipping_cents');
            $nonproduction = WebsiteShipment::query()->forTenant($tenant)->where('provider', 'easypost')->where('parcel->_native->version', 1)
                ->where('currency', 'usd')->where('purchased_at', '>=', $start)->where('purchased_at', '<', $end)
                ->where(fn ($query) => $query->whereNull('parcel->_native->mode')->orWhere('parcel->_native->mode', '!=', 'production'))->count();
            $freight = ['recorded_bookings' => 0, 'final_invoice_cents' => 0, 'missing_final_invoices' => 0];
            foreach (WebsiteOrder::query()->forTenant($tenant)->whereIn('source', ['native', 'staff_quote', 'staff_draft_invoice'])->cursor() as $order) {
                $plan = data_get($order->service_request, 'freight');
                if (! is_array($plan)) {
                    continue;
                }
                $booked = data_get($plan, 'booking.recorded_at');
                if (! $booked || CarbonImmutable::parse($booked)->lt($start) || CarbonImmutable::parse($booked)->gte($end)) {
                    continue;
                }
                $freight['recorded_bookings']++;
                if (isset($plan['invoice']['amount_cents'])) {
                    $freight['final_invoice_cents'] += (int) $plan['invoice']['amount_cents'];
                } else {
                    $freight['missing_final_invoices']++;
                }
            }
            $rows[] = ['tenant_id' => $tenant->id, 'slug' => $tenant->slug, 'provider_connections' => $accounts,
                'sites' => $sites->map(fn ($site) => ['site_id' => $site->id, 'readiness' => $health->forSite($site)])->all(),
                'native_parcel' => ['purchased_labels' => $labels, 'test_or_unknown_mode_labels_excluded' => $nonproduction, 'labels_missing_cost' => $unknownCost, 'purchased_label_cents' => $labelCost,
                    'provider_confirmed_refund_label_cents' => $refundCost, 'associated_order_count' => count($orderIds),
                    'associated_original_customer_shipping_cents' => $customerCharges,
                    'gross_label_cost_minus_original_shipping_charge_cents' => $labelCost - $customerCharges, 'by_service' => $serviceTotals],
                'manual_freight' => $freight];
        }
        $this->line(json_encode(['generated_at' => now()->toIso8601String(), 'release_id' => config('app.release_id'),
            'timezone' => config('app.timezone'), 'period' => $dates, 'currency' => 'USD', 'tenants' => $rows,
            'limitations' => ['Native Website records only; Shopify and legacy orders require separate source exports.',
                'Only declared production-mode parcel labels enter cost totals. Test and unknown modes are counted separately and excluded.',
                'Purchased labels use purchase dates; associated customer charges use that same distinct order cohort, not period sales.',
                'Original customer charges are not net of refunds. The gross difference is not profit or a fully reconciled shipping subsidy.',
                'Carrier adjustments, actual refund credits, API subscriptions, insurance, materials, handling, and unrecorded freight invoices need provider/accounting reconciliation.',
                'Readiness is saved local configuration and observed worker evidence; it does not verify account funding, physical acceptance, or negotiated-rate eligibility.']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
