<?php

use App\Models\SubscriptionCredentialRevision;
use App\Models\SubscriptionInstallation;
use App\Models\SubscriptionOperation;
use App\Models\SubscriptionProviderResource;
use App\Models\SubscriptionWebhookInbox;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionEvidence;
use App\Services\Subscriptions\SubscriptionOperationService;
use App\Services\Subscriptions\SubscriptionWebhookInboxService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../ShopifyEmbeddedTestHelpers.php';
require_once __DIR__.'/../../Support/SubscriptionFoundationHelpers.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    $this->tenant = Tenant::query()->create(['name' => 'Evidence merchant', 'slug' => 'evidence-merchant']);
    $this->actor = subscriptionFoundationFixture($this, $this->tenant);
    config()->set('subscriptions.provider_reads_enabled', true);
    config()->set('subscriptions.provider_read_tenant_ids', [(int) $this->tenant->id]);
    $store = \App\Models\ShopifyStore::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->installation = SubscriptionInstallation::query()->create([
        'tenant_id' => $this->tenant->id, 'shopify_store_id' => $store->id, 'shop_domain' => $store->shop_domain,
        'shop_gid' => 'gid://shopify/Shop/101', 'app_gid' => 'gid://shopify/App/201', 'app_installation_gid' => 'gid://shopify/AppInstallation/301',
        'public_client_id' => 'public-app', 'status' => 'verified', 'verified_at' => now(),
    ]);
    $this->revision = SubscriptionCredentialRevision::query()->create([
        'tenant_id' => $this->tenant->id, 'installation_id' => $this->installation->id, 'revision' => 1,
        'status' => 'active', 'credentials' => ['access_token' => 'private-token-example'],
        'credential_fingerprint' => SubscriptionEvidence::digest('private-token-example'), 'digest_key_id' => SubscriptionEvidence::keyId(), 'verified_at' => now(),
    ]);
    $this->installation->update(['active_credential_revision_id' => $this->revision->id]);
    $this->operations = app(SubscriptionOperationService::class);
    $this->inbox = app(SubscriptionWebhookInboxService::class);
});

function evidenceOperation($test, array $request = [], ?string $uuid = null): SubscriptionOperation
{
    return $test->operations->record($test->tenant->id, $test->installation->id, $test->actor, 'owned_contract_sync', $uuid ?? (string) Str::uuid(), $request);
}

function evidenceDelivery($test, string $delivery = 'delivery-1', string $body = '{"id":101,"email":"private@example.test"}', ?string $reason = null): SubscriptionWebhookInbox
{
    return $test->inbox->persist($test->tenant->id, $test->installation->id, $delivery, 'same-event', 'subscription_contracts/update', '2026-10', $body, ['Authorization' => 'never-retain', 'X-Shopify-Shop-Domain' => 'modernforestry.myshopify.com'], $reason, signingEvidence: ['app_secret_reference' => 'test:app-secret', 'app_secret_revision' => '1']);
}

