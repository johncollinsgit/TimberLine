<?php

use App\Jobs\Pos\RefreshSquareEventSource;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Marketing\SquareSourceRefresh;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake([RefreshSquareEventSource::class]);
    config(['pos_live.runtime_id' => 'source_ingress_fixture', 'pos_live.square_environment' => 'sandbox', 'pos_live.external_sales_ingest_enabled' => true,
        'pos_live.square_webhook_signature_key' => 'fictional-ingress-signature', 'pos_live.square_webhook_url' => 'https://example.invalid/api/pos/square/webhook', 'cache.default' => 'array']);
    $this->binding = ['connection_id' => 'source-reader', 'merchant_id' => 'original-merchant', 'environment' => 'sandbox',
        'location_ids' => ['original-location'], 'runtime_id' => 'source_ingress_fixture', 'policy_version' => str_repeat('a', 64)];
    $this->sources = Mockery::mock(SquareSourceRefresh::class);
    app()->instance(SquareSourceRefresh::class, $this->sources);
});

function sourceIngressEvent(string $type = 'payment.updated', array $object = []): array
{
    return ['event_id' => 'fictional-source-event', 'merchant_id' => 'original-merchant', 'type' => $type,
        'data' => ['object' => $object ?: ['payment' => ['id' => 'external-payment', 'location_id' => 'original-location', 'status' => 'COMPLETED']]]];
}

