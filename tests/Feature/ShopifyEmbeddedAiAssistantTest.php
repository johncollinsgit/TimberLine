<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Services\Tenancy\TenantModuleAccessResolver;

beforeEach(function (): void {
    $this->withoutVite();
});

test('assistant start page renders required tabs and human-review messaging when ai access is enabled', function () {
    $tenant = Tenant::query()->create([
        'name' => 'AI Enabled Tenant',
        'slug' => 'ai-enabled-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    TenantAccessAddon::query()->create([
        'tenant_id' => $tenant->id,
        'addon_key' => 'future_niche_modules',
        'enabled' => true,
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route('shopify.app.assistant.start', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('AI Assistant')
        ->assertSeeText('Start Here')
        ->assertSeeText('Top Opportunities')
        ->assertSeeText('Draft Campaigns')
        ->assertSeeText('Setup')
        ->assertSeeText('Activity')
        ->assertSeeText('Human Review')
        ->assertViewHas('pageSubnav', function (array $subnav): bool {
            $keys = collect($subnav)->pluck('key')->values()->all();

            return $keys === ['start', 'opportunities', 'drafts', 'setup', 'activity'];
        });
});

test('assistant pages are tenant and tier aware for locked tenants', function () {
    $tenant = Tenant::query()->create([
        'name' => 'AI Locked Tenant',
        'slug' => 'ai-locked-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.assistant.start', retailEmbeddedSignedQuery()))
        ->assertStatus(403)
        ->assertSeeText('AI Assistant is not unlocked for this tenant yet. Review plan and module access to continue.')
        ->assertSeeText('Review plans and module access');
});

test('modern forestry alpha bootstrap unlocks ai assistant on first request', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.assistant.start', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('AI Assistant')
        ->assertSeeText('Ready')
        ->assertDontSeeText('Review plans and module access');

    $module = app(TenantModuleAccessResolver::class)->module($tenant->id, 'ai');

    expect($module['has_access'])->toBeTrue()
        ->and($module['ui_state'])->toBe('active')
        ->and($module['setup_status'])->toBe('configured');
});
