<?php

use App\Services\Marketing\Email\TenantEmailSettingsService;
use App\Services\Marketing\TwilioSenderConfigService;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function (): void {
    Cache::flush();
    \Mockery::close();
});

test('merchant journey payload is cached for the same tenant within ttl', function () {
    config()->set('cache.default', 'array');
    config()->set('shopify_embedded.journey_cache_ttl_seconds', 60);

    $accessResolver = \Mockery::mock(TenantModuleAccessResolver::class);
    $accessResolver->shouldReceive('resolveForTenant')
        ->once()
        ->with(42, \Mockery::type('array'))
        ->andReturn([
            'plan_key' => 'starter',
            'operating_mode' => 'shopify',
            'modules' => [],
        ]);

    $labelResolver = \Mockery::mock(TenantDisplayLabelResolver::class);
    $labelResolver->shouldReceive('moduleLabels')->zeroOrMoreTimes()->andReturn([]);
    $labelResolver->shouldReceive('resolve')->once()->with(42)->andReturn([
        'labels' => [],
        'source' => 'global_fallback',
        'template_missing' => false,
    ]);

    $service = new TenantCommercialExperienceService(
        $accessResolver,
        \Mockery::mock(LandlordCommercialConfigService::class),
        $labelResolver,
        \Mockery::mock(TenantEmailSettingsService::class),
        \Mockery::mock(TwilioSenderConfigService::class)
    );

    $first = $service->merchantJourneyPayload(42);
    $second = $service->merchantJourneyPayload(42);

    expect($second)->toBe($first);
});

test('merchant journey payload cache is tenant scoped', function () {
    config()->set('cache.default', 'array');
    config()->set('shopify_embedded.journey_cache_ttl_seconds', 60);

    $accessResolver = \Mockery::mock(TenantModuleAccessResolver::class);
    $accessResolver->shouldReceive('resolveForTenant')
        ->twice()
        ->with(\Mockery::on(fn ($value) => in_array($value, [42, 43], true)), \Mockery::type('array'))
        ->andReturn([
            'plan_key' => 'starter',
            'operating_mode' => 'shopify',
            'modules' => [],
        ]);

    $labelResolver = \Mockery::mock(TenantDisplayLabelResolver::class);
    $labelResolver->shouldReceive('moduleLabels')->zeroOrMoreTimes()->andReturn([]);
    $labelResolver->shouldReceive('resolve')->twice()->andReturn([
        'labels' => [],
        'source' => 'global_fallback',
        'template_missing' => false,
    ]);

    $service = new TenantCommercialExperienceService(
        $accessResolver,
        \Mockery::mock(LandlordCommercialConfigService::class),
        $labelResolver,
        \Mockery::mock(TenantEmailSettingsService::class),
        \Mockery::mock(TwilioSenderConfigService::class)
    );

    $tenant42 = $service->merchantJourneyPayload(42);
    $tenant43 = $service->merchantJourneyPayload(43);

    expect($tenant42['tenant_id'])->toBe(42)
        ->and($tenant43['tenant_id'])->toBe(43);
});

test('merchant journey payload cache expires after ttl', function () {
    config()->set('cache.default', 'array');
    config()->set('shopify_embedded.journey_cache_ttl_seconds', 1);

    $accessResolver = \Mockery::mock(TenantModuleAccessResolver::class);
    $accessResolver->shouldReceive('resolveForTenant')
        ->twice()
        ->with(42, \Mockery::type('array'))
        ->andReturn([
            'plan_key' => 'starter',
            'operating_mode' => 'shopify',
            'modules' => [],
        ]);

    $labelResolver = \Mockery::mock(TenantDisplayLabelResolver::class);
    $labelResolver->shouldReceive('moduleLabels')->zeroOrMoreTimes()->andReturn([]);
    $labelResolver->shouldReceive('resolve')->twice()->with(42)->andReturn([
        'labels' => [],
        'source' => 'global_fallback',
        'template_missing' => false,
    ]);

    $service = new TenantCommercialExperienceService(
        $accessResolver,
        \Mockery::mock(LandlordCommercialConfigService::class),
        $labelResolver,
        \Mockery::mock(TenantEmailSettingsService::class),
        \Mockery::mock(TwilioSenderConfigService::class)
    );

    $service->merchantJourneyPayload(42);
    sleep(2);
    $service->merchantJourneyPayload(42);

    expect(true)->toBeTrue();
});
