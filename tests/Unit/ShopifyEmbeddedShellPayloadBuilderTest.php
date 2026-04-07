<?php

use App\Services\Shopify\ShopifyEmbeddedPageRegistry;
use App\Services\Shopify\ShopifyEmbeddedShellPayloadBuilder;
use App\Services\Shopify\ShopifyEmbeddedUrlGenerator;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function (): void {
    \Mockery::close();
});

test('shopify embedded shell payload builder memoizes tenant shell data within a request', function () {
    $request = Request::create('/shopify/app', 'GET');

    $displayLabelResolver = \Mockery::mock(TenantDisplayLabelResolver::class);
    $displayLabelResolver
        ->shouldReceive('resolve')
        ->once()
        ->with(42)
        ->andReturn([
            'labels' => [
                'rewards_label' => 'Loyalty',
            ],
        ]);

    $experienceProfileService = \Mockery::mock(TenantExperienceProfileService::class);
    $experienceProfileService
        ->shouldReceive('forTenant')
        ->once()
        ->with(42, null)
        ->andReturn([
            'workspace' => [
                'label' => 'Commerce',
                'command_placeholder' => 'Search workspace',
            ],
        ]);

    $moduleAccessResolver = \Mockery::mock(TenantModuleAccessResolver::class);
    $moduleAccessResolver
        ->shouldReceive('resolveForTenant')
        ->once()
        ->with(42, \Mockery::on(function (array $moduleKeys): bool {
            return in_array('customers', $moduleKeys, true)
                && in_array('rewards', $moduleKeys, true);
        }))
        ->andReturn([
            'modules' => [
                'customers' => ['reason' => 'ok'],
                'rewards' => ['reason' => 'ok'],
            ],
        ]);

    $registry = new ShopifyEmbeddedPageRegistry();
    $urlGenerator = new ShopifyEmbeddedUrlGenerator($registry);
    $builder = new ShopifyEmbeddedShellPayloadBuilder(
        $registry,
        $urlGenerator,
        $displayLabelResolver,
        $experienceProfileService,
        $moduleAccessResolver
    );

    $navigation = $builder->appNavigation('rewards', 'overview', 42, $request);
    $builder->customerSubnav('all', 42, $request);
    $builder->dashboardSubnav('overview', 42, $request);
    $builder->embeddedSearchResults('reward', 42, $request);
    $commandDocuments = collect((array) ($navigation['commandSearchDocuments'] ?? []));

    expect($navigation['workspaceLabel'] ?? null)->toBe('Commerce')
        ->and($navigation['displayLabels']['rewards_label'] ?? null)->toBe('Loyalty')
        ->and($commandDocuments->pluck('title')->contains('Settings'))->toBeTrue()
        ->and($commandDocuments->contains(fn (array $row): bool => ($row['id'] ?? null) === 'page:customers.detail'))
            ->toBeFalse();
});

test('shopify embedded shell payload builder picks up future pages from registry without controller changes', function () {
    $request = Request::create('/shopify/app', 'GET');

    $registry = new class extends ShopifyEmbeddedPageRegistry
    {
        public function pages(): array
        {
            $pages = parent::pages();
            $pages[] = [
                'key' => 'labs',
                'route_name' => 'shopify.app.integrations',
                'label' => 'Labs',
                'section' => 'labs',
                'group' => 'primary',
                'icon_key' => 'beaker',
                'module_key' => 'integrations',
                'searchable' => true,
                'search_badge' => 'Labs',
                'search_subtitle' => 'Try new embedded workflows.',
                'search_keywords' => ['labs', 'experiments'],
                'prefetch_priority' => 'low',
            ];

            return $pages;
        }
    };

    $displayLabelResolver = \Mockery::mock(TenantDisplayLabelResolver::class);
    $displayLabelResolver
        ->shouldReceive('resolve')
        ->once()
        ->with(99)
        ->andReturn(['labels' => []]);

    $experienceProfileService = \Mockery::mock(TenantExperienceProfileService::class);
    $experienceProfileService
        ->shouldReceive('forTenant')
        ->once()
        ->with(99, null)
        ->andReturn([
            'workspace' => [
                'label' => 'Commerce',
                'command_placeholder' => 'Search sections',
            ],
        ]);

    $moduleAccessResolver = \Mockery::mock(TenantModuleAccessResolver::class);
    $moduleAccessResolver
        ->shouldReceive('resolveForTenant')
        ->once()
        ->with(99, \Mockery::on(fn (array $moduleKeys): bool => in_array('integrations', $moduleKeys, true)))
        ->andReturn([
            'modules' => [
                'integrations' => ['reason' => 'ok'],
            ],
        ]);

    $builder = new ShopifyEmbeddedShellPayloadBuilder(
        $registry,
        new ShopifyEmbeddedUrlGenerator($registry),
        $displayLabelResolver,
        $experienceProfileService,
        $moduleAccessResolver
    );

    $navigation = $builder->appNavigation('labs', null, 99, $request);
    $searchResults = $builder->embeddedSearchResults('labs', 99, $request);
    $commandDocuments = is_array($navigation['commandSearchDocuments'] ?? null)
        ? $navigation['commandSearchDocuments']
        : [];

    $keys = collect((array) ($navigation['items'] ?? []))
        ->pluck('key')
        ->values()
        ->all();

    expect($keys)->toContain('labs')
        ->and(collect($searchResults)->pluck('title')->all())->toContain('Labs')
        ->and(collect($commandDocuments)->pluck('title')->all())->toContain('Labs');
});

test('shopify embedded shell payload builder returns messaging subnav entries from registry', function () {
    $request = Request::create('/shopify/app/messaging/analytics', 'GET');

    $displayLabelResolver = \Mockery::mock(TenantDisplayLabelResolver::class);
    $displayLabelResolver
        ->shouldReceive('resolve')
        ->once()
        ->with(77)
        ->andReturn(['labels' => []]);

    $experienceProfileService = \Mockery::mock(TenantExperienceProfileService::class);
    $experienceProfileService
        ->shouldReceive('forTenant')
        ->never();

    $moduleAccessResolver = \Mockery::mock(TenantModuleAccessResolver::class);
    $moduleAccessResolver
        ->shouldReceive('resolveForTenant')
        ->once()
        ->with(77, \Mockery::on(fn (array $moduleKeys): bool => in_array('messaging', $moduleKeys, true)))
        ->andReturn([
            'modules' => [
                'messaging' => ['reason' => 'ok', 'has_access' => true],
            ],
        ]);

    $registry = new ShopifyEmbeddedPageRegistry();
    $builder = new ShopifyEmbeddedShellPayloadBuilder(
        $registry,
        new ShopifyEmbeddedUrlGenerator($registry),
        $displayLabelResolver,
        $experienceProfileService,
        $moduleAccessResolver
    );

    $subnav = $builder->messagingSubnav('analytics', 77, $request);

    expect(collect($subnav)->pluck('key')->all())->toBe(['setup', 'workspace', 'analytics', 'responses'])
        ->and(collect($subnav)->firstWhere('key', 'analytics')['active'] ?? false)->toBeTrue();
});
