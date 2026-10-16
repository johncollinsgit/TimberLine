<?php

use App\Models\IntegrationConnection;
use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeResource;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionRechargeReadinessService;
use App\Services\Subscriptions\SubscriptionRechargeReadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/SubscriptionRechargeFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    config()->set('app.debug', false);
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.auth.flagship_hosts', ['app.theeverbranch.com']);
    config()->set('subscriptions.recharge_reads_enabled', false);
    config()->set('subscriptions.recharge_read_tenant_ids', []);
    $this->tenant = Tenant::query()->create(['name' => 'Fictional source review', 'slug' => 'recharge-workspace']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'subscriptions', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test_explicit', 'price_source' => 'test']);
    $this->actor = User::factory()->create(['role' => 'manager', 'requested_via' => 'customer_production', 'is_active' => true]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'manager', 'membership_active' => true]);
    $this->actingAs($this->actor);
    $this->url = 'https://app.theeverbranch.com/subscriptions/recharge?tenant='.$this->tenant->slug;
    $this->postUrl = fn ($action) => 'https://app.theeverbranch.com/subscriptions/recharge/'.$action.'?tenant='.$this->tenant->slug;
});

afterEach(function () {
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Queue::assertNothingPushed();
});

function rechargeWorkspaceCapture($test): SubscriptionRechargeConnection
{
    rechargeFake();
    config()->set('subscriptions.recharge_reads_enabled', true);
    config()->set('subscriptions.recharge_read_tenant_ids', [$test->tenant->id]);
    $source = IntegrationConnection::query()->create(['tenant_id' => $test->tenant->id, 'provider' => 'recharge', 'status' => 'pending', 'access_token' => 'PRIVATE_RECHARGE_TOKEN']);
    $reads = app(SubscriptionRechargeReadService::class);
    $id = $reads->verifyConnection($test->tenant->id, $test->actor, $source->id, rechargeExpected(), (string) Str::uuid())['readiness']['connection_id'];
    for ($i = 0; $i < 12; $i++) {
        $result = $reads->sync($test->tenant->id, $test->actor, $id, (string) Str::uuid());
        if ($result['sync_complete']) {
            break;
        }
    }
    expect($result['sync_complete'])->toBeTrue();
    config()->set('subscriptions.recharge_reads_enabled', false);

    return SubscriptionRechargeConnection::query()->findOrFail($id);
}

test('Recharge setup page is offline honest about missing capture and has no live controls', function () {
    $this->get($this->url)->assertOk()->assertSeeText('Prepare Recharge customers and settings')->assertSeeText('Source imports are paused')
        ->assertSeeText('Private source setup')->assertSeeText('Settings requiring source review')->assertSeeText('rollout is off');
    Http::assertNothingSent();
});

test('credential drafts are encrypted idempotent local records and are never flashed on invalid input', function () {
    $token = 'PRIVATE_SETUP_CANARY_1234567890';
    $uuid = (string) Str::uuid();
    $input = ['provider_store_id' => '9001', 'identifier' => 'reviewed-merchant', 'platform' => 'shopify', 'recharge_access_token' => $token, 'request_uuid' => $uuid];
    $this->post(($this->postUrl)('source'), $input)->assertRedirect();
    $this->post(($this->postUrl)('source'), $input)->assertRedirect();
    expect(IntegrationConnection::query()->count())->toBe(1)
        ->and(DB::table('integration_connections')->value('access_token'))->not->toContain($token)
        ->and(SubscriptionRechargeConnection::query()->count())->toBe(0);
    $this->get($this->url)->assertOk()->assertDontSee($token)->assertSeeText('Private draft #');
    $input['request_uuid'] = 'invalid';
    $this->post(($this->postUrl)('source'), $input)->assertRedirect()->assertSessionHasErrors('setup');
    expect(session()->getOldInput('recharge_access_token'))->toBeNull();
    Http::assertNothingSent();
});

