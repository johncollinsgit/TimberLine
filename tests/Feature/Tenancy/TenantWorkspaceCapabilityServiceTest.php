<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantWorkspaceCapabilityService;

test('Modern Forestry retains its explicitly approved maker, retail, and legacy context', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);

    $context = app(TenantWorkspaceCapabilityService::class)->forTenant($tenant);

    expect($context['workspace_profile'])->toBe('maker_production')
        ->and($context['capability_packs'])->toContain('retail_commerce')
        ->and($context['legacy_overlays'])->toContain('modern_forestry_legacy')
        ->and($context['is_reviewed'])->toBeTrue();
});
test('Collins Electric is explicitly a field-service workspace', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);

    $context = app(TenantWorkspaceCapabilityService::class)->forTenant($tenant);

    expect($context['workspace_profile'])->toBe('field_service_trades')
        ->and($context['capability_packs'])->toContain('service_reputation')
        ->and($context['legacy_overlays'])->toBe([]);
});

test('an unreviewed workspace cannot gain retail access from entitlement or connection state', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Unreviewed Retail Claim', 'slug' => 'unreviewed-retail-claim']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'pro',
        'operating_mode' => 'shopify',
        'source' => 'test',
        'metadata' => [
            'tenant_blueprint' => [
                'workspace_profile' => 'retail_commerce',
                'capability_packs' => ['retail_commerce'],
                'blueprint_review_status' => 'needs_follow_up',
            ],
        ],
    ]);

    $context = app(TenantWorkspaceCapabilityService::class)->forTenant($tenant->fresh('accessProfile'));
    $resolved = app(TenantModuleAccessResolver::class)->resolveForTenant($tenant->id, ['rewards', 'reviews', 'wishlist', 'shopify']);

    expect($context['workspace_profile'])->toBe('generic_custom')
        ->and($context['is_reviewed'])->toBeFalse()
        ->and($resolved['modules']['rewards']['reason'])->toBe('workspace_not_supported')
        ->and($resolved['modules']['reviews']['reason'])->toBe('workspace_not_supported')
        ->and($resolved['modules']['wishlist']['reason'])->toBe('workspace_not_supported')
        ->and($resolved['modules']['shopify']['reason'])->toBe('workspace_not_supported');
});

test('a reviewed field-service selection enables only the compatible service pack', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Reviewed Service Team', 'slug' => 'reviewed-service-team']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => [
            'tenant_blueprint' => [
                'workspace_profile' => 'field_service_trades',
                'capability_packs' => ['service_reputation'],
                'blueprint_review_status' => 'reviewed',
            ],
        ],
    ]);

    $resolved = app(TenantModuleAccessResolver::class)->resolveForTenant($tenant->id, ['field_service', 'service_reviews', 'rewards', 'reviews']);

    expect($resolved['modules']['field_service']['enabled'])->toBeTrue()
        ->and($resolved['modules']['service_reviews']['enabled'])->toBeTrue()
        ->and($resolved['modules']['rewards']['reason'])->toBe('workspace_not_supported')
        ->and($resolved['modules']['reviews']['reason'])->toBe('workspace_not_supported');
});
