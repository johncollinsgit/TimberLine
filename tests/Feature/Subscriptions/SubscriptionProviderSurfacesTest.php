<?php

use App\Models\ShopifyStore;
use App\Models\SubscriptionCredentialRevision;
use App\Models\SubscriptionInstallation;
use App\Models\SubscriptionOperation;
use App\Models\SubscriptionProviderResource;
use App\Models\SubscriptionWebhookInbox;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionEvidence;
use App\Services\Subscriptions\SubscriptionProviderReadService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    config()->set('app.debug', false);
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.auth.flagship_hosts', ['app.theeverbranch.com']);
    config()->set('subscriptions.provider_reads_enabled', false);
    config()->set('subscriptions.provider_read_tenant_ids', []);
    config()->set('subscriptions.background_reads_enabled', false);
    $this->tenant = Tenant::query()->create(['name' => 'Surface merchant', 'slug' => 'surface-merchant']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'subscriptions', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_paid', 'entitlement_source' => 'test_explicit', 'price_source' => 'test']);
    $this->actor = User::factory()->create(['name' => 'Workspace Manager', 'email' => 'manager@example.test', 'role' => 'manager', 'requested_via' => 'customer_production', 'is_active' => true]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'manager', 'membership_active' => true]);
    $this->actingAs($this->actor);
    [$this->installation,$this->revision] = subscriptionSurfaceBinding($this->tenant, 'one');
    $this->url = 'https://app.theeverbranch.com/subscriptions?tenant=surface-merchant';
});

afterEach(function (): void {
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Queue::assertNothingPushed();
});

function subscriptionSurfaceBinding(Tenant $tenant, string $key, ?string $secret = 'explicit-current-secret-32-bytes!!'): array
{
    $store = ShopifyStore::query()->create(['tenant_id' => $tenant->id, 'store_key' => 'surface-'.$key, 'store_role' => 'retail', 'shop_domain' => 'surface-'.$key.'.myshopify.com', 'access_token' => 'private-access-token-'.$key]);
    $installation = SubscriptionInstallation::query()->create(['tenant_id' => $tenant->id, 'shopify_store_id' => $store->id, 'shop_domain' => $store->shop_domain,
        'shop_gid' => 'gid://shopify/Shop/'.($store->id + 100), 'app_gid' => 'gid://shopify/App/'.($store->id + 200), 'app_installation_gid' => 'gid://shopify/AppInstallation/'.($store->id + 300),
        'public_client_id' => 'surface-public-'.$key, 'status' => 'verified', 'verified_at' => now(), 'shop_timezone' => 'America/New_York',
        'scopes' => ['read_own_subscription_contracts', 'read_customers', 'read_customer_payment_methods'],
        'evidence' => ['private' => 'hidden-installation-evidence@example.test']]);
    $revision = SubscriptionCredentialRevision::query()->create(['tenant_id' => $tenant->id, 'installation_id' => $installation->id, 'revision' => 1, 'status' => 'active', 'verified_at' => now(),
        'credentials' => ['access_token' => 'private-access-token-'.$key], 'credential_fingerprint' => SubscriptionEvidence::digest($key), 'digest_key_id' => SubscriptionEvidence::keyId(),
        'app_secret' => $secret, 'app_secret_reference' => 'test:surface-'.$key, 'app_secret_revision' => 'current']);
    $installation->update(['active_credential_revision_id' => $revision->id]);

    return [$installation, $revision];
}

function subscriptionSurfaceResource($test, string $gid = 'gid://shopify/SubscriptionContract/1101', string $completeness = 'complete'): SubscriptionProviderResource
{
    $operation = SubscriptionOperation::query()->create(['tenant_id' => $test->tenant->id, 'installation_id' => $test->installation->id, 'credential_revision_id' => $test->revision->id, 'actor_user_id' => $test->actor->id,
        'request_uuid' => (string) Str::uuid(), 'kind' => 'owned_contract_sync', 'request_fingerprint' => str_repeat('a', 64), 'digest_key_id' => SubscriptionEvidence::keyId(), 'request' => [], 'status' => 'completed']);

    return SubscriptionProviderResource::query()->create(['tenant_id' => $test->tenant->id, 'installation_id' => $test->installation->id, 'sync_operation_id' => $operation->id, 'resource_type' => 'contract',
        'provider_gid' => $gid, 'status' => 'PAUSED', 'completeness' => $completeness, 'observed_at' => now(), 'provider_revision' => '18446744073709551615',
        'snapshot' => ['email' => 'private-subscriber@example.test', 'address' => '99 Hidden Address Road', 'action_url' => 'https://private.example.test/token-secret'],
    ]);
}