test('evidence models encrypt and hide private fields and preserve immutable identity', function (): void {
    $operation = evidenceOperation($this, ['private' => 'private@example.test']);
    $inbox = evidenceDelivery($this);
    expect(DB::table('subscription_credential_revisions')->value('credentials'))->not->toContain('private-token-example')
        ->and(DB::table('subscription_operations')->value('request'))->not->toContain('private@example.test')
        ->and(DB::table('subscription_webhook_inboxes')->value('raw_body'))->not->toContain('private@example.test')
        ->and(json_encode($this->revision->fresh()))->not->toContain('credentials', 'private-token-example')
        ->and(json_encode($operation))->not->toContain('request_fingerprint', 'private@example.test')
        ->and(json_encode($inbox))->not->toContain('private@example.test', 'raw_body')
        ->and($inbox->headers)->toBe(['x-shopify-shop-domain' => 'modernforestry.myshopify.com']);
    expect(fn () => $this->installation->update(['app_gid' => 'gid://shopify/App/999']))->toThrow(LogicException::class);
    expect(fn () => $this->revision->update(['credentials' => ['access_token' => 'replacement']]))->toThrow(LogicException::class);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('operation UUID dedupes canonical content and rejects content kind and secret conflicts', function (): void {
    $uuid = (string) Str::uuid();
    $first = evidenceOperation($this, ['z' => 1, 'nested' => ['b' => 2, 'a' => 1]], $uuid);
    expect(evidenceOperation($this, ['nested' => ['a' => 1, 'b' => 2], 'z' => 1], $uuid)->id)->toBe($first->id);
    expect(fn () => evidenceOperation($this, ['z' => 2], $uuid))->toThrow(HttpException::class);
    expect(fn () => $this->operations->record($this->tenant->id, $this->installation->id, $this->actor, 'installation_probe', $uuid, []))->toThrow(HttpException::class);
    expect(fn () => $this->operations->record($this->tenant->id, $this->installation->id, $this->actor, 'create_billing_attempt', (string) Str::uuid()))->toThrow(HttpException::class);
    expect(fn () => evidenceOperation($this, ['nested' => ['access_token' => 'forbidden']]))->toThrow(HttpException::class);
    expect(SubscriptionOperation::query()->count())->toBe(1);
});

test('operation work has fenced expiring leases and durable replay without provider execution', function (): void {
    $row = evidenceOperation($this);
    $first = $this->operations->claim($this->tenant->id, $row->id, $this->actor, 2);
    expect($this->operations->claim($this->tenant->id, $row->id, $this->actor))->toBeNull();
    $this->travel(3)->seconds();
    $second = $this->operations->claim($this->tenant->id, $row->id, $this->actor);
    expect($second->attempts)->toBe(2)
        ->and($this->operations->complete($this->tenant->id, $row->id, $first->lease_token, 'completed'))->toBeFalse()
        ->and($this->operations->complete($this->tenant->id, $row->id, $second->lease_token, 'completed', ['observed_count' => 0]))->toBeTrue()
        ->and($this->operations->claim($this->tenant->id, $row->id, $this->actor))->toBeNull()
        ->and(evidenceOperation($this, [], $row->request_uuid)->id)->toBe($row->id);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('pending verification permits a pinned probe but not ordinary sync or credential fallback', function (): void {
    $this->installation->update(['active_credential_revision_id' => null, 'status' => 'pending_verification', 'verified_at' => null]);
    $this->revision->update(['status' => 'pending_verification', 'verified_at' => null]);
    $probe = $this->operations->record($this->tenant->id, $this->installation->id, $this->actor, 'installation_probe', (string) Str::uuid(), [], $this->revision->id);
    expect($this->operations->claim($this->tenant->id, $probe->id, $this->actor))->not->toBeNull();
    expect(fn () => evidenceOperation($this))->toThrow(HttpException::class);
    expect(fn () => evidenceDelivery($this))->toThrow(HttpException::class);
});

test('changed revoked expired or foreign credentials and inactive managers fail closed', function (): void {
    $row = evidenceOperation($this);
    $this->revision->update(['revoked_at' => now()]);
    expect(fn () => $this->operations->claim($this->tenant->id, $row->id, $this->actor))->toThrow(HttpException::class);
    $this->revision->update(['revoked_at' => null, 'expires_at' => now()->subSecond()]);
    expect(fn () => evidenceOperation($this))->toThrow(HttpException::class);
    $this->revision->update(['expires_at' => null]);
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    expect(fn () => evidenceOperation($this))->toThrow(HttpException::class);
});

test('database composite keys prohibit cross-tenant installation children and foreign active revisions', function (): void {
    $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
    expect(fn () => DB::table('subscription_webhook_inboxes')->insert([
        'tenant_id' => $other->id, 'installation_id' => $this->installation->id, 'delivery_id' => 'foreign', 'topic' => 'x/y', 'api_version' => '2026-10',
        'body_fingerprint' => str_repeat('a', 64), 'digest_key_id' => str_repeat('a', 16), 'raw_body' => 'synthetic', 'headers' => 'synthetic', 'verification' => 'synthetic',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
    $second = SubscriptionInstallation::query()->create([
        'tenant_id' => $this->tenant->id, 'shopify_store_id' => $this->installation->shopify_store_id, 'shop_domain' => 'other.myshopify.com',
        'shop_gid' => 'gid://shopify/Shop/102', 'app_gid' => 'gid://shopify/App/202', 'app_installation_gid' => 'gid://shopify/AppInstallation/302', 'public_client_id' => 'other-public',
    ]);
    expect(fn () => $second->update(['active_credential_revision_id' => $this->revision->id]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('webhook duplicate replay is durable and conflicting bodies quarantine without replacing original', function (): void {
    $first = evidenceDelivery($this);
    expect(evidenceDelivery($this)->id)->toBe($first->id);
    $lease = $this->inbox->claim($this->tenant->id, $first->id, $this->actor);
    $conflict = evidenceDelivery($this, body: '{"id":101,"email":"changed@example.test"}');
    expect($conflict->status)->toBe('quarantined')
        ->and($conflict->raw_body)->toContain('private@example.test')
        ->and($conflict->conflict_evidence['raw_body'])->toContain('changed@example.test')
        ->and(DB::table('subscription_webhook_inboxes')->value('conflict_evidence'))->not->toContain('changed@example.test')
        ->and($this->inbox->complete($this->tenant->id, $first->id, $lease->lease_token))->toBeFalse()
        ->and($this->inbox->claim($this->tenant->id, $first->id, $this->actor))->toBeNull();
    expect(evidenceDelivery($this, 'delivery-2')->id)->not->toBe($first->id);
    expect(SubscriptionWebhookInbox::query()->where('event_id', 'same-event')->count())->toBe(2);
});

test('paused authenticated evidence quarantines and cannot automatically resume when access returns', function (): void {
    TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->update(['enabled_status' => 'disabled']);
    app()->forgetInstance(TenantModuleAccessResolver::class);
    $row = evidenceDelivery($this);
    expect($row->status)->toBe('quarantined')->and($row->quarantine_reason)->toBe('entitlement_paused');
    TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->update(['enabled_status' => 'enabled']);
    app()->forgetInstance(TenantModuleAccessResolver::class);
    expect($this->inbox->claim($this->tenant->id, $row->id, $this->actor))->toBeNull();
    expect(evidenceDelivery($this, 'paused-reads', reason: 'provider_reads_paused')->status)->toBe('quarantined');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('unsupported and malformed authenticated bodies retain quarantined evidence with bounded safe headers', function (): void {
    $row = $this->inbox->persist($this->tenant->id, $this->installation->id, 'old-version', null, 'subscription_contracts/update', '2026-01', '{"id":1}', signingEvidence: ['app_secret_reference' => 'test:app-secret', 'app_secret_revision' => '1']);
    expect($row->quarantine_reason)->toBe('unsupported_api_version');
    expect(evidenceDelivery($this, 'malformed', 'not-json')->quarantine_reason)->toBe('malformed_payload');
    expect(fn () => evidenceDelivery($this, 'large', str_repeat('a', 262145)))->toThrow(HttpException::class);
    expect(fn () => evidenceDelivery($this, 'secret', '{"access_token":"forbidden"}'))->toThrow(HttpException::class);
});

test('provider resource retains uint64 revision and encrypted complete evidence', function (): void {
    $row = SubscriptionProviderResource::query()->create([
        'tenant_id' => $this->tenant->id, 'installation_id' => $this->installation->id, 'sync_operation_id' => evidenceOperation($this)->id,
        'resource_type' => 'contract', 'provider_gid' => 'gid://shopify/SubscriptionContract/10', 'provider_revision' => '18446744073709551615',
        'status' => 'ACTIVE', 'observed_at' => now(), 'snapshot' => ['email' => 'private@example.test'],
    ]);
    expect($row->fresh()->provider_revision)->toBe('18446744073709551615')
        ->and(DB::table('subscription_provider_resources')->value('snapshot'))->not->toContain('private@example.test')
        ->and($row->toArray())->not->toHaveKey('snapshot');
});

test('global read pause quarantines intake and signing revision is server-owned hidden evidence', function (): void {
    config()->set('subscriptions.provider_reads_enabled', false);
    $row = evidenceDelivery($this);
    expect($row->quarantine_reason)->toBe('provider_reads_paused')
        ->and($row->verification)->toBe(['app_secret_reference' => 'test:app-secret', 'app_secret_revision' => '1'])
        ->and($row->toArray())->not->toHaveKey('verification');
    expect(fn () => $this->inbox->claim($this->tenant->id, $row->id, $this->actor))->toThrow(HttpException::class);
    expect(fn () => $this->inbox->persist($this->tenant->id, $this->installation->id, 'missing-signature', null, 'app/uninstalled', '2026-10', '{"id":1}'))->toThrow(HttpException::class);
});

test('key rotation preserves identical encrypted request and delivery replay and the original signing revision', function (): void {
    $uuid = (string) Str::uuid();
    $operation = evidenceOperation($this, ['private' => 'same@example.test'], $uuid);
    $delivery = evidenceDelivery($this);
    $oldKey = config('app.key');
    $newKey = 'base64:'.base64_encode(random_bytes(32));
    config()->set('app.key', $newKey);
    config()->set('app.previous_keys', [$oldKey]);
    app()->forgetInstance('encrypter');
    \Illuminate\Support\Facades\Crypt::clearResolvedInstance('encrypter');
    expect(evidenceOperation($this, ['private' => 'same@example.test'], $uuid)->id)->toBe($operation->id);
    $replay = $this->inbox->persist($this->tenant->id, $this->installation->id, 'delivery-1', 'same-event', 'subscription_contracts/update', '2026-10', '{"id":101,"email":"private@example.test"}', signingEvidence: ['app_secret_revision' => '2', 'app_secret_reference' => 'test:app-secret']);
    expect($replay->id)->toBe($delivery->id)->and($replay->status)->toBe('pending')
        ->and($replay->verification['app_secret_revision'])->toBe('1')
        ->and($operation->request_fingerprint)->not->toBe(SubscriptionEvidence::digest(SubscriptionEvidence::canonical(['kind' => 'owned_contract_sync', 'credential_revision_id' => $this->revision->id, 'request' => ['private' => 'same@example.test']])));
});

test('revoked operator or read gate cannot accept a claimed observation', function (): void {
    $row = evidenceOperation($this);
    $claimed = $this->operations->claim($this->tenant->id, $row->id, $this->actor);
    User::query()->whereKey($this->actor->id)->update(['is_active' => false]);
    expect(fn () => $this->operations->complete($this->tenant->id, $row->id, $claimed->lease_token, 'completed'))->toThrow(HttpException::class);
    User::query()->whereKey($this->actor->id)->update(['is_active' => true]);
    config()->set('subscriptions.provider_reads_enabled', false);
    expect(fn () => $this->operations->complete($this->tenant->id, $row->id, $claimed->lease_token, 'completed'))->toThrow(HttpException::class);
    expect($row->fresh()->status)->toBe('reading');
});
