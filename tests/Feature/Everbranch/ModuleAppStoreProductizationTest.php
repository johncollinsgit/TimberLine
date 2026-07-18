<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSetupStatus;
use App\Models\User;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
});

function moduleStoreTenant(string $slug = 'module-store-tenant', string $plan = 'starter', string $mode = 'direct'): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug,
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => $plan,
        'operating_mode' => $mode,
        'source' => 'test',
    ]);

    return $tenant;
}

function moduleStoreUser(Tenant $tenant): User
{
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $user->tenants()->attach($tenant->id, ['role' => 'manager']);

    return $user;
}

test('tenant module store payload exposes product grade metadata for visible modules', function (): void {
    $tenant = moduleStoreTenant();

    $payload = app(TenantModuleCatalogService::class)->tenantStorePayload($tenant->id, 'marketing');
    $sms = collect((array) ($payload['modules'] ?? []))
        ->firstWhere('module_key', 'sms');

    expect($sms)->toBeArray()
        ->and($sms['category_label'])->toBe('Integrations')
        ->and($sms['lifecycle_label'])->toBe('Beta')
        ->and($sms['setup_effort_label'])->toBe('Everbranch-assisted setup')
        ->and($sms['required_integrations_label'])->toBe('No required integration')
        ->and($sms['pricing_impact_label'])->toContain('checkout is not active here')
        ->and($sms['entitlement_requirement_label'])->toContain('Requires add-on access')
        ->and($sms['tenant_visibility_label'])->toBe('Visible in tenant App Store')
        ->and($sms['mobile_relevance_label'])->toBe('Not mobile-specific')
        ->and($sms['buyer_setup'])->toMatchArray([
            'outcome' => 'Send customer text messages through a tenant-controlled SMS provider setup.',
            'primary_action' => 'Configure SMS',
        ])
        ->and($sms['buyer_setup']['what_you_need'] ?? [])->not->toBeEmpty()
        ->and($sms['buyer_setup']['setup_steps'] ?? [])->not->toBeEmpty();
});

test('order calendar is a public purchasable add-on with canonical pricing', function (): void {
    $tenant = moduleStoreTenant('order-calendar-store');
    $payload = app(TenantModuleCatalogService::class)->tenantStorePayload($tenant->id, 'marketing');
    $module = collect((array) ($payload['modules'] ?? []))->firstWhere('module_key', 'workflow_automations');

    expect($module)->toBeArray()
        ->and($module['display_name'])->toBe('Order Calendar')
        ->and($module['status'])->toBe('live')
        ->and($module['billing_mode'])->toBe('add_on')
        ->and(data_get($module, 'purchase.addon_key'))->toBe('order_calendar')
        ->and(data_get($module, 'purchase.purchase_key'))->toBe('addon.order_calendar')
        ->and(data_get($module, 'purchase.recurring_price_cents'))->toBe(2900)
        ->and(data_get($module, 'purchase.price_display'))->toBe('$29.00/month')
        ->and(config('commercial.addons.order_calendar.modules'))->toBe(['workflow_automations'])
        ->and(config('commercial.stripe_mapping.addons.order_calendar.recurring_price_lookup_key'))->toBe('addon_order_calendar_monthly');
});

test('quickbooks is an opt-in reusable branch with guided owner setup', function (): void {
    $tenant = moduleStoreTenant('quickbooks-branch-tenant');
    $user = moduleStoreUser($tenant);

    $payload = app(TenantModuleCatalogService::class)->tenantStorePayload($tenant->id, 'marketing');
    $quickBooks = collect((array) ($payload['modules'] ?? []))
        ->firstWhere('module_key', 'quickbooks');

    expect($quickBooks)->toBeArray()
        ->and($quickBooks['status'])->toBe('beta')
        ->and($quickBooks['module_state']['enabled'] ?? true)->toBeFalse()
        ->and($quickBooks['module_state']['cta'] ?? null)->toBe('add')
        ->and($quickBooks['buyer_setup']['primary_action'] ?? null)->toBe('Connect QuickBooks')
        ->and(data_get($quickBooks, 'visibility.mobile_store'))->toBeFalse();

    $result = app(TenantModuleCatalogService::class)->activateModuleForTenant(
        tenantId: (int) $tenant->id,
        moduleKey: 'quickbooks',
        actorId: (int) $user->id,
        source: 'test'
    );

    expect($result['ok'] ?? false)->toBeTrue()
        ->and(TenantModuleEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_key', 'quickbooks')
            ->where('enabled_status', 'enabled')
            ->exists())->toBeTrue();
});