function sendSourceIngress(array|string $event, ?string $signature = null)
{
    $raw = is_array($event) ? json_encode($event, JSON_THROW_ON_ERROR) : $event;
    $signature ??= base64_encode(hash_hmac('sha256', config('pos_live.square_webhook_url').$raw, config('pos_live.square_webhook_signature_key'), true));

    return test()->call('POST', '/api/pos/square/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature], $raw);
}

it('rejects invalid HMAC before resolving any source policy or queuing work', function (): void {
    $this->sources->shouldNotReceive('webhookTargets');
    sendSourceIngress(sourceIngressEvent(), 'invalid-signature')->assertForbidden();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('rejects signed malformed JSON and ignores malformed object identities before resolving policy', function (): void {
    $this->sources->shouldNotReceive('webhookTargets');
    sendSourceIngress('{invalid-json')->assertUnprocessable();
    $event = sourceIngressEvent();
    $event['data']['object']['payment']['id'] = '../not-an-original-id';
    sendSourceIngress($event)->assertOk();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('keeps external source ingestion off until opted in and ignores unknown merchants', function (): void {
    config(['pos_live.external_sales_ingest_enabled' => false]);
    sendSourceIngress(sourceIngressEvent())->assertOk();
    Queue::assertNothingPushed();
    config(['pos_live.external_sales_ingest_enabled' => true]);
    $event = sourceIngressEvent();
    $event['merchant_id'] = 'unknown-merchant';
    $this->sources->shouldReceive('webhookTargets')->once()->with('unknown-merchant', 'sandbox', 'original-location')->andReturn([]);
    sendSourceIngress($event)->assertOk();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('queues a verified external payment without a Pay reference using only the reviewed frozen source binding', function (): void {
    $this->sources->shouldReceive('webhookTargets')->twice()->with('original-merchant', 'sandbox', 'original-location')
        ->andReturn([['tenant_id' => 31, 'binding' => $this->binding]]);
    $event = sourceIngressEvent();
    $event['data']['object']['payment']['card_details'] = ['card' => ['cardholder_name' => 'Fictional card field must not enter the queue payload']];
    sendSourceIngress($event)->assertOk();
    sendSourceIngress($event)->assertOk();
    Queue::assertPushed(RefreshSquareEventSource::class, 1);
    Queue::assertPushed(RefreshSquareEventSource::class, function ($job): bool {
        expect($job->tenant)->toBe(31)->and($job->binding)->toBe($this->binding)->and($job->kind)->toBe('payment')
            ->and($job->providerId)->toBe('external-payment')->and($job->runtime)->toBe('source_ingress_fixture')->and($job->queue)->toBe('event-sales-history');
        expect(json_encode(get_object_vars($job)))->not->toContain('cardholder_name', 'Fictional card field');
        expect(Str::isUuid($job->requestId()))->toBeTrue();

        return true;
    });
    Http::assertNothingSent();
});

it('uses the original payment identity for refunds and the documented order event envelope', function (string $type, array $payload, string $kind, string $id): void {
    $this->sources->shouldReceive('webhookTargets')->once()->with('original-merchant', 'sandbox', 'original-location')
        ->andReturn([['tenant_id' => 31, 'binding' => $this->binding]]);
    sendSourceIngress(sourceIngressEvent($type, $payload))->assertOk();
    Queue::assertPushed(RefreshSquareEventSource::class, fn ($job) => $job->kind === $kind && $job->providerId === $id);
    Http::assertNothingSent();
})->with([
    ['refund.updated', ['refund' => ['id' => 'external-refund', 'payment_id' => 'original-payment', 'location_id' => 'original-location']], 'payment', 'original-payment'],
    ['order.created', ['order_created' => ['order_id' => 'original-order', 'location_id' => 'original-location']], 'order', 'original-order'],
    ['order.updated', ['order_updated' => ['order_id' => 'original-order', 'location_id' => 'original-location']], 'order', 'original-order'],
    ['order.fulfillment.updated', ['order_fulfillment_updated' => ['order_id' => 'original-order', 'location_id' => 'original-location']], 'order', 'original-order'],
]);

it('retains stable retry identities and refuses a replacement runtime or disabled ingestion before provider access', function (): void {
    $job = new RefreshSquareEventSource(31, $this->binding, 'payment', 'external-payment', 'original-event', 'source_ingress_fixture');
    $replayed = unserialize(serialize($job));
    expect($replayed->uniqueId())->toBe($job->uniqueId())->and($replayed->requestId())->toBe($job->requestId())
        ->and($job->backoff())->toBe([2, 5, 15]);
    $this->sources->shouldReceive('refreshObject')->once()->with(31, $this->binding, 'payment', 'external-payment', $job->requestId())->andReturn(['status' => 'completed']);
    $job->handle($this->sources);
    foreach ([['pos_live.runtime_id' => 'replacement_runtime'], ['pos_live.runtime_id' => 'source_ingress_fixture', 'pos_live.external_sales_ingest_enabled' => false]] as $change) {
        config($change);
        try {
            $replayed->handle($this->sources);
            $this->fail('Changed runtime or disabled ingestion must reject the queued work.');
        } catch (HttpException $error) {
            expect($error->getStatusCode())->toBe(409);
        }
    }
    Http::assertNothingSent();
});

it('uses its reviewed asynchronous queue even when the application default queue is synchronous', function (): void {
    config(['queue.default' => 'sync', 'pos_live.external_sales_queue_connection' => 'database']);
    $job = new RefreshSquareEventSource(31, $this->binding, 'payment', 'external-payment', 'original-event', 'source_ingress_fixture');
    expect($job->connection)->toBe('database')->and($job->queue)->toBe('event-sales-history');
    $this->sources->shouldNotReceive('refreshObject');
    $this->sources->shouldReceive('webhookTargets')->once()->with('original-merchant', 'sandbox', 'original-location')
        ->andReturn([['tenant_id' => 31, 'binding' => $this->binding]]);
    sendSourceIngress(sourceIngressEvent())->assertOk();
    Queue::assertPushed(RefreshSquareEventSource::class, fn ($queued) => $queued->connection === 'database' && $queued->queue === 'event-sales-history');
    Http::assertNothingSent();
});

it('refuses a selected synchronous source queue before a verified notification can perform source work', function (): void {
    config(['queue.default' => 'sync', 'pos_live.external_sales_queue_connection' => 'sync']);
    $this->sources->shouldNotReceive('refreshObject');
    $this->sources->shouldReceive('webhookTargets')->once()->with('original-merchant', 'sandbox', 'original-location')
        ->andReturn([['tenant_id' => 31, 'binding' => $this->binding]]);
    sendSourceIngress(sourceIngressEvent())->assertStatus(503);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('logs a hashed source reference when source retrieval fails without retaining payload or exception messages', function (): void {
    Log::spy();
    $job = new RefreshSquareEventSource(31, $this->binding, 'payment', 'private-provider-id', 'private-event-id', 'source_ingress_fixture');
    $job->failed(new RuntimeException('Private provider response must not be logged'));
    Log::shouldHaveReceived('warning')->once()->with('square event source refresh requires review', Mockery::on(function (array $context) use ($job): bool {
        return $context === ['tenant_id' => 31, 'source_reference' => $job->uniqueId(), 'error_class' => RuntimeException::class]
            && ! str_contains(json_encode($context), 'private-provider-id');
    }));
});

it('rechecks the real owner refresh policy when an already queued source job starts', function (): void {
    app()->forgetInstance(SquareSourceRefresh::class);
    $tenant = Tenant::create(['name' => 'Fictional webhook source', 'slug' => 'webhook-source-policy']);
    $owner = User::factory()->create(['is_active' => true, 'role' => 'member', 'email_verified_at' => now()]);
    $tenant->users()->attach($owner->id, ['role' => 'owner', 'membership_active' => true]);
    TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'square_config', 'value' => ['connection_id' => 'original-source-reader', 'generation' => 1,
        'merchant_id' => 'original-merchant', 'environment' => 'sandbox', 'location_ids' => ['original-location'], 'status' => 'active',
        'credentials' => app(PosStore::class)->encrypt(['access_token' => 'fictional-source-only-reader', 'expires_at' => now()->addDay()->toIso8601String()])]]);
    Http::fake(['https://connect.squareupsandbox.com/v2/merchants/original-merchant' => Http::response(['merchant' => ['id' => 'original-merchant']])]);
    $refresh = app(SquareSourceRefresh::class);
    $enabled = $refresh->configure($tenant->id, $owner, ['enabled' => true, 'expected_version' => $refresh->status($tenant->id)['version'],
        'connection_id' => 'original-source-reader', 'lookback_days' => 14, 'create_unmatched' => true]);
    $job = new RefreshSquareEventSource($tenant->id, [...$enabled['binding'], 'policy_version' => $enabled['version']],
        'payment', 'original-payment', 'original-signed-event', 'source_ingress_fixture');
    $refresh->configure($tenant->id, $owner, ['enabled' => false, 'expected_version' => $enabled['version'], 'lookback_days' => 14, 'create_unmatched' => true]);
    try {
        $job->handle($refresh);
        $this->fail('A disabled current policy cannot ingest the queued source.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409);
    }
    Http::assertSentCount(1);
    expect(\App\Models\SquarePayment::count())->toBe(0)->and(\App\Models\SquareOrder::count())->toBe(0)->and(\App\Models\MarketingProfile::count())->toBe(0);
});
