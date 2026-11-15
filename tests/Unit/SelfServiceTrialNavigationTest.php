<?php

use App\Http\Middleware\EnforceSelfServiceTrial;
use App\Models\Tenant;
use App\Services\Navigation\UnifiedAppNavigationService;
use App\Services\Onboarding\SelfServiceTrialService;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Support\Tenancy\TenantHostBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    URL::forceRootUrl('https://app.theeverbranch.com');
    URL::forceScheme('https');
});

function trialNavigationFilter(): UnifiedAppNavigationService
{
    return new class(Mockery::mock(AuthenticatedTenantContextResolver::class), Mockery::mock(TenantExperienceProfileService::class), Mockery::mock(TenantModuleAccessResolver::class), app(TenantHostBuilder::class)) extends UnifiedAppNavigationService
    {
        public function filterForTest(array $shell, Tenant $tenant, Request $request): array
        {
            return $this->trialNavigation($shell, $tenant, $request);
        }
    };
}

test('active and expired trial navigation retain useful local work while removing forbidden nested destinations', function (string $state): void {
    $tenant = (new Tenant)->forceFill(['id' => 19, 'slug' => 'fictional-private-studio']);
    $trials = Mockery::mock(SelfServiceTrialService::class);
    $trials->shouldReceive('unpaidTrialForTenant')->once()->with($tenant)->andReturn(true);
    $trials->shouldReceive('status')->once()->with($tenant)->andReturn(['state' => $state]);
    app()->instance(SelfServiceTrialService::class, $trials);
    $url = fn (string $name): string => route($name, ['tenant' => $tenant->slug]);
    $shell = [
        'items' => [
            ['key' => 'home', 'label' => 'Home', 'href' => $url('dashboard')],
            ['key' => 'customers', 'label' => 'Customers', 'href' => $url('customers.directory')],
            ['key' => 'website', 'label' => 'Website', 'href' => $url('managed-website.index'), 'children' => [
                ['label' => 'Shop', 'href' => $url('managed-website.products.index')],
                ['label' => 'Private work', 'href' => '#', 'children' => [
                    ['label' => 'Files', 'href' => $url('documents.index')],
                    ['label' => 'Connect payments', 'href' => $url('tenant-payments.index')],
                ]],
            ]],
            ['key' => 'account-help', 'label' => 'Help', 'href' => $url('account-help.index')],
        ],
        'admin_sub_items' => [['label' => 'Invite team', 'href' => $url('admin.users')]],
        'quick_actions' => [['label' => 'Connect Mail', 'href' => $url('mail.setup')]],
        'marketing_sub_groups' => [['label' => 'More', 'items' => [
            ['label' => 'Local customers', 'href' => $url('marketing.customers')],
            ['label' => 'Campaigns', 'href' => $url('marketing.campaigns')],
        ]]],
        'console_switches' => [['label' => 'External', 'href' => 'https://foreign.example/website']],
        'fixture_state' => $state,
    ];

    $result = trialNavigationFilter()->filterForTest($shell, $tenant, Request::create('https://app.theeverbranch.com/website'));

    expect($result['items'][0]['href'])->toBe($url('workspace.guide'))
        ->and($result['items'][1]['href'])->toBe($url('marketing.customers'))
        ->and($result['items'][2]['children'])->toHaveCount(1)
        ->and($result['items'][2]['children'][0]['href'])->toBe($url('documents.index'))
        ->and($result['admin_sub_items'])->toBe([])
        ->and($result['quick_actions'])->toBe([])
        ->and($result['console_switches'])->toBe([])
        ->and($result['marketing_sub_groups'][0]['items'])->toHaveCount(1)
        ->and(collect($result['items'])->firstWhere('key', 'account-help')['href'])->toBe($url('account-help.index'))
        ->and(collect($result['items'])->firstWhere('key', 'trial-plan')['href'])->toBe($url('self-service.purchase'))
        ->and(collect($result['items'])->firstWhere('key', 'trial-plan')['label'])->toBe($state === 'converted' ? 'Manage billing' : 'Activate plan');
    $check = function (array $items) use (&$check): void {
        foreach ($items as $item) {
            $route = Route::getRoutes()->match(Request::create($item['href'], 'GET'));
            expect(EnforceSelfServiceTrial::allowsReadRoute((string) $route->getName()))->toBeTrue();
            foreach (['children', 'items'] as $key) {
                $check($item[$key] ?? []);
            }
        }
    };
    $check($result['items']);
})->with(['active', 'expired', 'converted']);

test('paid and legacy navigation payloads pass through without changing any items or metadata', function (string $state): void {
    $tenant = (new Tenant)->forceFill(['id' => 19, 'slug' => 'fictional-existing-studio']);
    $trials = Mockery::mock(SelfServiceTrialService::class);
    $trials->shouldReceive('unpaidTrialForTenant')->once()->with($tenant)->andReturn(false);
    app()->instance(SelfServiceTrialService::class, $trials);
    $shell = ['items' => [['href' => 'https://external.example/unchanged', 'children' => [['label' => 'Existing']]]], 'custom_metadata' => ['state' => $state]];

    expect(trialNavigationFilter()->filterForTest($shell, $tenant, Request::create('https://app.theeverbranch.com/website')))->toBe($shell);
})->with(['paid', 'legacy']);
