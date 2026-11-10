<?php

namespace App\Services\ManagedWebsite;

use App\Models\IntegrationConnection;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteFulfillment;
use App\Models\WebsiteFulfillmentLine;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Tenant-owned freight accounts and durable, single-send booking attempts. */
class WebsiteFreightProviderService
{
    public function __construct(private WebsiteShipStationFreightService $client, private WebsiteFreightService $freight) {}

    public function current(WebsiteOrder $order): ?IntegrationConnection
    {
        return $this->currentForSite(TenantSite::query()->forTenantId($order->tenant_id)->findOrFail($order->tenant_site_id));
    }

    public function currentForSite(TenantSite $site): ?IntegrationConnection
    {
        return IntegrationConnection::query()->forTenantId($site->tenant_id)->where('provider', 'shipstation_freight')
            ->where('metadata->native_freight->site_id', $site->id)->latest('id')->first();
    }

    public function bookingEnabled(WebsiteOrder $order): bool
    {
        return (bool) config('managed_website.freight_booking_enabled')
            && in_array($order->tenant_id, (array) config('managed_website.freight_booking_tenant_ids', []), true);
    }

    public function connect(WebsiteOrder $order, array $input, User $actor): void
    {
        $this->connectSite($this->freight->authorize($order, $actor), $input, $actor);
    }