function subscriptionSurfaceRequest($test, string $body = '{"id":1101,"email":"private-subscriber@example.test"}', array $headers = [], ?string $secret = null, ?string $publicId = null)
{
    $secret ??= 'explicit-current-secret-32-bytes!!';
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $test->installation->shop_domain,
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'delivery-1101', 'HTTP_X_SHOPIFY_EVENT_ID' => 'event-1101', 'HTTP_X_SHOPIFY_TOPIC' => 'subscription_contracts/update', 'HTTP_X_SHOPIFY_API_VERSION' => '2026-10'];

    return $test->call('POST', 'https://app.theeverbranch.com/webhooks/subscriptions/'.($publicId ?? $test->installation->public_id), [], [], [], array_replace($server, $headers), $body);
}

test('native subscription workspace renders offline saved references without private snapshots or credential material', function (): void {
    subscriptionSurfaceResource($this);
    $this->get($this->url)->assertOk()->assertSeeText('History and setup review')->assertSeeText('#1101')->assertSeeText('Paused')
        ->assertDontSee('private-subscriber@example.test')->assertDontSee('99 Hidden Address Road')->assertDontSee('token-secret')
        ->assertDontSee('private-access-token')->assertDontSee('explicit-current-secret')->assertDontSee('hidden-installation-evidence@example.test')
        ->assertViewHas('readiness', fn (array $r) => $r['owned_contract_count'] === null && ! $r['provider_execution_enabled']);
    expect(app(SubscriptionProviderReadService::class)->readiness($this->tenant->id, $this->installation->id)['owned_contract_count'])->toBeNull();
});

test('workspace authority uses active membership and canonical commercial entitlement', function (string $denial): void {
    if ($denial === 'member') {
        $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    } elseif ($denial === 'inactive_membership') {
        $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    } elseif ($denial === 'inactive_user') {
        $this->actor->update(['is_active' => false]);
    } else {
        TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->update($denial === 'pending_billing' ? ['billing_status' => 'pending_billing'] : ['enabled_status' => 'disabled']);
        app()->forgetInstance(TenantModuleAccessResolver::class);
    }
    $this->get($this->url)->assertForbidden()->assertDontSeeText('Surface merchant')->assertDontSeeText($this->installation->shop_domain);
})->with(['member', 'inactive_membership', 'inactive_user', 'pending_billing', 'disabled']);

test('installation selectors are tenant scoped and unsupported selectors do not expose other saved history', function (): void {
    $other = Tenant::query()->create(['name' => 'Other merchant', 'slug' => 'other-merchant']);
    [$foreign] = subscriptionSurfaceBinding($other, 'foreign');
    $this->get($this->url.'&installation='.$foreign->id)->assertNotFound()->assertDontSeeText($foreign->shop_domain);
    $this->get($this->url.'&installation=999999')->assertNotFound();
    $this->get('https://app.theeverbranch.com/subscriptions?tenant=other-merchant')->assertForbidden()->assertDontSeeText('Other merchant');
});

test('incomplete or failed reads remain unknown while a complete empty observation displays zero', function (): void {
    subscriptionSurfaceResource($this, completeness: 'incomplete');
    $this->installation->update(['sync_state' => ['last_failure' => ['code' => 'provider_read_failed']]]);
    $this->get($this->url)->assertOk()->assertViewHas('readiness', fn (array $r) => $r['owned_contract_count'] === null && $r['freshness'] === 'never_synced')
        ->assertSeeText('An incomplete or failed read does not establish an empty contract list.')->assertSeeText('Requires review');
    $this->installation->update(['last_synced_at' => now(), 'sync_state' => ['last_complete' => ['owned_contract_count' => 0, 'credential_revision_id' => $this->revision->id, 'started_at' => now()->toISOString()], 'last_failure' => null]]);
    $this->get($this->url)->assertOk()->assertViewHas('readiness', fn (array $r) => $r['owned_contract_count'] === 0)
        ->assertSeeText('From the last complete verified read.');
});

