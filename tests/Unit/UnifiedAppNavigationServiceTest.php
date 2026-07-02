<?php

use Tests\TestCase;
use App\Services\Navigation\UnifiedAppNavigationService;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Support\Tenancy\TenantHostBuilder;

uses(TestCase::class);

test('customer onboarding quick action stays hidden while the electrician tutorial feature is off', function (): void {
    $service = new class(
        Mockery::mock(AuthenticatedTenantContextResolver::class),
        Mockery::mock(TenantExperienceProfileService::class),
        Mockery::mock(TenantModuleAccessResolver::class),
        Mockery::mock(TenantHostBuilder::class)
    ) extends UnifiedAppNavigationService {
        public function exposedQuickActions(array $profile, bool $canAccessOps, bool $canAccessMarketing, ?int $tenantId): array
        {
            return $this->quickActions($profile, $canAccessOps, $canAccessMarketing, $tenantId);
        }
    };

    $actions = $service->exposedQuickActions(['use_case_profile' => 'ops'], true, false, 1);

    expect(collect($actions)->contains(fn (array $action): bool => ($action['label'] ?? '') === 'Setup plan'))->toBeFalse();
});

test('customer onboarding quick action returns when the electrician tutorial feature is enabled', function (): void {
    config()->set('features.customer_electrician_tutorial', true);

    $service = new class(
        Mockery::mock(AuthenticatedTenantContextResolver::class),
        Mockery::mock(TenantExperienceProfileService::class),
        Mockery::mock(TenantModuleAccessResolver::class),
        Mockery::mock(TenantHostBuilder::class)
    ) extends UnifiedAppNavigationService {
        public function exposedQuickActions(array $profile, bool $canAccessOps, bool $canAccessMarketing, ?int $tenantId): array
        {
            return $this->quickActions($profile, $canAccessOps, $canAccessMarketing, $tenantId);
        }
    };

    $actions = $service->exposedQuickActions(['use_case_profile' => 'ops'], true, false, 1);

    expect(collect($actions)->contains(fn (array $action): bool => ($action['label'] ?? '') === 'Setup plan'))->toBeTrue();
});