test('app store visible safe to market modules define buyer setup copy in config', function (): void {
    $requiredKeys = ['outcome', 'best_for', 'what_you_need', 'next_step', 'setup_steps', 'primary_action', 'help_text'];

    foreach ((array) config('module_catalog.modules', []) as $moduleKey => $definition) {
        if (! is_array($definition)) {
            continue;
        }

        $isAppStoreVisible = (bool) data_get($definition, 'visibility.app_store', false);
        $isSafeToMarket = strtoupper(trim((string) ($definition['market_state'] ?? ''))) === 'SAFE_TO_MARKET';
        $isLiveOrBeta = in_array(strtolower(trim((string) ($definition['status'] ?? ''))), ['live', 'beta'], true);

        if (! ($isAppStoreVisible && $isSafeToMarket && $isLiveOrBeta)) {
            continue;
        }

        $buyerSetup = is_array($definition['buyer_setup'] ?? null) ? (array) $definition['buyer_setup'] : [];

        foreach ($requiredKeys as $key) {
            expect($buyerSetup, "Missing buyer_setup.{$key} for {$moduleKey}")->toHaveKey($key);
        }

        expect(trim((string) ($buyerSetup['outcome'] ?? '')), "Empty buyer_setup.outcome for {$moduleKey}")->not->toBe('')
            ->and(trim((string) ($buyerSetup['best_for'] ?? '')), "Empty buyer_setup.best_for for {$moduleKey}")->not->toBe('')
            ->and(trim((string) ($buyerSetup['next_step'] ?? '')), "Empty buyer_setup.next_step for {$moduleKey}")->not->toBe('')
            ->and(trim((string) ($buyerSetup['primary_action'] ?? '')), "Empty buyer_setup.primary_action for {$moduleKey}")->not->toBe('')
            ->and(trim((string) ($buyerSetup['help_text'] ?? '')), "Empty buyer_setup.help_text for {$moduleKey}")->not->toBe('')
            ->and((array) ($buyerSetup['what_you_need'] ?? []), "Empty buyer_setup.what_you_need for {$moduleKey}")->not->toBeEmpty()
            ->and((array) ($buyerSetup['setup_steps'] ?? []), "Empty buyer_setup.setup_steps for {$moduleKey}")->not->toBeEmpty();
    }
});

test('tenant app store hides draft internal unsafe and deprecated modules', function (): void {
    config()->set('module_catalog.modules.pr7_draft_probe', [
        'display_name' => 'PR7 Draft Probe',
        'description' => 'Should never appear.',
        'status' => 'draft',
        'market_state' => 'SAFE_TO_MARKET',
        'channels' => ['both'],
        'classification' => 'shared-core',
        'included_in_plans' => [],
        'billing_mode' => 'included',
        'visibility' => ['public_site' => true, 'app_store' => true],
    ]);
    config()->set('module_catalog.modules.pr7_internal_probe', [
        'display_name' => 'PR7 Internal Probe',
        'description' => 'Should never appear.',
        'status' => 'live',
        'market_state' => 'INTERNAL_ONLY',
        'channels' => ['both'],
        'classification' => 'internal-admin',
        'included_in_plans' => [],
        'billing_mode' => 'included',
        'visibility' => ['public_site' => true, 'app_store' => true],
    ]);
    config()->set('module_catalog.modules.pr7_deprecated_probe', [
        'display_name' => 'PR7 Deprecated Probe',
        'description' => 'Should never appear.',
        'status' => 'deprecated',
        'market_state' => 'SAFE_TO_MARKET',
        'channels' => ['both'],
        'classification' => 'shared-core',
        'included_in_plans' => [],
        'billing_mode' => 'included',
        'visibility' => ['public_site' => true, 'app_store' => true],
    ]);

    $tenant = moduleStoreTenant();
    $payload = app(TenantModuleCatalogService::class)->tenantStorePayload($tenant->id, 'marketing');
    $names = collect((array) ($payload['modules'] ?? []))
        ->pluck('display_name')
        ->all();

    expect($names)->not->toContain('PR7 Draft Probe')
        ->and($names)->not->toContain('PR7 Internal Probe')
        ->and($names)->not->toContain('PR7 Deprecated Probe')
        ->and($names)->toContain('SMS');
});