test('workspace rejects unverified snapshots and permits scoped paused history with safe filter rendering', function (): void {
    subscriptionSurfaceResource($this);
    $this->get($this->url.'&status=paused')->assertOk()->assertSeeText('#1101');
    $this->get($this->url.'&status=active')->assertOk()->assertDontSeeText('#1101');
    $this->get($this->url.'&q='.urlencode('<script>alert(1)</script>'))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    $this->installation->update(['status' => 'pending_verification']);
    $this->get($this->url)->assertOk()->assertDontSeeText('#1101');
});

test('webhook HTTP verifies exact raw bytes before retaining authenticated paused evidence', function (): void {
    auth()->logout();
    $body = "{\n  \"id\": 1101, \"email\": \"private-subscriber@example.test\"\n}";
    $response = subscriptionSurfaceRequest($this, $body)->assertStatus(202)->assertHeader('Cache-Control');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $row = SubscriptionWebhookInbox::query()->sole();
    expect($row->raw_body)->toBe($body)->and($row->status)->toBe('quarantined')->and($row->quarantine_reason)->toBe('background_reads_paused')
        ->and($row->verification)->toBe(['app_secret_reference' => 'test:surface-one', 'app_secret_revision' => 'current'])
        ->and(DB::table('subscription_webhook_inboxes')->value('raw_body'))->not->toContain('private-subscriber@example.test');
    $signature = base64_encode(hash_hmac('sha256', $body, 'explicit-current-secret-32-bytes!!', true));
    subscriptionSurfaceRequest($this, $body.' ', ['HTTP_X_SHOPIFY_HMAC_SHA256' => $signature])->assertUnauthorized();
    expect(SubscriptionWebhookInbox::query()->count())->toBe(1);
});

test('unknown opaque IDs wrong shops invalid signatures topics and delivery IDs reject before durable intake', function (string $failure): void {
    $headers = [];
    $id = null;
    if ($failure === 'unknown_uuid') {
        $id = (string) Str::uuid();
        $status = 404;
    } elseif ($failure === 'numeric_id') {
        $id = (string) $this->installation->id;
        $status = 404;
    } elseif ($failure === 'wrong_shop') {
        $headers['HTTP_X_SHOPIFY_SHOP_DOMAIN'] = 'other.myshopify.com';
        $status = 401;
    } elseif ($failure === 'signature') {
        $headers['HTTP_X_SHOPIFY_HMAC_SHA256'] = 'invalid';
        $status = 401;
    } elseif ($failure === 'topic') {
        $headers['HTTP_X_SHOPIFY_TOPIC'] = 'orders/create';
        $status = 422;
    } else {
        $headers['HTTP_X_SHOPIFY_WEBHOOK_ID'] = '';
        $status = 422;
    }
    subscriptionSurfaceRequest($this, headers: $headers, publicId: $id)->assertStatus($status);
    expect(SubscriptionWebhookInbox::query()->count())->toBe(0);
})->with(['unknown_uuid', 'numeric_id', 'wrong_shop', 'signature', 'topic', 'delivery']);

test('token only setup cannot use a global secret and reports setup incomplete', function (): void {
    [$installation,$revision] = subscriptionSurfaceBinding($this->tenant, 'token-only', null);
    $this->installation = $installation;
    config()->set('services.shopify.stores.retail.client_secret', 'explicit-current-secret-32-bytes!!');
    subscriptionSurfaceRequest($this)->assertStatus(503);
    expect(SubscriptionWebhookInbox::query()->count())->toBe(0)
        ->and(app(SubscriptionProviderReadService::class)->readiness($this->tenant->id, $installation->id)['webhook_setup_complete'])->toBeFalse();
});

