<?php

namespace App\Services\ManagedWebsite;

use App\Jobs\ProcessWebsiteShippingEvent;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteFulfillment;
use App\Models\WebsiteFulfillmentLine;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderEvent;
use App\Models\WebsiteShipment;
use App\Models\WebsiteShipmentEvent;
use App\Models\WebsiteShippingRateQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Single-parcel native postage. Ambiguous purchases are recovered with GETs only. */
class WebsiteShipmentLifecycleService
{
    public function purchase(WebsiteOrder $order, WebsiteShippingRateQuote $quote, User $actor): WebsiteShipment
    {
        $this->authorize($actor, $order->tenant_id);
        $shipment = $this->orderLock($order, function (WebsiteOrder $order) use ($quote, $actor): WebsiteShipment {
            app(WebsitePaymentService::class)->assertFulfillable($order);
            abort_unless($order->fulfillment_method === 'ship', 422, 'This is not a shipping order.');
            $existing = $order->shipments()->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                $shipment = $existing->first();
                abort_unless($existing->count() === 1 && $shipment->provider === 'easypost'
                    && data_get($shipment->parcel, '_native.version') === 1 && ! data_get($shipment->parcel, '_native.retired'), 422, 'Use the existing shipment recovery or replacement action.');

                return $shipment;
            }
            abort_unless(app(WebsiteCommerceShippingService::class)->enabledFor(Tenant::query()->findOrFail($order->tenant_id)), 423, 'New native shipping is paused.');
            $plans = app(WebsiteShippingPlanService::class);
            $plans->assertOrderQuote($order, $quote);
            $connection = $plans->connection($order->tenant_id);
            abort_unless(filled($connection->external_account_secret), 423, 'Configure this carrier account\'s signed webhook before buying postage.');
            $fulfillment = WebsiteFulfillment::query()->create(['tenant_id' => $order->tenant_id, 'website_order_id' => $order->id,
                'status' => 'pending', 'method' => 'ship', 'fulfilled_by_user_id' => $actor->id, 'note' => 'Native parcel preparation. Label purchase does not confirm handoff.']);
            foreach ($order->lines()->get() as $line) {
                WebsiteFulfillmentLine::query()->create(['tenant_id' => $order->tenant_id, 'website_fulfillment_id' => $fulfillment->id,
                    'website_order_line_id' => $line->id, 'quantity' => $line->quantity]);
            }
            $native = ['version' => 1, 'operation_id' => (string) Str::uuid(), 'connection_id' => $connection->id,
                'account_fingerprint' => $plans->accountFingerprint($connection), 'mode' => data_get($quote->parcel, '_shipping_plan.mode'),
                'origin_fingerprint' => data_get($quote->parcel, '_shipping_plan.origin_fingerprint'),
                'carrier_account_id' => data_get($quote->parcel, '_carrier_account_id'),
                'destination_fingerprint' => $plans->addressFingerprint($quote->destination), 'buy_state' => 'prepared',
                'approved_cost_cents' => $quote->amount_cents, 'refund_state' => null, 'retired' => false];
            $shipment = WebsiteShipment::query()->create(['tenant_id' => $order->tenant_id, 'website_order_id' => $order->id,
                'website_fulfillment_id' => $fulfillment->id, 'website_fulfillment_location_id' => $quote->website_fulfillment_location_id,
                'provider' => 'easypost', 'provider_shipment_id' => $quote->provider_shipment_id, 'provider_rate_id' => $quote->provider_rate_id,
                'carrier' => $quote->carrier, 'service' => $quote->service, 'currency' => 'usd', 'status' => 'purchase_prepared',
                'destination' => $quote->destination, 'parcel' => $plans->parcel($quote) + ['_native' => $native]]);
            $this->event($shipment, 'purchase_prepared', 'Postage attempt saved before contacting the carrier.', ['user_id' => $actor->id]);

            return $shipment;
        });