test('tenant module store renders metadata as guidance without billing checkout controls', function (): void {
    $tenant = moduleStoreTenant();
    $user = moduleStoreUser($tenant);

    $this->actingAs($user)
        ->get(route('marketing.modules'))
        ->assertOk()
        ->assertSeeText('Workspace feature catalog')
        ->assertSeeText('What this does')
        ->assertSeeText('Best next step')
        ->assertSeeText('What you need before setup')
        ->assertSeeText('Setup steps')
        ->assertSeeText('Send customer text messages through a tenant-controlled SMS provider setup.')
        ->assertSeeText('Plan and setup details')
        ->assertSeeText('Pricing: Add-on pricing label only; checkout is not active here')
        ->assertDontSeeText('Checkout')
        ->assertDontSeeText('Pay now')
        ->assertDontSeeText('Candle Club')
        ->assertDontSeeText('Candle Cash')
        ->assertDontSeeText('Modern Forestry');
});

test('module interests remain separate from installed or entitled modules', function (): void {
    $tenant = moduleStoreTenant();
    TenantSetupStatus::query()->create([
        'tenant_id' => $tenant->id,
        'business_profile_status' => 'ready',
        'import_path' => 'manual',
        'shopify_connection_status' => 'not_connected',
        'square_status' => 'not_requested',
        'csv_manual_status' => 'requested',
        'module_interests' => ['sms'],
        'mobile_interest' => 'none',
        'landlord_review_status' => 'pending_review',
    ]);

    $payload = app(TenantModuleCatalogService::class)->tenantStorePayload($tenant->id, 'marketing');
    $sms = collect((array) ($payload['modules'] ?? []))->firstWhere('module_key', 'sms');

    expect($sms)->toBeArray()
        ->and($sms['module_state']['enabled'] ?? true)->toBeFalse()
        ->and($sms['module_state']['cta'] ?? null)->toBe('add')
        ->and(TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->where('module_key', 'sms')->exists())->toBeFalse();
});

test('shopify embedded app store renders safe metadata and keeps billing language passive', function (): void {
    $tenant = moduleStoreTenant('shopify-module-store-tenant', 'starter', 'shopify');
    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.store', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Checkout not active here')
        ->assertSeeText('What this does')
        ->assertSeeText('Best next step')
        ->assertSeeText('What you need before setup')
        ->assertSeeText('Send customer text messages through a tenant-controlled SMS provider setup.')
        ->assertSeeText('Pricing: Add-on pricing label only; checkout is not active here')
        ->assertSeeText('SMS')
        ->assertDontSeeText('Future Niche Modules')
        ->assertDontSeeText('Candle Club')
        ->assertDontSeeText('Candle Cash')
        ->assertDontSeeText('Modern Forestry');
});

test('landlord commercial module table shows internal visibility context read only', function (): void {
    $host = parse_url(route('landlord.commercial.index'), PHP_URL_HOST) ?: 'app.theeverbranch.com';
    config()->set('tenancy.landlord.primary_host', $host);
    config()->set('tenancy.landlord.hosts', [$host]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get("http://{$host}/landlord/commercial")
        ->assertOk()
        ->assertSeeText('tenant App Store visibility still fails closed')
        ->assertSeeText('Tenant-visible')
        ->assertSeeText('Hidden from tenants')
        ->assertSeeText('Lifecycle')
        ->assertSeeText('Integrations')
        ->assertSeeText('Pricing');
});