test('current signing revision must be active pinned verified unexpired and not revoked', function (string $failure): void {
    if ($failure === 'revoked') {
        $this->revision->update(['revoked_at' => now()]);
    } elseif ($failure === 'not_verified') {
        $this->revision->update(['verified_at' => null]);
    } elseif ($failure === 'pending') {
        $this->revision->update(['status' => 'pending_verification']);
    } elseif ($failure === 'not_pinned') {
        $this->installation->update(['active_credential_revision_id' => null]);
    } else {
        [$this->installation] = subscriptionSurfaceBinding($this->tenant, 'expired', null);
        SubscriptionCredentialRevision::query()->create(['tenant_id' => $this->tenant->id, 'installation_id' => $this->installation->id, 'revision' => 2, 'status' => 'retired', 'verified_at' => now(), 'rotated_at' => now()->subMinutes(5), 'app_secret_expires_at' => now()->subSecond(), 'credentials' => ['access_token' => 'x'], 'credential_fingerprint' => str_repeat('a', 64), 'digest_key_id' => SubscriptionEvidence::keyId(), 'app_secret' => 'explicit-current-secret-32-bytes!!', 'app_secret_reference' => 'test:expired', 'app_secret_revision' => 'expired']);
    }
    subscriptionSurfaceRequest($this)->assertStatus(503);
    expect(SubscriptionWebhookInbox::query()->count())->toBe(0);
})->with(['revoked', 'not_verified', 'pending', 'not_pinned', 'expired']);

function subscriptionSurfacePrevious($test, array $overrides = []): SubscriptionCredentialRevision
{
    return SubscriptionCredentialRevision::query()->create(array_replace(['tenant_id' => $test->tenant->id, 'installation_id' => $test->installation->id, 'revision' => 2, 'status' => 'retired', 'verified_at' => now()->subHour(), 'rotated_at' => now()->subMinutes(5), 'app_secret_expires_at' => now()->addMinutes(5),
        'credentials' => ['access_token' => 'old-private-token'], 'credential_fingerprint' => str_repeat('b', 64), 'digest_key_id' => SubscriptionEvidence::keyId(),
        'app_secret' => 'explicit-previous-secret-32-bytes!', 'app_secret_reference' => 'test:surface-one', 'app_secret_revision' => 'previous'], $overrides));
}

test('explicit previous signing window accepts only until its exact expiry and records original verification revision', function (): void {
    subscriptionSurfacePrevious($this);
    subscriptionSurfaceRequest($this, secret: 'explicit-previous-secret-32-bytes!')->assertStatus(202);
    expect(SubscriptionWebhookInbox::query()->sole()->verification['app_secret_revision'])->toBe('previous');
    $this->travel(6)->minutes();
    subscriptionSurfaceRequest($this, headers: ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'later-delivery'], secret: 'explicit-previous-secret-32-bytes!')->assertUnauthorized();
    expect(SubscriptionWebhookInbox::query()->count())->toBe(1);
});

test('previous secret requires a bounded approved rotation window and matching installation', function (string $failure): void {
    $values = match ($failure) {
        'no_rotation' => ['rotated_at' => null],'future_rotation' => ['rotated_at' => now()->addMinute()],'overlong' => ['app_secret_expires_at' => now()->addDay()],'revoked' => ['revoked_at' => now()],default => []
    };
    if ($failure === 'foreign') {
        [$foreign] = subscriptionSurfaceBinding($this->tenant, 'different-app', null);
        $values['installation_id'] = $foreign->id;
    }
    subscriptionSurfacePrevious($this, $values);
    subscriptionSurfaceRequest($this, secret: 'explicit-previous-secret-32-bytes!')->assertUnauthorized();
    expect(SubscriptionWebhookInbox::query()->count())->toBe(0);
})->with(['no_rotation', 'future_rotation', 'overlong', 'revoked', 'foreign']);

test('duplicate delivery and conflicting payload preserve encrypted original and signing revision without executing work', function (): void {
    config()->set('subscriptions.provider_reads_enabled', true);
    config()->set('subscriptions.provider_read_tenant_ids', [(int) $this->tenant->id]);
    config()->set('subscriptions.background_reads_enabled', true);
    $body = '{"id":1101,"email":"private-subscriber@example.test"}';
    subscriptionSurfaceRequest($this, $body)->assertOk();
    subscriptionSurfaceRequest($this, $body)->assertOk();
    subscriptionSurfacePrevious($this);
    subscriptionSurfaceRequest($this, $body, secret: 'explicit-previous-secret-32-bytes!')->assertOk();
    subscriptionSurfaceRequest($this, '{"id":1101,"email":"different-private@example.test"}', secret: 'explicit-previous-secret-32-bytes!')->assertStatus(202);
    $row = SubscriptionWebhookInbox::query()->sole();
    expect($row->raw_body)->toBe($body)->and($row->verification['app_secret_revision'])->toBe('current')->and($row->status)->toBe('quarantined')
        ->and($row->conflict_evidence['verification']['app_secret_revision'])->toBe('previous')
        ->and($row->quarantine_reason)->toBe('conflicting_delivery')->and($row->conflict_evidence['raw_body'])->toContain('different-private@example.test');
    subscriptionSurfaceRequest($this, $body, ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'another-delivery'])->assertOk();
    expect(SubscriptionWebhookInbox::query()->where('event_id', 'event-1101')->count())->toBe(2)
        ->and(DB::table('subscription_webhook_inboxes')->value('conflict_evidence'))->not->toContain('different-private@example.test');
});