        return $this->buyPrepared($shipment);
    }

    private function buyPrepared(WebsiteShipment $shipment): WebsiteShipment
    {
        $shipment->refresh();
        if (data_get($shipment->parcel, '_native.buy_state') !== 'prepared') {
            return $this->reconcile($shipment);
        }
        // GETs cannot buy postage. Keep the approved attempt resumable when
        // this preflight is unavailable; concurrent preflights are harmless.
        $client = app(WebsiteEasyPostService::class);
        $current = $client->retrieve($shipment);
        $this->assertProvider($shipment, $current);
        if (! empty($current['postage_label'])) {
            return $this->accept($shipment, $current);
        }
        $this->checkReplacementOrigin($shipment);
        $claimed = $this->locked($shipment, function ($order, $shipment): bool {
            app(WebsitePaymentService::class)->assertFulfillable($order);
            if (data_get($shipment->parcel, '_native.buy_state') !== 'prepared' || data_get($shipment->parcel, '_native.retired')) {
                return false;
            }
            abort_unless(app(WebsiteCommerceShippingService::class)->enabledFor(Tenant::query()->findOrFail($order->tenant_id))
                && app(WebsiteEasyPostService::class)->connection($shipment)->isConnected(), 423, 'New postage is paused for this carrier account.');
            $this->assertReplacementOrigin($shipment);
            $this->metadata($shipment, ['buy_state' => 'purchasing', 'buy_started_at' => now()->toIso8601String()]);
            $shipment->update(['status' => 'purchasing']);

            return true;
        });
        if (! $claimed) {
            return $this->reconcile($shipment);
        }
        try {
            // Only this durable claim may send a buy. A crash or lost response
            // leaves purchasing/unknown and future requests perform GET only.
            $response = $client->client($shipment)->post('/shipments/'.$shipment->provider_shipment_id.'/buy', ['rate' => ['id' => $shipment->provider_rate_id]]);
            if ($response->successful() && is_array($response->json()) && ! empty($response->json('postage_label'))) {
                return $this->accept($shipment, $response->json());
            }
            $this->unknown($shipment);
            if ($response->json('error.code') === 'SHIPMENT.POSTAGE.EXISTS') {
                return $this->reconcile($shipment);
            }
        } catch (\Throwable $exception) {
            $this->unknown($shipment);
            throw $exception;
        }
        throw ValidationException::withMessages(['shipping' => 'Postage is unconfirmed. Use Check shipment to recover this same attempt before buying any replacement.']);
    }

    public function resumePurchase(WebsiteShipment $shipment, User $actor): WebsiteShipment
    {
        $this->authorize($actor, $shipment->tenant_id);
        $shipment->refresh();
        app(WebsiteEasyPostService::class)->connection($shipment);
        abort_unless(data_get($shipment->parcel, '_native.buy_state') === 'prepared'
            && $shipment->provider_rate_id && is_int(data_get($shipment->parcel, '_native.approved_cost_cents'))
            && ! data_get($shipment->parcel, '_native.retired'), 422, 'Only an approved, unsubmitted postage attempt can resume. Use Check shipment for an uncertain purchase.');

        return $this->buyPrepared($shipment);
    }

    public function reconcile(WebsiteShipment $shipment): WebsiteShipment
    {
        $data = app(WebsiteEasyPostService::class)->retrieve($shipment);
        $this->assertProvider($shipment, $data);
        if (! empty($data['postage_label'])) {
            $shipment = $this->accept($shipment, $data);
            $trackerId = data_get($data, 'tracker.id');
            if ($trackerId && preg_match('/^trk_[A-Za-z0-9_]+$/', (string) $trackerId)) {
                $response = app(WebsiteEasyPostService::class)->client($shipment)->get('/trackers/'.$trackerId);
                abort_unless($response->successful() && $response->json('id') === $trackerId, 502, 'Carrier tracking verification is unavailable.');
                $this->locked($shipment, fn ($order, $shipment) => $this->tracking($order, $shipment, $response->json()));
            }

            return $shipment->fresh();
        }
        if (! in_array(data_get($shipment->fresh()->parcel, '_native.buy_state'), ['prepared', 'approval_required'], true)) {
            $this->unknown($shipment);
        }

        return $shipment->fresh();
    }

    private function unknown(WebsiteShipment $shipment): void
    {
        $this->locked($shipment, function ($order, $shipment): void {
            if (! in_array(data_get($shipment->parcel, '_native.buy_state'), ['purchased', 'review'], true)) {
                $this->metadata($shipment, ['buy_state' => 'unknown']);
                $shipment->update(['status' => 'purchase_unknown']);
            }
        });
    }

    private function assertProvider(WebsiteShipment $shipment, array $data): void
    {
        $plans = app(WebsiteShippingPlanService::class);
        $valid = ($data['id'] ?? '') === $shipment->provider_shipment_id && ($data['object'] ?? '') === 'Shipment'
            && ($data['mode'] ?? '') === data_get($shipment->parcel, '_native.mode');
        foreach (['length', 'width', 'height', 'weight'] as $field) {
            $value = data_get($data, 'parcel.'.$field);
            $valid = $valid && is_numeric($value) && (float) $value === (float) data_get($shipment->parcel, $field);
        }
        try {
            $valid = $valid && hash_equals((string) data_get($shipment->parcel, '_native.destination_fingerprint'), $plans->addressFingerprint((array) ($data['to_address'] ?? [])))
                && hash_equals((string) data_get($shipment->parcel, '_native.origin_fingerprint'), $plans->addressFingerprint((array) ($data['from_address'] ?? [])));
        } catch (\Throwable $exception) {
            $valid = false;
        }
        abort_unless($valid, 502, 'Carrier shipment details do not match the accepted order parcel. Staff review is required.');
    }

    private function accept(WebsiteShipment $shipment, array $data): WebsiteShipment
    {
        $this->assertProvider($shipment, $data);
        $rate = (array) ($data['selected_rate'] ?? []);
        $label = (array) ($data['postage_label'] ?? []);
        abort_unless(preg_match('/^pl_[A-Za-z0-9_]+$/', (string) ($label['id'] ?? ''))
            && ($rate['id'] ?? '') === $shipment->provider_rate_id && ($rate['carrier'] ?? '') === $shipment->carrier
            && ($rate['service'] ?? '') === $shipment->service && strtolower((string) ($rate['currency'] ?? '')) === 'usd'
            && (! data_get($shipment->parcel, '_native.carrier_account_id') || ($rate['carrier_account_id'] ?? '') === data_get($shipment->parcel, '_native.carrier_account_id'))
            && app(WebsiteEasyPostService::class)->cents($rate['rate'] ?? null) === data_get($shipment->parcel, '_native.approved_cost_cents'), 502, 'Purchased postage does not match the approved rate. Review this shipment.');

        return $this->locked($shipment, function ($order, $shipment) use ($data, $label): WebsiteShipment {
            $wasPurchased = data_get($shipment->parcel, '_native.buy_state') === 'purchased';
            $previousLabel = data_get($this->providerEvidence($shipment), 'postage_label.id');
            abort_if($previousLabel && $previousLabel !== $label['id'], 409, 'Carrier label identity changed. Review this shipment.');
            $previousTracker = data_get($shipment->parcel, '_native.tracker_id');
            abort_if($previousTracker && $previousTracker !== data_get($data, 'tracker.id'), 409, 'Carrier tracker identity changed. Review this shipment.');
            $this->metadata($shipment, ['buy_state' => 'purchased', 'tracker_id' => data_get($data, 'tracker.id')]);
            $shipment->update(['label_cost_cents' => data_get($shipment->parcel, '_native.approved_cost_cents'), 'purchased_at' => $shipment->purchased_at ?? now(),
                'tracking_number' => $data['tracking_code'] ?? $shipment->tracking_number,
                'tracking_url' => $this->trackingUrl(data_get($data, 'tracker.public_url')) ?? $shipment->tracking_url,
                'status' => $wasPurchased ? $shipment->status : 'label_purchased']);
            // URLs, addresses and provider bodies stay in encrypted evidence.
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $this->event($shipment, 'provider_snapshot', 'Carrier shipment verified.', ['shipment' => $data], 'snapshot-'.$hash);
            if (! $wasPurchased) {
                $this->event($shipment, 'label_purchased', 'Postage confirmed. Packing, printing, and handoff remain separate actions.');
                if (data_get($shipment->parcel, '_native.replaces_id')) {
                    $this->notify($order, 'label-'.$shipment->id, 'label_updated', $shipment);
                }
            }
            $refund = $data['refund_status'] ?? null;
            $before = data_get($shipment->parcel, '_native.refund_state');
            if (in_array($refund, ['submitted', 'refunded'], true)
                || ($before && in_array($refund, ['rejected', 'not_applicable'], true))) {
                if ($before !== 'refunded') {
                    $this->metadata($shipment, ['refund_state' => $refund, 'retired' => true]);
                    if ($refund === 'refunded') {
                        $shipment->update(['voided_at' => $shipment->voided_at ?? now()]);
                    }
                    if ($before !== $refund) {
                        $this->event($shipment, 'postage_refund_'.$refund, 'Carrier postage refund is '.$refund.'.');
                    }
                }
            }
            $tracker = (array) ($data['tracker'] ?? []);
            if ($tracker !== []) {
                $this->tracking($order, $shipment, $tracker);
            }

            return $shipment->fresh();
        });
    }

    public function providerEvidence(WebsiteShipment $shipment): array
    {
        return (array) data_get(WebsiteShipmentEvent::query()->forTenantId($shipment->tenant_id)->where('website_shipment_id', $shipment->id)
            ->where('event_type', 'provider_snapshot')->latest('id')->first()?->payload, 'shipment', []);
    }

    public function pack(WebsiteShipment $shipment, User $actor): WebsiteShipment
    {
        $this->authorize($actor, $shipment->tenant_id);
        $this->checkReplacementOrigin($shipment);
        $shipment = $this->reconcile($shipment);

        return $this->locked($shipment, function ($order, $shipment) use ($actor): WebsiteShipment {
            $this->usable($order, $shipment);
            if (! data_get($shipment->parcel, '_native.packed_at')) {
                abort_if(data_get($shipment->parcel, '_native.handed_over_at') || data_get($shipment->parcel, '_native.carrier_accepted_at'), 422, 'This parcel has already been handed over or accepted.');
                $this->metadata($shipment, ['packed_at' => now()->toIso8601String()]);
                WebsiteFulfillment::query()->forTenantId($order->tenant_id)->whereKey($shipment->website_fulfillment_id)->update(['status' => 'packed']);
                $order->update(['fulfillment_status' => 'packed']);
                $this->event($shipment, 'packed', 'Staff confirmed the parcel is packed.', ['user_id' => $actor->id]);
                $this->notify($order, 'packing-'.$shipment->id, 'preparing', $shipment);
            }

            return $shipment->fresh();
        });
    }

    public function handoff(WebsiteShipment $shipment, User $actor): WebsiteShipment
    {
        $this->authorize($actor, $shipment->tenant_id);
        $this->checkReplacementOrigin($shipment);
        $shipment = $this->reconcile($shipment);

        return $this->locked($shipment, function ($order, $shipment) use ($actor): WebsiteShipment {
            $this->usable($order, $shipment);
            abort_unless(data_get($shipment->parcel, '_native.packed_at'), 422, 'Confirm packing before carrier handoff.');
            if (! data_get($shipment->parcel, '_native.handed_over_at')) {
                $this->metadata($shipment, ['handed_over_at' => now()->toIso8601String()]);
                $this->fulfill($order, $shipment);
                $this->event($shipment, 'handed_over', 'Staff confirmed carrier handoff.', ['user_id' => $actor->id]);
                $this->notify($order, 'shipped-'.$shipment->id, 'shipped', $shipment);
            }

            return $shipment->fresh();
        });
    }

    public function refund(WebsiteShipment $shipment, User $actor): WebsiteShipment
    {
        $this->authorize($actor, $shipment->tenant_id);
        // Retrieve first: staff cannot request an unused-label refund against a
        // known accepted/delivered parcel using stale local data.
        $shipment = $this->reconcile($shipment);
        $send = $this->locked($shipment, function ($order, $shipment) use ($actor): bool {
            abort_unless(data_get($shipment->parcel, '_native.buy_state') === 'purchased', 422, 'Resolve the postage purchase first.');
            abort_if(data_get($shipment->parcel, '_native.handed_over_at') || data_get($shipment->parcel, '_native.carrier_accepted_at'), 422, 'This parcel has already been handed over or accepted.');
            abort_if($shipment->purchased_at?->lt(now()->subDays(30)), 422, 'This USPS label needs provider review outside the refund window.');
            if (data_get($shipment->parcel, '_native.refund_state')) {
                return false;
            }
            $this->metadata($shipment, ['refund_state' => 'requesting', 'retired' => true]);
            $this->event($shipment, 'postage_refund_requested', 'Unused-label refund requested. Approval is pending; do not use this label.', ['user_id' => $actor->id]);

            return true;
        });
        if ($send) {
            try {
                $response = app(WebsiteEasyPostService::class)->client($shipment)->post('/shipments/'.$shipment->provider_shipment_id.'/refund');
                if ($response->successful() && is_array($response->json()) && ! empty($response->json('postage_label'))) {
                    return $this->accept($shipment, $response->json());
                }
            } catch (\Throwable $exception) {
                $this->locked($shipment, fn ($order, $shipment) => $this->metadata($shipment, ['refund_state' => 'unknown']));
                throw $exception;
            }
            $this->locked($shipment, fn ($order, $shipment) => $this->metadata($shipment, ['refund_state' => 'unknown']));
        }

        return $this->reconcile($shipment);
    }

    /** A new rate is preparation only; approval is a separate money action. */
    public function prepareReplacement(WebsiteShipment $original, User $actor, bool $confirmedUnused = false): WebsiteShipment
    {
        $this->authorize($actor, $original->tenant_id);
        abort_unless($confirmedUnused, 422, 'Confirm the original label is unused and will not be used.');
        $original = $this->reconcile($original);
        $replacement = $this->locked($original, function ($order, $original) use ($actor): WebsiteShipment {
            app(WebsitePaymentService::class)->assertFulfillable($order);
            abort_unless(app(WebsiteCommerceShippingService::class)->enabledFor(Tenant::query()->findOrFail($order->tenant_id)), 423, 'New postage is paused.');
            abort_unless(data_get($original->parcel, '_native.retired') && data_get($original->parcel, '_native.refund_state'), 422, 'Request the unused original label refund first.');
            abort_if(data_get($original->parcel, '_native.handed_over_at') || data_get($original->parcel, '_native.carrier_accepted_at'), 422, 'An accepted parcel cannot be replaced here.');
            if ($id = data_get($original->parcel, '_native.replacement_id')) {
                return WebsiteShipment::query()->forTenantId($order->tenant_id)->where('website_order_id', $order->id)->findOrFail($id);
            }
            $site = TenantSite::query()->forTenantId($order->tenant_id)->findOrFail($order->tenant_site_id);
            abort_unless(in_array($original->carrier.':'.$original->service, (array) data_get($site->settings, 'shipping.services', []), true), 423, 'The original service is no longer approved. Review the shipping plan.');
            $source = $this->providerEvidence($original);
            $native = data_get($original->parcel, '_native');
            $native = array_intersect_key($native, array_flip(['version', 'connection_id', 'account_fingerprint', 'mode', 'origin_fingerprint', 'destination_fingerprint', 'carrier_account_id']));
            $native += ['operation_id' => (string) Str::uuid(), 'buy_state' => 'replacement_preparing', 'refund_state' => null,
                'retired' => false, 'replaces_id' => $original->id, 'approved_cost_cents' => null];
            $parcel = array_intersect_key($original->parcel, array_flip(['length', 'width', 'height', 'weight']));
            $shipment = WebsiteShipment::query()->create(['tenant_id' => $order->tenant_id, 'website_order_id' => $order->id,
                'website_fulfillment_id' => $original->website_fulfillment_id, 'website_fulfillment_location_id' => $original->website_fulfillment_location_id,
                'provider' => 'easypost', 'carrier' => $original->carrier, 'service' => $original->service, 'currency' => 'usd',
                'status' => 'replacement_preparing', 'destination' => $original->destination, 'parcel' => $parcel + ['_native' => $native]]);
            $request = ['reference' => 'website-'.$native['operation_id'], 'to_address' => $source['to_address'], 'from_address' => $source['from_address'],
                'parcel' => $parcel, 'options' => ['label_format' => 'PNG', 'label_size' => '4x6']];
            if ($account = $native['carrier_account_id'] ?? null) {
                $request['carrier_accounts'] = [$account];
            }
            $this->event($shipment, 'replacement_prepared', 'Replacement rate requested. No replacement postage has been purchased; the original refund may still be pending.', ['request' => $request, 'user_id' => $actor->id]);
            $this->metadata($original, ['replacement_id' => $shipment->id]);

            return $shipment;
        });
        if ($replacement->provider_shipment_id || data_get($replacement->parcel, '_native.buy_state') !== 'replacement_preparing') {
            return $replacement;
        }
        // A reference is not unique at EasyPost. Claim creation once, and never
        // automatically repeat it after an ambiguous response.
        $claimed = $this->locked($replacement, function ($order, $shipment): bool {
            if (data_get($shipment->parcel, '_native.buy_state') !== 'replacement_preparing') {
                return false;
            }
            $this->metadata($shipment, ['buy_state' => 'replacement_creating']);

            return true;
        });
        if (! $claimed) {
            return $replacement->fresh();
        }
        $request = data_get($replacement->events()->where('event_type', 'replacement_prepared')->latest('id')->first()?->payload, 'request');
        try {
            $response = app(WebsiteEasyPostService::class)->client($replacement)->post('/shipments', ['shipment' => $request]);
            abort_unless($response->successful() && preg_match('/^shp_[A-Za-z0-9_]+$/', (string) $response->json('id')), 502, 'Replacement rate preparation is unconfirmed. Staff review is required.');
            $data = $response->json();
            $replacement = $this->locked($replacement, function ($order, $shipment) use ($data): WebsiteShipment {
                $shipment->update(['provider_shipment_id' => $data['id']]);
                $this->assertProvider($shipment, $data);
                abort_if(! empty($data['postage_label']), 502, 'Unexpected purchased replacement needs review.');
                $rates = [];
                foreach ((array) ($data['rates'] ?? []) as $rate) {
                    if (($rate['carrier'] ?? '') === $shipment->carrier && ($rate['service'] ?? '') === $shipment->service
                        && strtolower((string) ($rate['currency'] ?? '')) === 'usd' && ($rate['shipment_id'] ?? '') === $data['id']
                        && ($rate['mode'] ?? $data['mode']) === data_get($shipment->parcel, '_native.mode')
                        && (! data_get($shipment->parcel, '_native.carrier_account_id') || ($rate['carrier_account_id'] ?? '') === data_get($shipment->parcel, '_native.carrier_account_id'))
                        && preg_match('/^rate_[A-Za-z0-9_]+$/', (string) ($rate['id'] ?? ''))) {
                        $rates[] = ['id' => $rate['id'], 'carrier' => $rate['carrier'], 'service' => $rate['service'], 'amount_cents' => app(WebsiteEasyPostService::class)->cents($rate['rate'] ?? null)];
                    }
                }
                abort_unless($rates !== [], 422, 'No approved replacement rate is available.');
                $this->metadata($shipment, ['buy_state' => 'approval_required', 'replacement_rates' => $rates]);
                $shipment->update(['status' => 'approval_required']);
                $this->event($shipment, 'provider_snapshot', 'Replacement rates verified; postage approval required.', ['shipment' => $data]);

                return $shipment;
            });
        } catch (\Throwable $exception) {
            $this->locked($replacement, function ($order, $shipment): void {
                $this->metadata($shipment, ['buy_state' => 'replacement_unknown']);
                $shipment->update(['status' => 'replacement_unknown']);
            });
            throw $exception;
        }

        return $replacement->fresh();
    }

    public function approveReplacement(WebsiteShipment $shipment, string $rateId, int $costCents, User $actor): WebsiteShipment
    {
        $this->authorize($actor, $shipment->tenant_id);
        $this->checkReplacementOrigin($shipment);
        $shipment = $this->locked($shipment, function ($order, $shipment) use ($rateId, $costCents, $actor): WebsiteShipment {
            app(WebsitePaymentService::class)->assertFulfillable($order);
            abort_unless(app(WebsiteCommerceShippingService::class)->enabledFor(Tenant::query()->findOrFail($order->tenant_id)), 423, 'New postage is paused.');
            abort_unless(data_get($shipment->parcel, '_native.replaces_id') && ! data_get($shipment->parcel, '_native.retired'), 422);
            $this->assertReplacementOrigin($shipment);
            $state = data_get($shipment->parcel, '_native.buy_state');
            if ($state !== 'approval_required') {
                abort_unless($shipment->provider_rate_id === $rateId && data_get($shipment->parcel, '_native.approved_cost_cents') === $costCents, 409, 'Replacement approval changed.');

                return $shipment;
            }
            $rate = collect((array) data_get($shipment->parcel, '_native.replacement_rates'))->first(fn ($rate) => $rate['id'] === $rateId && $rate['amount_cents'] === $costCents);
            abort_unless($rate, 422, 'Approve the displayed replacement postage amount.');
            $this->metadata($shipment, ['buy_state' => 'prepared', 'approved_cost_cents' => $costCents]);
            $shipment->update(['provider_rate_id' => $rateId, 'status' => 'purchase_prepared']);
            $this->event($shipment, 'replacement_approved', 'Staff approved the separate replacement postage charge.', ['amount_cents' => $costCents, 'user_id' => $actor->id]);

            return $shipment;
        });

        return $this->buyPrepared($shipment);
    }

    private function checkReplacementOrigin(WebsiteShipment $shipment): void
    {
        if ($id = data_get($shipment->parcel, '_native.replaces_id')) {
            $original = WebsiteShipment::query()->forTenantId($shipment->tenant_id)->where('website_order_id', $shipment->website_order_id)->findOrFail($id);
            $this->reconcile($original);
            $this->assertReplacementOrigin($shipment);
        }
    }

    private function assertReplacementOrigin(WebsiteShipment $shipment): void
    {
        if ($id = data_get($shipment->parcel, '_native.replaces_id')) {
            $original = WebsiteShipment::query()->forTenantId($shipment->tenant_id)->where('website_order_id', $shipment->website_order_id)->findOrFail($id);
            abort_unless(data_get($original->parcel, '_native.retired') && data_get($original->parcel, '_native.refund_state')
                && ! data_get($original->parcel, '_native.handed_over_at') && ! data_get($original->parcel, '_native.carrier_accepted_at'), 422, 'The original parcel has been accepted or is no longer eligible. Do not buy a replacement.');
        }
    }

    private function usable(WebsiteOrder $order, WebsiteShipment $shipment): void
    {
        app(WebsitePaymentService::class)->assertFulfillable($order);
        app(WebsiteEasyPostService::class)->connection($shipment);
        $this->assertReplacementOrigin($shipment);
        abort_unless(data_get($shipment->parcel, '_native.buy_state') === 'purchased' && ! data_get($shipment->parcel, '_native.retired'), 422, 'This label is unresolved or retired.');
    }

    private function tracking(WebsiteOrder $order, WebsiteShipment $shipment, array $tracker): void
    {
        if (($tracker['id'] ?? '') !== data_get($shipment->parcel, '_native.tracker_id')
            || ! preg_match('/^trk_[A-Za-z0-9_]+$/', (string) ($tracker['id'] ?? ''))
            || ($tracker['object'] ?? '') !== 'Tracker' || ($tracker['shipment_id'] ?? '') !== $shipment->provider_shipment_id
            || ($tracker['mode'] ?? '') !== data_get($shipment->parcel, '_native.mode')
            || ($tracker['tracking_code'] ?? '') !== $shipment->tracking_number || ($tracker['carrier'] ?? '') !== $shipment->carrier) {
            return;
        }
        $status = $tracker['status'] ?? '';
        if (! in_array($status, ['unknown', 'pre_transit', 'in_transit', 'out_for_delivery', 'delivered', 'available_for_pickup', 'return_to_sender', 'failure', 'cancelled', 'error'], true)) {
            return;
        }
        try {
            $updated = \Carbon\CarbonImmutable::parse($tracker['updated_at'] ?? 'invalid')->utc();
            $previous = data_get($shipment->parcel, '_native.carrier_updated_at');
            $previous = is_numeric($previous) ? \Carbon\CarbonImmutable::createFromTimestamp($previous, 'UTC') : ($previous ? \Carbon\CarbonImmutable::parse($previous) : null);
        } catch (\Throwable $exception) {
            return;
        }
        if (($previous && $updated->lessThanOrEqualTo($previous)) || $updated->greaterThan(now()->addMinutes(5))) {
            return;
        }
        $updated = $updated->format('Y-m-d\TH:i:s.u\Z');
        if ($shipment->status === 'delivered' && $status !== 'delivered') {
            $this->metadata($shipment, ['carrier_updated_at' => $updated]);
            $this->event($shipment, 'tracking_correction_review', 'Carrier reported a change after delivery. Delivery remains recorded pending review.', ['status' => $status]);

            return;
        }
        if (data_get($shipment->parcel, '_native.carrier_accepted_at') && in_array($status, ['unknown', 'pre_transit', 'cancelled'], true)) {
            $this->metadata($shipment, ['carrier_updated_at' => $updated]);
            $this->event($shipment, 'tracking_correction_review', 'Carrier reported an earlier stage after acceptance. The accepted parcel remains recorded.', ['status' => $status]);

            return;
        }
        $this->metadata($shipment, ['carrier_updated_at' => $updated]);
        $shipment->update(['status' => $status === 'pre_transit' ? 'label_purchased' : $status,
            'tracking_url' => $this->trackingUrl($tracker['public_url'] ?? null) ?? $shipment->tracking_url]);
        $this->event($shipment, 'tracking_updated', 'Carrier tracking is '.$status.'.', ['tracker' => $tracker], 'tracker-'.$tracker['id'].'-'.hash('sha256', $updated));
        $accepted = in_array($status, ['in_transit', 'out_for_delivery', 'delivered', 'available_for_pickup', 'return_to_sender'], true);
        $newAcceptance = $accepted && ! data_get($shipment->parcel, '_native.carrier_accepted_at');
        if ($newAcceptance) {
            $this->metadata($shipment, ['carrier_accepted_at' => $updated]);
        }
        if (data_get($shipment->parcel, '_native.retired')) {
            return; // An old replacement label must not fulfill or notify the current parcel.
        }
        if ($newAcceptance) {
            $this->fulfill($order, $shipment);
            $this->notify($order, 'shipped-'.$shipment->id, 'shipped', $shipment);
        }
        if ($status === 'delivered') {
            $this->metadata($shipment, ['exception_active' => false]);
            if ($order->exception_status === 'shipping_exception') {
                $order->update(['exception_status' => 'none']);
            }
            $this->notify($order, 'delivered-'.$shipment->id, 'delivered', $shipment);
        } elseif (in_array($status, ['failure', 'return_to_sender', 'error'], true)) {
            $order->update(['exception_status' => 'shipping_exception']);
            if (! data_get($shipment->parcel, '_native.exception_active')) {
                $episode = (int) data_get($shipment->parcel, '_native.exception_episode', 0) + 1;
                $this->metadata($shipment, ['exception_active' => true, 'exception_episode' => $episode]);
                $this->notify($order, 'exception-'.$shipment->id.'-'.$episode, 'exception', $shipment);
            }
        } elseif (in_array($status, ['in_transit', 'out_for_delivery', 'available_for_pickup'], true) && $order->exception_status === 'shipping_exception') {
            $order->update(['exception_status' => 'none']);
            $this->metadata($shipment, ['exception_active' => false]);
        }
    }

    private function fulfill(WebsiteOrder $order, WebsiteShipment $shipment): void
    {
        WebsiteFulfillment::query()->forTenantId($order->tenant_id)->whereKey($shipment->website_fulfillment_id)->update(['status' => 'fulfilled', 'fulfilled_at' => now()]);
        $order->update(['fulfillment_status' => 'fulfilled', 'fulfilled_at' => $order->fulfilled_at ?? now()]);
    }

    public function ingest(string $raw, array $headers, string $method, string $path): void
    {
        abort_if(strlen($raw) > 1024 * 1024, 413);
        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            abort(400, 'Invalid carrier event.');
        }
        abort_unless(is_array($payload), 400);
        $type = $payload['description'] ?? '';
        if (! in_array($type, ['tracker.created', 'tracker.updated', 'refund.successful'], true)) {
            return;
        }
        $result = (array) ($payload['result'] ?? []);
        $id = $result['shipment_id'] ?? '';
        $trackerId = $result['id'] ?? '';
        $query = WebsiteShipment::query()->where('provider', 'easypost')->where('parcel->_native->version', 1);
        if (is_string($id) && preg_match('/^shp_[A-Za-z0-9_]+$/', $id)) {
            $query->where('provider_shipment_id', $id);
        } elseif ($type !== 'refund.successful' && is_string($trackerId) && preg_match('/^trk_[A-Za-z0-9_]+$/', $trackerId)) {
            $query->where('parcel->_native->tracker_id', $trackerId);
        } else {
            return;
        }
        $shipment = $query->first();
        if (! $shipment) {
            return;
        }
        $client = app(WebsiteEasyPostService::class);
        $connection = $client->connection($shipment);
        $secret = trim((string) $connection->external_account_secret);
        abort_unless($client->validSignature($raw, $headers, $method, $path, $secret), 400, 'Invalid carrier signature.');
        abort_unless(preg_match('/^evt_[A-Za-z0-9_]+$/', (string) ($payload['id'] ?? ''))
            && ($payload['mode'] ?? '') === data_get($shipment->parcel, '_native.mode'), 400, 'Carrier event identity is invalid.');
        $event = $this->locked($shipment, function ($order, $shipment) use ($payload, $type, $result): WebsiteShipmentEvent {
            return WebsiteShipmentEvent::query()->firstOrCreate(['website_shipment_id' => $shipment->id, 'provider_event_id' => $payload['id']],
                ['tenant_id' => $shipment->tenant_id, 'event_type' => 'provider_event', 'status' => 'received', 'message' => 'Authenticated carrier event queued.',
                    'payload' => ['description' => $type, 'object_id' => $result['id'] ?? null, 'user_id' => $payload['user_id'] ?? null, 'mode' => $payload['mode']], 'occurred_at' => now()]);
        });
        if ($event->status !== 'processed') {
            ProcessWebsiteShippingEvent::dispatch($event->id)->afterCommit();
        }
    }

    public function processEvent(int $eventId): void
    {
        $event = WebsiteShipmentEvent::query()->where('event_type', 'provider_event')->findOrFail($eventId);
        if ($event->status === 'processed') {
            return;
        }
        $shipment = WebsiteShipment::query()->forTenantId($event->tenant_id)->where('provider', 'easypost')->findOrFail($event->website_shipment_id);
        $client = app(WebsiteEasyPostService::class);
        $connection = $client->connection($shipment);
        $response = $client->client($shipment)->get('/events/'.$event->provider_event_id);
        $accountId = data_get($connection->metadata, 'native_shipping.user_id');
        if (! $accountId && str_starts_with((string) $connection->external_account_id, 'user_')) {
            $accountId = $connection->external_account_id;
        }
        abort_unless($response->successful() && $response->json('id') === $event->provider_event_id
            && $response->json('mode') === data_get($shipment->parcel, '_native.mode')
            && $response->json('description') === data_get($event->payload, 'description')
            && preg_match('/^user_[A-Za-z0-9_]+$/', (string) $response->json('user_id'))
            && (! $accountId || $response->json('user_id') === $accountId), 502, 'Carrier event account verification is unavailable.');
        if (! $accountId) {
            DB::transaction(function () use ($connection, $response): void {
                $connection = \App\Models\IntegrationConnection::query()->forTenantId($connection->tenant_id)->lockForUpdate()->findOrFail($connection->id);
                $metadata = $connection->metadata ?? [];
                $existing = data_get($metadata, 'native_shipping.user_id');
                abort_if($existing && $existing !== $response->json('user_id'), 409, 'Carrier account identity changed.');
                $metadata['native_shipping']['user_id'] = $response->json('user_id');
                $connection->update(['metadata' => $metadata]);
            });
        }
        $this->reconcile($shipment);
        $event->update(['status' => 'processed']);
    }

    private function trackingUrl(mixed $url): ?string
    {
        return is_string($url) && strlen($url) <= 2048 && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === 'track.easypost.com' ? $url : null;
    }

    private function authorize(User $actor, int $tenantId): void
    {
        app(WebsiteShippingAccessService::class)->authorize($actor, $tenantId);
    }

    private function metadata(WebsiteShipment $shipment, array $changes): void
    {
        $parcel = $shipment->parcel;
        $parcel['_native'] = array_merge((array) ($parcel['_native'] ?? []), $changes);
        $shipment->update(['parcel' => $parcel]);
    }

    private function orderLock(WebsiteOrder $order, callable $callback): mixed
    {
        return DB::transaction(function () use ($order, $callback) {
            TenantSite::query()->forTenantId($order->tenant_id)->lockForUpdate()->findOrFail($order->tenant_site_id);
            $order = WebsiteOrder::query()->forTenantId($order->tenant_id)->lockForUpdate()->findOrFail($order->id);

            return $callback($order);
        }, 3);
    }

    private function locked(WebsiteShipment $shipment, callable $callback): mixed
    {
        $order = WebsiteOrder::query()->forTenantId($shipment->tenant_id)->findOrFail($shipment->website_order_id);

        return $this->orderLock($order, function ($order) use ($shipment, $callback) {
            $shipment = WebsiteShipment::query()->forTenantId($order->tenant_id)->where('website_order_id', $order->id)->lockForUpdate()->findOrFail($shipment->id);

            return $callback($order, $shipment);
        });
    }

    private function event(WebsiteShipment $shipment, string $type, string $message, array $data = [], ?string $id = null): void
    {
        WebsiteShipmentEvent::query()->firstOrCreate(['website_shipment_id' => $shipment->id, 'provider_event_id' => $id ?? $type.'-'.Str::uuid()],
            ['tenant_id' => $shipment->tenant_id, 'event_type' => $type, 'status' => $shipment->status, 'message' => $message, 'payload' => $data, 'occurred_at' => now()]);
        if ($type !== 'provider_snapshot') {
            WebsiteOrderEvent::query()->create(['tenant_id' => $shipment->tenant_id, 'website_order_id' => $shipment->website_order_id,
                'user_id' => $data['user_id'] ?? null, 'event_type' => $type, 'visibility' => 'staff', 'message' => $message, 'data' => ['shipment_id' => $shipment->id]]);
        }
    }

    private function notify(WebsiteOrder $order, string $key, string $kind, WebsiteShipment $shipment): void
    {
        app(WebsiteOrderNotificationService::class)->record($order, $key, $kind, $shipment);
    }
}