    public function connectSite(TenantSite $site, array $input, User $actor): void
    {
        $this->freight->authorizeSite($site, $actor);
        $data = Validator::make($input, ['api_key' => ['nullable', 'string', 'min:16', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'label' => ['required', 'string', 'max:190'], 'provider_account_id' => ['nullable', 'regex:/^se-[0-9]{1,30}$/D'],
            'billing_confirmed' => ['required', 'accepted']])->validate();
        $key = $data['api_key'] ?? null;
        if (blank($key)) {
            $key = $this->currentForSite($site)?->access_token;
        }
        if (blank($key)) {
            throw ValidationException::withMessages(['freight' => 'Enter the tenant-owned ShipStation v2 API key.']);
        }
        if (str_starts_with(strtoupper($key), 'TEST_')) {
            throw ValidationException::withMessages(['freight' => 'Use a production ShipStation v2 key for real order freight. Sandbox keys cannot book or fulfill paid orders in this workspace.']);
        }
        $accounts = $this->client->accounts($key);
        $selected = $data['provider_account_id'] ?? (count($accounts) === 1 ? $accounts[0]['id'] : null);
        $account = collect($accounts)->firstWhere('id', $selected);
        abort_unless(blank($selected) || $account, 422, 'Select an active freight provider account returned for this API key.');
        DB::transaction(function () use ($site, $actor, $data, $key, $accounts, $account): void {
            $site = TenantSite::query()->forTenantId($site->tenant_id)->lockForUpdate()->findOrFail($site->id);
            $this->freight->authorizeSite($site, $actor);
            // New immutable credential sets preserve all earlier quote/booking accounts.
            $connection = IntegrationConnection::query()->create(['tenant_id' => $site->tenant_id, 'provider' => 'shipstation_freight',
                'external_account_id' => 'native:'.Str::uuid(), 'external_account_label' => $data['label'],
                'access_token' => $key, 'status' => $account ? IntegrationConnection::STATUS_CONNECTED : IntegrationConnection::STATUS_PENDING,
                'connected_by_user_id' => $actor->id, 'connected_at' => now(),
                'metadata' => ['native_freight' => ['site_id' => $site->id, 'version' => 1, 'mode' => 'production',
                    'provider_account_id' => $account['id'] ?? null, 'accounts' => $accounts, 'billing_confirmed_by' => $actor->id, 'verified_at' => now()->toIso8601String()]]]);
            app(ManagedWebsiteService::class)->recordEvent($site,
                null, $actor, 'freight.account_verified', ['connection_id' => $connection->id, 'ready' => $connection->isConnected()]);
        });
    }

    public function quote(WebsiteOrder $order, int $version, User $actor): void
    {
        $this->freight->authorize($order, $actor);
        $order->refresh();
        $plan = (array) data_get($order->service_request, 'freight', []);
        $this->editable($plan);
        abort_unless(($plan['version'] ?? 0) === $version && ! empty($plan['specification']), 409, 'Reload and save reviewed freight details before requesting quotes.');
        $connection = $this->current($order);
        abort_unless($connection && $connection->isConnected(), 423, 'Connect and verify a tenant freight provider account first.');
        $connection = $this->connection($order, $connection->id);
        $request = ['freight_provider_account_id' => $this->client->accountId($connection)] + $this->client->specification($plan['specification']);
        $quotes = $this->client->quotes($connection, $request);
        $this->locked($order, $actor, function (WebsiteOrder $locked, array $current) use ($version, $quotes, $connection, $request): array {
            abort_unless(($current['version'] ?? 0) === $version, 409, 'Freight changed while quoting. Reload and request current quotes.');
            $this->editable($current);
            $this->connection($locked, $connection->id, $this->fingerprint($connection));
            $current['quotes'] = collect($current['quotes'] ?? [])->reject(fn ($q) => ($q['source'] ?? '') === 'shipstation_api')->values()->all();
            abort_unless(count($current['quotes']) + count($quotes) <= 20, 422, 'Retire old quotes by reviewing a new specification before requesting more.');
            foreach ($quotes as $quote) {
                $current['quotes'][] = $quote + ['id' => (string) Str::uuid(), 'source' => 'shipstation_api', 'provider' => 'ShipStation API',
                    'reference' => $quote['offer_id'], 'evidence_reference' => 'Verified provider response', 'all_in_confirmed' => true,
                    'connection_id' => $connection->id, 'account_fingerprint' => $this->fingerprint($connection),
                    'request' => $request, 'recorded_at' => now()->toIso8601String()];
            }
            $current['approved'] = null;
            $current['last_quote_count'] = count($quotes);

            return $current;
        }, 'quotes_received');
    }

    public function book(WebsiteOrder $order, array $input, User $actor): void
    {
        $this->freight->authorize($order, $actor);
        $data = Validator::make($input, ['version' => ['required', 'integer', 'min:0'], 'quote_id' => ['required', 'uuid'],
            'amount_cents' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted'],
            'ready_time' => ['required', 'date_format:H:i'], 'close_time' => ['required', 'date_format:H:i', 'after:ready_time']])->validate();
        $operation = (string) Str::uuid();
        $plan = $this->locked($order, $actor, function (WebsiteOrder $locked, array $plan) use ($data, $operation): array {
            abort_unless(($plan['version'] ?? 0) === (int) $data['version'], 409, 'Freight changed. Reload before booking.');
            $this->editable($plan);
            abort_unless($this->bookingEnabled($locked), 423, 'API freight booking is paused for this workspace. Quotes and existing-booking recovery remain available.');
            app(WebsitePaymentService::class)->assertFulfillable($locked);
            abort_unless(strtolower($locked->currency) === 'usd' && $locked->fulfillment_status === 'unfulfilled', 422, 'Review a paid, unfulfilled USD order before booking freight.');
            abort_if($locked->lines()->where('product_type', '!=', 'physical')->exists(), 422, 'This freight booking workflow requires a whole order of physical goods. Review mixed service orders separately.');
            abort_if($locked->shipments()->exists() || $locked->fulfillments()->where('status', '!=', 'cancelled')->exists(), 409, 'Resolve the existing parcel shipment or fulfillment before booking freight.');
            $quote = collect($plan['quotes'] ?? [])->firstWhere('id', $data['quote_id']);
            abort_if(data_get($plan, 'api_attempt.quote_id') === $data['quote_id'], 409, 'Request fresh quotes and approve a new offer after the rejected attempt.');
            abort_unless($quote && ($quote['source'] ?? '') === 'shipstation_api'
                && ($plan['approved']['quote_id'] ?? '') === $quote['id']
                && (int) $plan['approved']['amount_cents'] === (int) $quote['amount_cents']
                && (int) $data['amount_cents'] === (int) $quote['amount_cents']
                && CarbonImmutable::parse($quote['expires_at'])->isFuture(), 422, 'Approve a current API quote and confirm its exact cost before booking.');
            $this->connection($locked, $quote['connection_id'], $quote['account_fingerprint']);
            $this->client->specification($plan['specification']); // Date and contact validation without replacing the accepted quote.
            if (! empty($plan['api_attempt'])) {
                $plan['attempt_history'][] = $plan['api_attempt'];
            }
            $request = array_diff_key($quote['request'], array_flip(['freight_provider_account_id', 'shipment_date', 'accessorials']));
            $request += ['offer_id' => $quote['offer_id'], 'pickup_details' => ['pickup_date' => $plan['specification']['pickup_date'],
                'ready_time' => $data['ready_time'], 'close_time' => $data['close_time'], 'is_self_scheduled' => false,
                'location_type' => $quote['request']['ship_from']['location_type']],
                'references' => [['type' => 'reference_1', 'value' => 'EB-'.str_replace('-', '', $operation)]]];
            $plan['api_attempt'] = ['operation_id' => $operation, 'state' => 'booking', 'quote_id' => $quote['id'],
                'connection_id' => $quote['connection_id'], 'account_fingerprint' => $quote['account_fingerprint'],
                'amount_cents' => $quote['amount_cents'], 'request' => $request, 'started_at' => now()->toIso8601String()];

            return $plan;
        }, 'booking_started');
        $attempt = $plan['api_attempt'];
        try {
            $connection = $this->connection($order, $attempt['connection_id'], $attempt['account_fingerprint']);
            $response = $this->client->request($connection->access_token, 'POST', '/freight/shipments', $attempt['request']);
            $id = $response->json('freight_shipment_id');
            // Only explicit ShipStation field validation is a definite rejection.
            // Carrier/system errors, 429/5xx, malformed success and timeouts remain unknown.
            $errors = $response->json('errors');
            $rejected = $response->status() === 400 && is_array($errors) && count($errors) > 0
                && collect($errors)->every(fn ($e) => is_array($e) && ($e['error_source'] ?? '') === 'shipengine'
                    && in_array($e['error_type'] ?? '', ['validation', 'business_rules'], true)
                    && in_array($e['error_code'] ?? '', ['field_value_required', 'unspecified'], true));
            $this->attemptUpdate($order, $actor, $operation, ['state' => $rejected ? 'rejected' : 'unknown',
                'freight_shipment_id' => $this->client->validId($id) ? $id : null, 'http_status' => $response->status()]);
        } catch (\Throwable) {
            $this->attemptUpdate($order, $actor, $operation, ['state' => 'unknown']);
        }
        // Never send a second booking request. If an ID survived, canonical GETs
        // can complete this same attempt even if the response was interrupted.
        $this->refresh($order, $actor);
    }

    public function refresh(WebsiteOrder $order, User $actor): void
    {
        $this->freight->authorize($order, $actor);
        $order->refresh();
        $attempt = data_get($order->service_request, 'freight.api_attempt');
        abort_unless(is_array($attempt), 422, 'There is no API freight attempt to check.');
        $id = $attempt['freight_shipment_id'] ?? null;
        if (! $this->client->validId($id)) {
            return; // No authenticated ID: support must resolve the original operation. Never guess or retry a buy.
        }
        $connection = $this->connection($order, $attempt['connection_id'], $attempt['account_fingerprint']);
        $response = $this->client->request($connection->access_token, 'GET', '/freight/shipments/'.$id);
        $this->client->assertResponse($response);
        $shipment = $response->json();
        abort_unless(is_array($shipment) && ($shipment['freight_shipment_id'] ?? '') === $id
            && ($shipment['freight_provider_account_id'] ?? '') === $this->client->accountId($connection)
            && in_array($shipment['status'] ?? '', ['booked', 'in_transit', 'delivered', 'cancelled', 'exception'], true), 502, 'The freight response does not match the original booking account.');
        $documents = collect((array) ($shipment['documents'] ?? []))->take(20)->filter(fn ($d) => is_array($d) && is_string($d['url'] ?? null))
            ->map(function ($d): array {
                try {
                    $url = $this->client->documentUrl($d['url']);
                } catch (\Throwable) {
                    return [];
                }

                return ['type' => mb_substr(strtolower((string) ($d['type'] ?? 'document')), 0, 80), 'url' => $url];
            })->filter()->values()->all();
        $this->locked($order, $actor, function (WebsiteOrder $locked, array $plan) use ($attempt, $shipment, $documents, $id): array {
            abort_unless(data_get($plan, 'api_attempt.operation_id') === $attempt['operation_id'], 409, 'The freight operation changed.');
            abort_if(data_get($plan, 'api_attempt.state') === 'cancelled' && $shipment['status'] !== 'cancelled',
                409, 'The provider returned an older status for a cancelled shipment. Keep the confirmed cancellation and check with the original provider.');
            $cancellationPending = in_array(data_get($plan, 'api_attempt.state'), ['cancelling', 'cancellation_unknown'], true);
            $plan['api_attempt']['state'] = $shipment['status'] === 'cancelled' ? 'cancelled' : ($cancellationPending ? 'cancellation_unknown' : 'booked');
            $plan['api_attempt']['provider_status'] = $shipment['status'];
            $plan['api_attempt']['checked_at'] = now()->toIso8601String();
            $plan['api_attempt']['documents'] = $documents;
            $plan['booking'] = ['source' => 'shipstation_api', 'reference' => $id,
                'bol_reference' => mb_substr((string) ($shipment['bol_number'] ?? ''), 0, 190),
                'pro_number' => mb_substr((string) ($shipment['pro_number'] ?? ''), 0, 190),
                'evidence_reference' => 'Verified original-account API response',
                'recorded_at' => $plan['booking']['recorded_at'] ?? now()->toIso8601String()];
            if ($shipment['status'] === 'cancelled') {
                $plan['cancellation'] = ['reference' => $id, 'evidence_reference' => 'Provider reports cancelled; verify pickup cancellation and fees with the provider.', 'recorded_at' => now()->toIso8601String()];
            }

            return $plan;
        }, 'booking_checked');
        // A canonical shipment read is separate from fresh carrier tracking.
        // Tracking outages never undo a confirmed booking or make it retryable.
        try {
            $trackingResponse = $this->client->request($connection->access_token, 'GET', '/freight/shipments/'.$id.'/tracking');
            $this->client->assertResponse($trackingResponse);
            $tracking = $trackingResponse->json();
            if (! is_array($tracking) || ($tracking['freight_shipment_id'] ?? '') !== $id
                || ($tracking['freight_provider_account_id'] ?? '') !== $this->client->accountId($connection)) {
                return;
            }
            $events = collect((array) ($tracking['events'] ?? []))->take(50)->filter(fn ($e) => is_array($e))
                ->map(fn ($e) => ['occurred_at' => mb_substr((string) ($e['occurred_at'] ?? ''), 0, 80),
                    'status' => mb_substr((string) ($e['status'] ?? ''), 0, 80),
                    'description' => mb_substr((string) ($e['description'] ?? ''), 0, 500)])->values()->all();
            $this->attemptUpdate($order, $actor, $attempt['operation_id'], ['tracking' => [
                'status' => mb_substr((string) ($tracking['tracking_status'] ?? $tracking['status'] ?? ''), 0, 80),
                'estimated_delivery_date' => mb_substr((string) ($tracking['estimated_delivery_date'] ?? ''), 0, 80),
                'checked_at' => now()->toIso8601String(), 'events' => $events]]);
        } catch (\Throwable) {
            // Existing evidence remains visible with its original observation time.
        }
    }

    public function document(WebsiteOrder $order, User $actor, int $index): string
    {
        $this->freight->authorize($order, $actor);
        $attempt = data_get($order->fresh()->service_request, 'freight.api_attempt', []);
        $document = $attempt['documents'][$index] ?? null;
        abort_unless($document && $index >= 0, 404);

        return $this->client->document($this->connection($order, $attempt['connection_id'], $attempt['account_fingerprint']), $document['url']);
    }

    public function cancel(WebsiteOrder $order, int $version, User $actor): void
    {
        $plan = $this->locked($order, $actor, function (WebsiteOrder $locked, array $plan) use ($version): array {
            abort_unless(($plan['version'] ?? 0) === $version && data_get($plan, 'api_attempt.state') === 'booked', 409, 'Check the original freight booking before requesting cancellation.');
            abort_if(! empty($plan['handoff']) || in_array(data_get($plan, 'api_attempt.provider_status'), ['in_transit', 'delivered'], true)
                || in_array(data_get($plan, 'api_attempt.tracking.status'), ['in_transit', 'out_for_delivery', 'at_delivery', 'delivered'], true),
                422, 'The freight has been handed off or is in transit. Review changes directly with the original provider.');
            $this->connection($locked, $plan['api_attempt']['connection_id'], $plan['api_attempt']['account_fingerprint']);
            $plan['api_attempt']['state'] = 'cancelling';

            return $plan;
        }, 'cancellation_started');
        $attempt = $plan['api_attempt'];
        try {
            $connection = $this->connection($order, $attempt['connection_id'], $attempt['account_fingerprint']);
            $response = $this->client->request($connection->access_token, 'DELETE', '/freight/shipments/'.$attempt['freight_shipment_id']);
            $this->attemptUpdate($order, $actor, $attempt['operation_id'], ['state' => 'cancellation_unknown',
                'pickup_cancelled' => $response->successful() && $response->json('freight_shipment_id') === $attempt['freight_shipment_id'] && $response->json('pickup_cancelled') === true]);
        } catch (\Throwable) {
            $this->attemptUpdate($order, $actor, $attempt['operation_id'], ['state' => 'cancellation_unknown']);
        }
        $this->refresh($order, $actor);
    }

    public function handoff(WebsiteOrder $order, array $input, User $actor): void
    {
        $this->freight->authorize($order, $actor);
        $data = Validator::make($input, ['version' => ['required', 'integer', 'min:0'],
            'confirmed' => ['required', 'accepted'], 'evidence_reference' => ['required', 'string', 'max:500']])->validate();
        $attempt = data_get($order->fresh()->service_request, 'freight.api_attempt', []);
        abort_unless(($attempt['state'] ?? '') === 'booked' && $this->client->validId($attempt['freight_shipment_id'] ?? null), 409, 'Confirm the original freight booking before recording handoff.');
        $connection = $this->connection($order, $attempt['connection_id'], $attempt['account_fingerprint']);
        $response = $this->client->request($connection->access_token, 'GET', '/freight/shipments/'.$attempt['freight_shipment_id']);
        $this->client->assertResponse($response);
        abort_unless($response->json('freight_shipment_id') === $attempt['freight_shipment_id']
            && $response->json('freight_provider_account_id') === $this->client->accountId($connection)
            && in_array($response->json('status'), ['booked', 'in_transit', 'delivered'], true), 409, 'The original provider has not confirmed an active shipment. Check status before handoff.');
        $this->locked($order, $actor, function (WebsiteOrder $locked, array $plan) use ($data, $actor, $attempt): array {
            abort_unless(($plan['version'] ?? 0) === (int) $data['version'] && data_get($plan, 'api_attempt.operation_id') === $attempt['operation_id']
                && data_get($plan, 'api_attempt.state') === 'booked', 409, 'Freight changed. Reload before recording handoff.');
            app(WebsitePaymentService::class)->assertFulfillable($locked);
            abort_unless(empty($plan['handoff']) && $locked->fulfillment_status === 'unfulfilled'
                && ! $locked->lines()->where('product_type', '!=', 'physical')->exists()
                && ! $locked->fulfillments()->where('status', '!=', 'cancelled')->exists(), 409, 'Review existing fulfillment before recording this handoff.');
            $fulfillment = WebsiteFulfillment::query()->create(['tenant_id' => $locked->tenant_id, 'website_order_id' => $locked->id,
                'status' => 'fulfilled', 'method' => 'ship', 'fulfilled_at' => now(), 'fulfilled_by_user_id' => $actor->id,
                'note' => 'Staff confirmed freight handoff. See protected freight pickup evidence.']);
            foreach ($locked->lines()->get() as $line) {
                WebsiteFulfillmentLine::query()->create(['tenant_id' => $locked->tenant_id, 'website_fulfillment_id' => $fulfillment->id,
                    'website_order_line_id' => $line->id, 'quantity' => $line->quantity]);
            }
            $locked->update(['fulfillment_status' => 'fulfilled', 'fulfilled_at' => now()]);
            $plan['handoff'] = ['by' => $actor->id, 'at' => now()->toIso8601String(), 'evidence_reference' => $data['evidence_reference'], 'fulfillment_id' => $fulfillment->id];

            return $plan;
        }, 'physical_handoff_recorded');
    }

    private function connection(WebsiteOrder $order, int $id, ?string $fingerprint = null): IntegrationConnection
    {
        $connection = IntegrationConnection::query()->forTenantId($order->tenant_id)->where('provider', 'shipstation_freight')
            ->where('metadata->native_freight->site_id', $order->tenant_site_id)->findOrFail($id);
        abort_unless($connection->isConnected() && filled($connection->access_token) && ! str_starts_with(strtoupper($connection->access_token), 'TEST_')
            && $this->client->validId($this->client->accountId($connection))
            && ($fingerprint === null || hash_equals($fingerprint, $this->fingerprint($connection))), 423, 'Restore the original freight account and credentials to recover this operation. A newer connection cannot replace it.');

        return $connection;
    }

    private function fingerprint(IntegrationConnection $connection): string
    {
        return hash('sha256', $connection->id.'|'.$this->client->accountId($connection).'|'.$connection->access_token);
    }

    private function editable(array $plan): void
    {
        abort_if(! empty($plan['booking']) || (! empty($plan['api_attempt']) && data_get($plan, 'api_attempt.state') !== 'rejected'),
            409, 'Resolve the original freight operation before making changes. Check status; do not book again.');
    }

    private function attemptUpdate(WebsiteOrder $order, User $actor, string $operation, array $changes): void
    {
        $this->locked($order, $actor, function (WebsiteOrder $locked, array $plan) use ($operation, $changes): array {
            abort_unless(data_get($plan, 'api_attempt.operation_id') === $operation, 409, 'The original freight operation changed.');
            $plan['api_attempt'] = array_replace($plan['api_attempt'], $changes);

            return $plan;
        }, 'attempt_checked');
    }

    private function locked(WebsiteOrder $order, User $actor, \Closure $action, string $event): array
    {
        return DB::transaction(function () use ($order, $actor, $action, $event): array {
            TenantSite::query()->forTenantId($order->tenant_id)->lockForUpdate()->findOrFail($order->tenant_site_id);
            $locked = WebsiteOrder::query()->forTenantId($order->tenant_id)->where('tenant_site_id', $order->tenant_site_id)->lockForUpdate()->findOrFail($order->id);
            $this->freight->authorize($locked, $actor);
            $request = (array) $locked->service_request;
            $before = (array) ($request['freight'] ?? []);
            $after = $action($locked, $before);
            $after['version'] = ($before['version'] ?? 0) + 1;
            $request['freight'] = $after;
            $locked->update(['service_request' => $request]);
            WebsiteOrderEvent::query()->create(['tenant_id' => $locked->tenant_id, 'website_order_id' => $locked->id, 'user_id' => $actor->id,
                'event_type' => 'freight_api_'.$event, 'visibility' => 'staff', 'message' => 'Freight provider operation recorded.', 'data' => ['before' => $before, 'after' => $after]]);

            return $after;
        });
    }
}
