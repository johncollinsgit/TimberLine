<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\TenantDiscoveryPage;
use App\Models\TenantDiscoveryProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Services\Marketing\Email\TenantEmailSettingsService;
use App\Services\Tenancy\ModernForestryAlphaBootstrapService;
use App\Services\Tenancy\TenantModuleAccessResolver;

beforeEach(function (): void {
    config()->set('entitlements.default_plan', 'growth');
});

test('modern forestry alpha bootstrap reenables messaging, sendgrid, and twilio defaults', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    /** @var ModernForestryAlphaBootstrapService $service */
    $service = app(ModernForestryAlphaBootstrapService::class);
    $result = $service->ensureForTenant($tenant->id, 'retail', force: true);

    expect($result['applied'])->toBeTrue()
        ->and($result['tenant_id'])->toBe($tenant->id);

    $messagingEntitlement = TenantModuleEntitlement::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'messaging')
        ->first();
    $aiEntitlement = TenantModuleEntitlement::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'ai')
        ->first();

    $messagingState = TenantModuleState::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'messaging')
        ->first();
    $aiState = TenantModuleState::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'ai')
        ->first();

    $emailSettings = app(TenantEmailSettingsService::class)->forAdmin($tenant->id);
    $marketingSettings = TenantMarketingSetting::query()
        ->where('tenant_id', $tenant->id)
        ->where('key', 'candle_cash_integration_config')
        ->value('value');
    $discoveryProfile = TenantDiscoveryProfile::query()
        ->where('tenant_id', $tenant->id)
        ->first();
    $southCarolinaPage = TenantDiscoveryPage::query()
        ->where('tenant_id', $tenant->id)
        ->where('page_key', 'south-carolina-wholesale')
        ->first();
    $aiModule = app(TenantModuleAccessResolver::class)->module($tenant->id, 'ai');

    expect($messagingEntitlement)->not->toBeNull()
        ->and($messagingEntitlement->enabled_status)->toBe('enabled')
        ->and($messagingEntitlement->availability_status)->toBe('available')
        ->and($aiEntitlement)->not->toBeNull()
        ->and($aiEntitlement->enabled_status)->toBe('enabled')
        ->and($aiEntitlement->availability_status)->toBe('available')
        ->and($messagingState)->not->toBeNull()
        ->and($messagingState->setup_status)->toBe('configured')
        ->and($aiState)->not->toBeNull()
        ->and($aiState->setup_status)->toBe('configured')
        ->and((bool) $aiState->coming_soon_override)->toBeFalse()
        ->and($emailSettings['email_provider'])->toBe('sendgrid')
        ->and($emailSettings['email_enabled'])->toBeTrue()
        ->and($emailSettings['analytics_enabled'])->toBeTrue()
        ->and(in_array($emailSettings['provider_status'], ['configured', 'healthy'], true))->toBeTrue()
        ->and(is_array($marketingSettings))->toBeTrue()
        ->and(data_get($marketingSettings, 'sms_provider'))->toBe('twilio')
        ->and((bool) data_get($marketingSettings, 'sms_provider_enabled'))->toBeTrue();

    expect($discoveryProfile)->not->toBeNull()
        ->and((string) ($discoveryProfile?->primary_brand_name ?? ''))->toBe('Modern Forestry')
        ->and((string) ($discoveryProfile?->domain_map['primary_wholesale_domain'] ?? ''))->toBe('modernforestrywholesale.com')
        ->and($southCarolinaPage)->not->toBeNull()
        ->and($aiModule['has_access'])->toBeTrue()
        ->and($aiModule['ui_state'])->toBe('active');
});
