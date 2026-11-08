<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderEvent;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Staff-reviewed domestic LTL evidence. Never books freight or changes payment/fulfillment truth. */
class WebsiteFreightService
{
    public const CLASSES = ['50', '55', '60', '65', '70', '77.5', '85', '92.5', '100', '110', '125', '150', '175', '200', '250', '300', '400', '500'];

    public function authorize(WebsiteOrder $order, User $actor): TenantSite
    {
        $order->assertNotPosSimulation();
        $site = TenantSite::query()->forTenantId($order->tenant_id)->findOrFail($order->tenant_site_id);
        app(WebsiteCatalogService::class)->assertEditor($site, $actor);
        app(WebsiteShippingAccessService::class)->assertCanManage($site->tenant, $actor);
        abort_unless(app(TenantFinancialAccess::class)->allows($actor, $site->tenant), 403);
        abort_unless(in_array($order->source, ['native', 'staff_quote', 'staff_draft_invoice'], true)
            && $order->lines()->where('product_type', 'physical')->exists(), 422, 'Freight planning requires a native Website order with physical items. Connected Shopify and legacy orders retain their own fulfillment workflow.');

        return $site;
    }

    public function save(WebsiteOrder $order, array $input, User $actor): void
    {
        $this->authorize($order, $actor);
        $base = ['action' => ['required', Rule::in(['specification', 'quote', 'approve', 'booking', 'invoice', 'cancellation'])],
            'version' => ['required', 'integer', 'min:0']];
        $action = Validator::make($input, $base)->validate()['action'];
        $rules = match ($action) {
            'specification' => [
                'origin_id' => ['required', 'integer', 'min:1'], 'pickup_phone' => ['required', 'string', 'max:40'],
                'pickup_name' => ['nullable', 'string', 'max:190'], 'pickup_company' => ['nullable', 'string', 'max:190'],
                'destination_company' => ['nullable', 'string', 'max:190'],
                'pickup_location_type' => ['nullable', Rule::in(['commercial', 'residential', 'limited_access'])],
                'pickup_residential' => ['nullable', 'boolean'],
                'destination_name' => ['required', 'string', 'max:190'], 'destination_street1' => ['required', 'string', 'max:190'],
                'destination_street2' => ['nullable', 'string', 'max:190'], 'destination_city' => ['required', 'string', 'max:120'],
                'destination_state' => ['required', 'string', 'size:2'], 'destination_zip' => ['required', 'string', 'max:10'],
                'delivery_phone' => ['required', 'string', 'max:40'], 'residential' => ['required', 'boolean'],
                'pickup_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
                'pallets' => ['required', 'integer', 'between:1,50'],
                'length_inches' => ['required', 'numeric', 'between:1,240'], 'width_inches' => ['required', 'numeric', 'between:1,120'],
                'height_inches' => ['required', 'numeric', 'between:1,144'], 'weight_pounds' => ['required', 'numeric', 'between:1,40000'],
                'description' => ['required', 'string', 'max:1000'], 'freight_class' => ['required', Rule::in(self::CLASSES)],
                'nmfc' => ['nullable', 'string', 'max:80'], 'accessorials' => ['nullable', 'array', 'max:6'],
                'accessorials.*' => ['distinct', Rule::in(['pickup_liftgate', 'delivery_liftgate', 'appointment', 'limited_access', 'inside_delivery', 'non_stackable'])],
                'reviewed' => ['required', 'accepted'],
            ],
            'quote' => [
                'provider' => ['required', 'string', 'max:190'], 'carrier' => ['required', 'string', 'max:190'],
                'reference' => ['required', 'string', 'max:190'], 'amount_cents' => ['required', 'integer', 'between:1,100000000'],
                'expires_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:now'],
                'transit_days' => ['nullable', 'integer', 'between:1,180'], 'evidence_reference' => ['required', 'string', 'max:500'],
                'all_in_confirmed' => ['required', 'accepted'],
            ],
            'approve' => ['quote_id' => ['required', 'uuid'], 'amount_cents' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']],
            'booking' => ['reference' => ['required', 'string', 'max:190'], 'bol_reference' => ['required', 'string', 'max:190'],
                'pro_number' => ['nullable', 'string', 'max:190'], 'evidence_reference' => ['required', 'string', 'max:500'], 'confirmed' => ['required', 'accepted']],
            'invoice' => ['reference' => ['required', 'string', 'max:190'], 'amount_cents' => ['required', 'integer', 'between:0,100000000'],
                'adjustment_reason' => ['required', 'string', 'max:1000'], 'evidence_reference' => ['required', 'string', 'max:500']],
            'cancellation' => ['reference' => ['required', 'string', 'max:190'], 'evidence_reference' => ['required', 'string', 'max:500'], 'confirmed' => ['required', 'accepted']],
        };
        $data = Validator::make($input, $base + $rules)->validate();
        DB::transaction(function () use ($order, $data, $action, $actor): void {
            TenantSite::query()->forTenantId($order->tenant_id)->lockForUpdate()->findOrFail($order->tenant_site_id);
            $order = WebsiteOrder::query()->forTenantId($order->tenant_id)->lockForUpdate()->findOrFail($order->id);
            $this->authorize($order, $actor);
            $request = (array) $order->service_request;
            $before = (array) ($request['freight'] ?? []);
            abort_unless(($before['version'] ?? 0) === (int) $data['version'], 409, 'Freight details changed. Reload before saving.');
            $freight = $before;
            abort_if(! empty($before['api_attempt']) && data_get($before, 'api_attempt.state') !== 'rejected'
                && $action !== 'invoice', 409, 'Use the original API freight operation and its status/cancellation controls. Do not create another booking or replace its evidence.');
            if ($action === 'specification') {
                abort_if(! empty($before['booking']), 409, 'Keep the booked freight evidence. Resolve the existing booking before planning a separate shipment.');
                $origin = \App\Models\WebsiteFulfillmentLocation::query()->forTenantId($order->tenant_id)
                    ->where('tenant_site_id', $order->tenant_site_id)->where('active', true)->findOrFail($data['origin_id']);
                $destination = app(WebsiteShippingPlanService::class)->address([
                    'name' => $data['destination_name'], 'street1' => $data['destination_street1'], 'street2' => $data['destination_street2'] ?? '',
                    'city' => $data['destination_city'], 'state' => $data['destination_state'], 'zip' => $data['destination_zip'],
                    'phone' => $data['delivery_phone'], 'country' => 'US', 'residential' => $data['residential'],
                ]);
                $freight = ['specification' => array_diff_key($data, array_flip(['action', 'version'])) + [
                    'origin' => app(WebsiteShippingPlanService::class)->address((array) $origin->address, 'origin'), 'destination' => $destination,
                ], 'quotes' => [], 'approved' => null];
            } else {
                abort_unless(! empty($freight['specification']), 422, 'Save reviewed freight details first.');
            }
            if ($action === 'quote') {
                abort_if(! empty($freight['booking']), 409, 'This freight is already booked. Record final billing separately.');
                abort_if(count($freight['quotes'] ?? []) >= 20, 422, 'Review the existing quotes or revise the specification before adding more.');
                $freight['quotes'][] = array_diff_key($data, array_flip(['action', 'version'])) + ['id' => (string) Str::uuid(), 'recorded_at' => now()->toIso8601String()];
            }
            if ($action === 'approve') {
                abort_if(! empty($freight['booking']), 409, 'This freight is already booked.');
                $quote = collect($freight['quotes'] ?? [])->firstWhere('id', $data['quote_id']);
                abort_unless($quote && \Carbon\CarbonImmutable::parse($quote['expires_at'])->isFuture()
                    && (int) $quote['amount_cents'] === (int) $data['amount_cents'], 422, 'Select a current quote and confirm its exact total cost.');
                $freight['approved'] = ['quote_id' => $quote['id'], 'amount_cents' => (int) $quote['amount_cents'], 'by' => $actor->id, 'at' => now()->toIso8601String()];
            }
            if ($action === 'booking') {
                abort_if(! empty($freight['booking']), 409, 'Booking evidence is already recorded. Check the existing carrier booking before making another.');
                $quote = collect($freight['quotes'] ?? [])->firstWhere('id', $freight['approved']['quote_id'] ?? null);
                abort_unless($quote && \Carbon\CarbonImmutable::parse($quote['expires_at'])->isFuture(), 422, 'Approve a current freight quote before recording its booking.');
                $freight['booking'] = array_diff_key($data, array_flip(['action', 'version'])) + ['recorded_at' => now()->toIso8601String(), 'by' => $actor->id];
            }
            if (in_array($action, ['invoice', 'cancellation'], true)) {
                abort_unless(! empty($freight['booking']), 422, 'Record the existing carrier booking first.');
                $freight[$action] = array_diff_key($data, array_flip(['action', 'version'])) + ['recorded_at' => now()->toIso8601String(), 'by' => $actor->id];
            }
            $freight['version'] = ($before['version'] ?? 0) + 1;
            $request['freight'] = $freight;
            $order->update(['service_request' => $request]);
            WebsiteOrderEvent::query()->create(['tenant_id' => $order->tenant_id, 'website_order_id' => $order->id, 'user_id' => $actor->id,
                'event_type' => 'freight_'.$action.'_recorded', 'visibility' => 'staff', 'message' => 'Staff recorded freight '.$action.' evidence.',
                'data' => ['before' => $before, 'after' => $freight]]);
        });
    }
}