test('workspace retains accepted source only and preparation preserves exact values without customer adoption', function () {
    $connection = rechargeWorkspaceCapture($this);
    $beforeRequests = count(Http::recorded());
    $legacy = DB::table('subscription_customers')->count();
    $crm = DB::table('marketing_profiles')->count();
    $native = DB::table('website_customers')->count();
    $generation = $connection->sync_state['last_complete']['generation_id'];
    $this->get($this->url)->assertOk()->assertSeeText('Customers captured')->assertSeeText('#101')->assertViewHas('summary', fn ($r) => $r['customer_count'] === 1 && ! $r['one_to_one_verified'] && ! $r['live_ready']);
    $this->post(($this->postUrl)('prepare'), ['connection_id' => $connection->id, 'generation_id' => $generation])->assertRedirect();
    $row = SubscriptionRechargeResource::query()->where('connection_id', $connection->id)->where('generation_id', $generation)->where('resource_type', 'subscription')->sole();
    expect($row->mapping['candidate']['price'])->toBe('19.9950')->and($row->mapping['candidate']['next_charge_scheduled_at'])->toBe('2026-11-01')
        ->and($row->mapping['field_values_preserved'])->toBeTrue()->and($row->mapping['executable'])->toBeFalse()
        ->and(DB::table('subscription_customers')->count())->toBe($legacy)->and(DB::table('marketing_profiles')->count())->toBe($crm)
        ->and(DB::table('website_customers')->count())->toBe($native);
    $this->get('https://app.theeverbranch.com/subscriptions/recharge/records/'.$row->id.'?tenant='.$this->tenant->slug)->assertOk()->assertSeeText('19.9950')->assertSeeText('2026-11-01')->assertSeeText('Same value')->assertDontSee('PRIVATE_');
    $again = app(SubscriptionRechargeReadinessService::class)->prepareBatch($this->tenant->id, $this->actor, $connection->id, $generation);
    expect($again['prepared_count'])->toBe(0)->and(count(Http::recorded()))->toBe($beforeRequests);
    Http::assertSent(fn ($r) => $r->method() === 'GET');
});

test('mapping review never invents vault authority or functional parity', function () {
    $connection = rechargeWorkspaceCapture($this);
    $service = app(SubscriptionRechargeReadinessService::class);
    $generation = $connection->sync_state['last_complete']['generation_id'];
    $service->prepareBatch($this->tenant->id, $this->actor, $connection->id, $generation);
    $method = SubscriptionRechargeResource::query()->where('generation_id', $generation)->where('resource_type', 'payment_method')->sole();
    expect($method->mapping_state)->toBe('unverified')->and($method->mapping['issues'])->toContain('provider_vault_authority_required');
    $summary = $service->summary($this->tenant->id, $this->actor, $connection->id);
    expect($summary['live_ready'])->toBeFalse()->and($summary['one_to_one_verified'])->toBeFalse()->and($summary['cutover_enabled'])->toBeFalse()
        ->and($summary['settings_gaps'])->toHaveKeys(['notifications', 'dunning', 'customer_portal', 'workflows', 'shipping_tax', 'payment_vault']);
});