test('authentic unsupported API malformed and paused entitlement deliveries quarantine without provider reads', function (string $condition): void {
    $headers = [];
    $body = '{"id":1101}';
    if ($condition === 'unsupported') {
        $headers['HTTP_X_SHOPIFY_API_VERSION'] = '2026-01';
        $reason = 'unsupported_api_version';
    } elseif ($condition === 'malformed') {
        $body = '{';
        $reason = 'malformed_payload';
    } else {
        config()->set('subscriptions.provider_reads_enabled', true);
        config()->set('subscriptions.provider_read_tenant_ids', [(int) $this->tenant->id]);
        config()->set('subscriptions.background_reads_enabled', true);
        TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->update(['enabled_status' => 'disabled']);
        app()->forgetInstance(TenantModuleAccessResolver::class);
        $reason = 'entitlement_paused';
    }
    subscriptionSurfaceRequest($this, $body, $headers)->assertStatus(202);
    expect(SubscriptionWebhookInbox::query()->sole()->quarantine_reason)->toBe($reason);
})->with(['unsupported', 'malformed', 'entitlement']);

test('repeated token revisions of one signing key cannot evict an approved distinct previous secret', function (): void {
    subscriptionSurfacePrevious($this);
    foreach ([3, 4, 5, 6] as $number) {
        SubscriptionCredentialRevision::query()->create(['tenant_id' => $this->tenant->id, 'installation_id' => $this->installation->id, 'revision' => $number, 'status' => 'retired', 'verified_at' => now()->subHour(), 'rotated_at' => now()->subMinutes(2), 'app_secret_expires_at' => now()->addMinutes(5),
            'credentials' => ['access_token' => 'copied-token-'.$number], 'credential_fingerprint' => str_repeat('c', 64), 'digest_key_id' => SubscriptionEvidence::keyId(),
            'app_secret' => 'explicit-current-secret-32-bytes!!', 'app_secret_reference' => 'test:surface-one', 'app_secret_revision' => 'current']);
    }
    subscriptionSurfaceRequest($this, secret: 'explicit-previous-secret-32-bytes!')->assertStatus(202);
    expect(SubscriptionWebhookInbox::query()->sole()->verification['app_secret_revision'])->toBe('previous');
    subscriptionSurfaceRequest($this, headers: ['HTTP_X_SHOPIFY_WEBHOOK_ID' => 'current-still-pinned'])->assertStatus(202);
    expect(SubscriptionWebhookInbox::query()->where('delivery_id', 'current-still-pinned')->sole()->verification['app_secret_revision'])->toBe('current');
});

test('native workspace labels in-progress checks separately from the last complete owned count', function (): void {
    $this->installation->update(['last_synced_at' => now()->subMinutes(5), 'sync_state' => [
        'last_complete' => ['owned_contract_count' => 7, 'credential_revision_id' => $this->revision->id, 'started_at' => now()->subMinutes(6)->toISOString()],
        'in_progress' => ['phase' => 'verify', 'count' => 12, 'started_at' => now()->toISOString()],
    ]]);
    $this->get($this->url)->assertOk()->assertSeeText('Synchronization in progress')->assertSeeText('12 contracts checked so far.')
        ->assertSeeText('The saved total updates after the entire verification finishes.')
        ->assertViewHas('readiness', fn (array $r) => $r['owned_contract_count'] === 7 && $r['sync_progress']['phase'] === 'verify' && $r['sync_progress']['staged_contract_count'] === 12);
});
