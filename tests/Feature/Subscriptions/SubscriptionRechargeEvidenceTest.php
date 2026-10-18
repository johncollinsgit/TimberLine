<?php

use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeCredentialRevision;
use App\Models\SubscriptionRechargeOperation;
use App\Models\SubscriptionRechargeResource;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->tenant = Tenant::query()->create(['name' => 'Fictional Recharge merchant', 'slug' => 'recharge-evidence']);
    $this->connection = SubscriptionRechargeConnection::query()->create([
        'tenant_id' => $this->tenant->id, 'provider_store_id' => '000123', 'platform' => 'shopify',
        'identifier' => 'fictional-recharge.myshopify.com', 'evidence' => ['private' => 'merchant-private-canary'],
    ]);
    $this->credential = SubscriptionRechargeCredentialRevision::query()->create([
        'tenant_id' => $this->tenant->id, 'connection_id' => $this->connection->id, 'revision' => 1,
        'credentials' => ['access_token' => 'synthetic-secret-canary'], 'token_id' => 'opaque-token-id',
        'credential_fingerprint' => str_repeat('a', 64), 'digest_key_id' => str_repeat('b', 16),
    ]);
    $this->operation = SubscriptionRechargeOperation::query()->create([
        'tenant_id' => $this->tenant->id, 'connection_id' => $this->connection->id,
        'credential_revision_id' => $this->credential->id, 'request_uuid' => (string) Str::uuid(),
        'kind' => 'shadow_sync', 'request' => ['private' => 'request-private-canary'],
        'request_fingerprint' => str_repeat('c', 64), 'digest_key_id' => str_repeat('b', 16),
    ]);
});

function rechargeEvidenceResource($test, array $override = []): SubscriptionRechargeResource
{
    return SubscriptionRechargeResource::query()->create(array_replace([
        'tenant_id' => $test->tenant->id, 'connection_id' => $test->connection->id,
        'sync_operation_id' => $test->operation->id, 'generation_id' => (string) Str::uuid(),
        'resource_type' => 'customer', 'provider_id' => '00000456789012345678901234567890',
        'source_updated_at' => '2026-10-16T15:26:30.123456-04:00',
        'canonical_digest' => str_repeat('d', 64), 'digest_key_id' => str_repeat('b', 16),
        'observed_at' => now(), 'snapshot' => ['email' => 'synthetic-private@example.test', 'content' => str_repeat('private-large-canary', 300)],
    ], $override));
}

test('Recharge evidence encrypts and hides credentials customer snapshots and operational payloads', function (): void {
    $row = rechargeEvidenceResource($this);
    $row->update(['mapping_state' => 'unverified', 'mapping' => ['private' => 'matching-private-canary']]);
    $this->operation->update(['result' => ['private' => 'result-private-canary']]);
    $this->connection->update(['sync_state' => ['private' => 'sync-private-canary']]);
    expect(DB::table('subscription_recharge_connections')->value('evidence'))->not->toContain('merchant-private-canary')
        ->and(DB::table('subscription_recharge_connections')->value('sync_state'))->not->toContain('sync-private-canary')
        ->and(DB::table('subscription_recharge_credential_revisions')->value('credentials'))->not->toContain('synthetic-secret-canary')
        ->and(DB::table('subscription_recharge_operations')->value('request'))->not->toContain('request-private-canary')
        ->and(DB::table('subscription_recharge_operations')->value('result'))->not->toContain('result-private-canary')
        ->and(DB::table('subscription_recharge_resources')->value('snapshot'))->not->toContain('synthetic-private@example.test', 'private-large-canary')
        ->and(DB::table('subscription_recharge_resources')->value('mapping'))->not->toContain('matching-private-canary')
        ->and($row->fresh()->snapshot['content'])->toBe(str_repeat('private-large-canary', 300))
        ->and(json_encode([$this->connection->fresh(), $this->credential->fresh(), $this->operation->fresh(), $row->fresh()]))->not->toContain('merchant-private-canary', 'sync-private-canary', 'synthetic-secret-canary', 'opaque-token-id', 'request-private-canary', 'result-private-canary', 'synthetic-private@example.test', 'private-large-canary', 'matching-private-canary', 'credential_fingerprint', 'canonical_digest', 'request_fingerprint');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
});

test('Recharge store credentials operation and generation identities cannot be changed through models', function (): void {
    $resource = rechargeEvidenceResource($this);
    expect(Str::isUuid($this->connection->public_id))->toBeTrue()
        ->and($resource->fresh()->provider_id)->toBe('00000456789012345678901234567890')
        ->and($resource->fresh()->source_updated_at)->toBe('2026-10-16T15:26:30.123456-04:00');
    expect(fn () => $this->connection->update(['provider_store_id' => '999']))->toThrow(LogicException::class);
    expect(fn () => $this->credential->update(['credentials' => ['access_token' => 'replacement']]))->toThrow(LogicException::class);
    expect(fn () => $this->operation->update(['credential_revision_id' => 999]))->toThrow(LogicException::class);
    expect(fn () => $resource->update(['generation_id' => (string) Str::uuid()]))->toThrow(LogicException::class);
    expect(fn () => $resource->fresh()->update(['snapshot' => ['email' => 'changed@example.test']]))->toThrow(LogicException::class);
});

