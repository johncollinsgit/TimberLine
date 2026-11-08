<?php

use App\Models\ClientProject;
use App\Models\ClientProjectTicket;
use App\Models\ClientProjectTicketTask;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\Dashboard\UnifiedDashboardService;
use App\Services\FieldService\QuickBooksOwnerReportingService;
use App\Services\Reporting\SalesChannelSummaryService;
use App\Services\Search\Providers\ActionsSearchProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
});

function dashboardActionTenant(array $metadata = []): Tenant
{
    $tenant = Tenant::create(['name' => 'Fictional action workspace', 'slug' => 'actions-'.Str::lower(Str::random(8))]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct', 'source' => 'test', 'metadata' => $metadata]);

    return $tenant;
}

function dashboardActionRequest(Tenant $tenant, User $user): Request
{
    $request = Request::create('/app/search?tenant='.$tenant->slug);
    $request->attributes->set('current_tenant', $tenant);
    $request->setUserResolver(fn () => $user);
    test()->actingAs($user);

    return $request;
}

test('lightweight action lookup matches the full dashboard for existing role and catalog permissions', function ($role) {
    $tenant = dashboardActionTenant();
    $user = User::factory()->create(['role' => $role]);
    $user->tenants()->attach($tenant, ['role' => 'owner', 'membership_active' => true]);
    Order::create(['tenant_id' => $tenant->id, 'order_number' => 'ACTION-1', 'status' => 'reviewed', 'total_price' => 500]);
    $request = dashboardActionRequest($tenant, $user);
    $dashboard = app(UnifiedDashboardService::class);
    $full = $dashboard->forRequest($request, $user)['next_actions'];
    expect($dashboard->nextActionsForRequest($request, $user))->toBe($full);
    if ($role === 'manager') {
        expect(array_column($full, 'label'))->not->toContain('Send Message to All Opted-In Customers', 'Open customers');
    }
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
})->with(['admin', 'marketing_manager', 'manager']);

test('search action lookup avoids dashboard financial services aggregate queries and customer sends', function () {
    $tenant = dashboardActionTenant();
    $user = User::factory()->create(['role' => 'admin']);
    $user->tenants()->attach($tenant, ['role' => 'owner', 'membership_active' => true]);
    Order::create(['tenant_id' => $tenant->id, 'order_number' => 'ACTION-2', 'status' => 'reviewed', 'total_price' => 500]);
    $request = dashboardActionRequest($tenant, $user);
    $this->mock(QuickBooksOwnerReportingService::class)->shouldNotReceive('report');
    $this->mock(SalesChannelSummaryService::class)->shouldNotReceive('forTenant');
    DB::flushQueryLog();
    DB::enableQueryLog();
    $results = app(ActionsSearchProvider::class)->search('', ['request' => $request, 'user' => $user]);
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($query) => strtolower($query));
    DB::disableQueryLog();
    expect($results)->not->toBeEmpty()
        ->and($queries->filter(fn ($query) => str_contains($query, 'sum(') || str_contains($query, 'marketing_storefront_events') || str_contains($query, 'website_orders'))->all())->toBe([])
        ->and($queries->filter(fn ($query) => ! str_starts_with($query, 'select') && ! str_starts_with($query, 'pragma'))->all())->toBe([]);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

test('action eligibility counts stay in the authenticated request tenant', function () {
    $tenant = dashboardActionTenant();
    $other = dashboardActionTenant();
    $user = User::factory()->create(['role' => 'manager']);
    $user->tenants()->attach($tenant, ['role' => 'owner', 'membership_active' => true]);
    Order::create(['tenant_id' => $tenant->id, 'order_number' => 'OWN-1', 'status' => 'reviewed']);
    for ($i = 0; $i < 7; $i++) {
        Order::create(['tenant_id' => $other->id, 'order_number' => 'FOREIGN-'.$i, 'status' => 'reviewed']);
    }
    $actions = app(UnifiedDashboardService::class)->nextActionsForRequest(dashboardActionRequest($tenant, $user), $user);
    $queue = collect($actions)->firstWhere('label', 'Review order queue');
    expect($queue['description'])->toStartWith('1 order ');
    expect(implode(' ', array_column($actions, 'description')))->not->toContain('7 orders');
});

test('website focused action lookup preserves checklist website eligibility and membership guards without commerce metrics', function () {
    $tenant = dashboardActionTenant(['workspace_focus' => 'website_sales']);
    TenantModuleState::create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'enabled_override' => true]);
    $user = User::factory()->create(['role' => 'manager']);
    $user->tenants()->attach($tenant, ['role' => 'admin', 'membership_active' => true]);
    $project = ClientProject::create(['tenant_id' => $tenant->id, 'title' => 'Launch', 'metadata' => ['checklist_enabled' => true]]);
    $ticket = ClientProjectTicket::create(['tenant_id' => $tenant->id, 'client_project_id' => $project->id, 'title' => 'Visible launch', 'problem_summary' => 'Launch', 'customer_visible' => true]);
    ClientProjectTicketTask::create(['tenant_id' => $tenant->id, 'client_project_ticket_id' => $ticket->id, 'title' => 'Review photos', 'owner_type' => 'client', 'status' => 'open']);
    $request = dashboardActionRequest($tenant, $user);
    $service = app(UnifiedDashboardService::class);
    $expected = $service->forRequest($request, $user)['next_actions'];
    DB::flushQueryLog();
    DB::enableQueryLog();
    $actual = $service->nextActionsForRequest($request, $user);
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($query) => strtolower($query));
    DB::disableQueryLog();
    expect($actual)->toBe($expected)->and(array_column($actual, 'label'))->toBe(['Continue the launch checklist', 'Review the website'])
        ->and($queries->filter(fn ($query) => str_contains($query, 'website_products') || str_contains($query, 'website_customers') || str_contains($query, 'website_orders'))->all())->toBe([]);
    $user->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
    expect(fn () => $service->nextActionsForRequest($request, $user))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('soccer focused action lookup stays empty and preserves admin coach membership requirements', function () {
    $tenant = dashboardActionTenant(['workspace_focus' => 'soccer']);
    config(['soccer.enabled' => true, 'soccer.tenant_slug' => $tenant->slug]);
    TenantModuleState::create(['tenant_id' => $tenant->id, 'module_key' => 'soccer', 'enabled_override' => true]);
    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $user->tenants()->attach($tenant, ['role' => 'admin', 'membership_active' => true]);
    $request = dashboardActionRequest($tenant, $user);
    $service = app(UnifiedDashboardService::class);
    expect($service->nextActionsForRequest($request, $user))->toBe($service->forRequest($request, $user)['next_actions'])->toBe([]);
    $user->tenants()->updateExistingPivot($tenant->id, ['role' => 'member']);
    expect(fn () => $service->nextActionsForRequest($request, $user))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