test('foreign tenant source selection records and generation cannot expose or alter evidence', function () {
    $connection = rechargeWorkspaceCapture($this);
    $generation = $connection->sync_state['last_complete']['generation_id'];
    $row = SubscriptionRechargeResource::query()->where('generation_id', $generation)->where('resource_type', 'customer')->sole();
    $other = Tenant::query()->create(['name' => 'Other review', 'slug' => 'other-recharge-review']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $other->id, 'module_key' => 'subscriptions', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test_explicit', 'price_source' => 'test']);
    $this->actor->tenants()->attach($other->id, ['role' => 'manager', 'membership_active' => true]);
    $this->get('https://app.theeverbranch.com/subscriptions/recharge?tenant='.$other->slug.'&connection='.$connection->id)->assertNotFound();
    $this->get('https://app.theeverbranch.com/subscriptions/recharge/records/'.$row->id.'?tenant='.$other->slug)->assertNotFound();
    $this->post('https://app.theeverbranch.com/subscriptions/recharge/prepare?tenant='.$other->slug, ['connection_id' => $connection->id, 'generation_id' => $generation])->assertNotFound();
    expect($row->fresh()->mapping)->toBeNull();
    $this->post(($this->postUrl)('prepare'), ['connection_id' => $connection->id, 'generation_id' => (string) Str::uuid()])->assertStatus(409);
});

test('role membership and paid add-on access guard private preparation', function (string $denial) {
    if ($denial === 'role') {
        $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    }
    if ($denial === 'inactive') {
        $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    }
    if ($denial === 'paywall') {
        TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->update(['enabled_status' => 'disabled']);
        app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    }
    $this->get($this->url)->assertForbidden();
    $this->post(($this->postUrl)('source'), ['recharge_access_token' => 'PRIVATE_MUST_NOT_SAVE'])->assertForbidden();
    expect(IntegrationConnection::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['role', 'inactive', 'paywall']);

test('read gate prevents forged capture and verify requests before any provider HTTP', function () {
    $source = IntegrationConnection::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'recharge', 'status' => 'pending', 'access_token' => 'PRIVATE_NOT_SENT',
        'metadata' => ['subscription_shadow_setup' => true, 'expected_identity' => rechargeExpected()]]);
    $this->post(($this->postUrl)('verify'), ['source_id' => $source->id, 'request_uuid' => (string) Str::uuid()])->assertStatus(409);
    Http::assertNothingSent();
});

test('manual settings comparison is private offline exact and never live configuration', function () {
    $connection = rechargeWorkspaceCapture($this);
    $beforeRequests = count(Http::recorded());
    $manual = app(\App\Services\Subscriptions\SubscriptionRechargeManualSettingsService::class);
    $document = ['schema_version' => 1, 'expected_identity' => rechargeExpected(),
        'expected_merchant_origin' => 'https://fictional-source.admin.rechargeapps.com',
        'category' => 'payment_vault', 'source_url' => 'https://fictional-source.admin.rechargeapps.com/merchant/settings/payment',
        'observed_at' => '2026-10-16T02:00:00-04:00', 'expected_previous_version_id' => null,
        'fields' => ['legacy_payment_processor' => 'Braintree/Paypal', 'sandbox_mode' => false, 'recalculate_next_charge_date' => true, 'migration_status' => null],
        'required_fields' => ['legacy_payment_processor', 'sandbox_mode', 'recalculate_next_charge_date', 'migration_status', 'vault_ownership_evidence']];
    $receipt = $manual->record($this->tenant->id, $this->actor, $connection->id, (string) Str::uuid(), $document);
    expect($receipt['live_ready'])->toBeFalse()->and($receipt['missing_fields'])->toBe(['vault_ownership_evidence']);
    $this->get($this->url)->assertOk()->assertSeeText('Settings source and draft history')->assertSeeText('Compare saved settings');
    $path = 'https://app.theeverbranch.com/subscriptions/recharge/settings/payment_vault?connection='.$connection->id.'&tenant=';
    $this->get($path.$this->tenant->slug)->assertOk()->assertSeeText('Braintree/Paypal')->assertSeeText('false')->assertSeeText('true')->assertSeeText('null')
        ->assertSeeText('Same value')->assertSeeText('vault_ownership_evidence')->assertSeeText('migration_status')->assertSeeText('rollout is off');
    $other = Tenant::query()->create(['name' => 'Other settings', 'slug' => 'other-settings-review']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $other->id, 'module_key' => 'subscriptions', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test_explicit', 'price_source' => 'test']);
    $this->actor->tenants()->attach($other->id, ['role' => 'manager', 'membership_active' => true]);
    $this->get($path.$other->slug)->assertNotFound()->assertDontSee('Braintree/Paypal');
    $this->get(str_replace('payment_vault', 'legal', $path).$this->tenant->slug)->assertNotFound();
    expect(count(Http::recorded()))->toBe($beforeRequests)->and(DB::table('subscription_customers')->count())->toBe(0);
});