test('Recharge generations retain accepted customer evidence while a new generation is incomplete', function (): void {
    $accepted = rechargeEvidenceResource($this, ['completeness' => 'complete']);
    $this->connection->update(['sync_state' => ['last_complete' => ['generation_id' => $accepted->generation_id, 'counts' => ['customer' => 1]]], 'last_complete_sync_at' => now()]);
    $staged = rechargeEvidenceResource($this, ['snapshot' => ['email' => 'different-private@example.test']]);
    expect($staged->fresh()->completeness)->toBe('staged')
        ->and($this->connection->fresh()->sync_state['last_complete']['generation_id'])->toBe($accepted->generation_id)
        ->and(SubscriptionRechargeResource::query()->where('generation_id', $accepted->generation_id)->value('id'))->toBe($accepted->id)
        ->and($accepted->fresh()->snapshot['email'])->toBe('synthetic-private@example.test');
    expect(fn () => rechargeEvidenceResource($this, ['generation_id' => $accepted->generation_id]))->toThrow(QueryException::class);
    expect(SubscriptionRechargeResource::query()->count())->toBe(2);
});

test('Recharge composite foreign keys reject another tenant or connection at every durable parent', function (): void {
    $second = SubscriptionRechargeConnection::query()->create(['tenant_id' => $this->tenant->id, 'provider_store_id' => '456', 'platform' => 'shopify', 'identifier' => 'second.myshopify.com']);
    expect(fn () => DB::table('subscription_recharge_connections')->where('id', $second->id)->update(['active_credential_revision_id' => $this->credential->id]))->toThrow(QueryException::class);
    expect(fn () => SubscriptionRechargeCredentialRevision::query()->create(array_replace($this->credential->getAttributes(), ['id' => null, 'tenant_id' => $this->tenant->id + 999, 'revision' => 2])))->toThrow(QueryException::class);
    expect(fn () => DB::table('subscription_recharge_operations')->insert(array_replace($this->operation->getAttributes(), ['id' => null, 'connection_id' => $second->id, 'request_uuid' => (string) Str::uuid()])))->toThrow(QueryException::class);
    expect(fn () => rechargeEvidenceResource($this, ['connection_id' => $second->id]))->toThrow(QueryException::class);
    DB::table('subscription_recharge_connections')->where('id', $this->connection->id)->update(['active_credential_revision_id' => $this->credential->id]);
    expect($this->connection->fresh()->active_credential_revision_id)->toBe($this->credential->id)
        ->and(SubscriptionRechargeResource::query()->count())->toBe(0);
});

test('Recharge source-store adoption is globally unique and request UUIDs are connection scoped', function (): void {
    $otherTenant = Tenant::query()->create(['name' => 'Other merchant', 'slug' => 'recharge-other']);
    expect(fn () => SubscriptionRechargeConnection::query()->create(['tenant_id' => $otherTenant->id, 'provider_store_id' => '000123', 'platform' => 'shopify', 'identifier' => 'foreign.myshopify.com']))->toThrow(QueryException::class);
    expect(fn () => DB::table('subscription_recharge_operations')->insert(array_replace($this->operation->getAttributes(), ['id' => null])))->toThrow(QueryException::class);
    expect(SubscriptionRechargeConnection::query()->forTenantId($otherTenant->id)->count())->toBe(0);
    expect(fn () => $this->connection->delete())->toThrow(QueryException::class);
    $other = SubscriptionRechargeConnection::query()->create(['tenant_id' => $otherTenant->id, 'provider_store_id' => '999', 'platform' => 'shopify', 'identifier' => 'other.myshopify.com']);
    $revision = SubscriptionRechargeCredentialRevision::query()->create([
        'tenant_id' => $otherTenant->id, 'connection_id' => $other->id, 'revision' => 1, 'credentials' => ['access_token' => 'different-synthetic-token'],
        'credential_fingerprint' => str_repeat('e', 64), 'digest_key_id' => str_repeat('b', 16),
    ]);
    $operation = SubscriptionRechargeOperation::query()->create([
        'tenant_id' => $otherTenant->id, 'connection_id' => $other->id, 'credential_revision_id' => $revision->id,
        'request_uuid' => $this->operation->request_uuid, 'kind' => 'shadow_sync', 'request' => [],
        'request_fingerprint' => str_repeat('f', 64), 'digest_key_id' => str_repeat('b', 16),
    ]);
    expect($operation->id)->not->toBe($this->operation->id)
        ->and(SubscriptionRechargeOperation::query()->forTenantId($this->tenant->id)->pluck('id')->all())->toBe([$this->operation->id])
        ->and(SubscriptionRechargeOperation::query()->forTenantId($otherTenant->id)->pluck('id')->all())->toBe([$operation->id]);
});

test('Recharge forward-only rollback preserves durable history and credentials', function (): void {
    $row = rechargeEvidenceResource($this);
    $migration = require database_path('migrations/2026_10_16_120000_create_subscription_recharge_evidence.php');
    $migration->down();
    expect($row->fresh()->snapshot['email'])->toBe('synthetic-private@example.test')
        ->and($this->credential->fresh()->credentials['access_token'])->toBe('synthetic-secret-canary');
});
