<?php

use App\Models\CandleCashTransaction;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\User;
use Illuminate\Support\Carbon;

function commercialAnalyticsLandlordHost(): string
{
    $host = parse_url(route('landlord.commercial.index'), PHP_URL_HOST);

    return is_string($host) && $host !== '' ? strtolower($host) : 'app.grovebud.com';
}

function makeLandlordOperator(): User
{
    return User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

function seedLandlordAnalyticsTenant(
    string $name,
    string $slug,
    string $planKey,
    bool $withSmsAddon = false,
    int $teamUsers = 0
): Tenant {
    $tenant = Tenant::query()->create([
        'name' => $name,
        'slug' => $slug,
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => $planKey,
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    if ($withSmsAddon) {
        TenantAccessAddon::query()->create([
            'tenant_id' => $tenant->id,
            'addon_key' => 'sms',
            'enabled' => true,
            'source' => 'test',
        ]);
    }

    if ($teamUsers > 0) {
        User::factory()->count($teamUsers)->create()->each(function (User $user) use ($tenant): void {
            $tenant->users()->attach($user->id, ['role' => 'manager']);
        });
    }

    return $tenant;
}

function seedTenantActivityFixtures(Tenant $tenant): array
{
    $profileA = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => Carbon::parse('2026-03-27 09:15:00'),
        'updated_at' => Carbon::parse('2026-03-27 09:15:00'),
    ]);

    $profileB = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'created_at' => Carbon::parse('2026-03-29 11:30:00'),
        'updated_at' => Carbon::parse('2026-03-29 11:30:00'),
    ]);

    Order::factory()->create([
        'tenant_id' => $tenant->id,
        'ordered_at' => Carbon::parse('2026-03-29 00:00:00'),
        'created_at' => Carbon::parse('2026-03-29 10:00:00'),
        'updated_at' => Carbon::parse('2026-03-29 10:00:00'),
        'total_price' => 120.50,
    ]);

    Order::factory()->create([
        'tenant_id' => $tenant->id,
        'ordered_at' => Carbon::parse('2026-03-30 00:00:00'),
        'created_at' => Carbon::parse('2026-03-30 13:00:00'),
        'updated_at' => Carbon::parse('2026-03-30 13:00:00'),
        'total_price' => 79.00,
    ]);

    MarketingAutomationEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profileA->id,
        'trigger_key' => 'campaign_open',
        'channel' => 'email',
        'status' => 'processed',
        'occurred_at' => Carbon::parse('2026-03-30 09:00:00'),
        'created_at' => Carbon::parse('2026-03-30 09:00:00'),
        'updated_at' => Carbon::parse('2026-03-30 09:00:00'),
    ]);

    MarketingAutomationEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profileB->id,
        'trigger_key' => 'reward_view',
        'channel' => 'shopify',
        'status' => 'processed',
        'occurred_at' => Carbon::parse('2026-03-31 08:00:00'),
        'created_at' => Carbon::parse('2026-03-31 08:00:00'),
        'updated_at' => Carbon::parse('2026-03-31 08:00:00'),
    ]);

    MarketingEmailDelivery::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profileB->id,
        'provider' => 'sendgrid',
        'email' => (string) $profileB->email,
        'status' => 'sent',
        'sent_at' => Carbon::parse('2026-03-31 08:30:00'),
        'created_at' => Carbon::parse('2026-03-31 08:30:00'),
        'updated_at' => Carbon::parse('2026-03-31 08:30:00'),
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profileA->id,
        'type' => 'redeem',
        'candle_cash_delta' => -15,
        'points' => -15,
        'source' => 'reward',
        'source_id' => 'reward-1',
        'description' => 'Redeemed reward',
        'created_at' => Carbon::parse('2026-03-31 07:00:00'),
        'updated_at' => Carbon::parse('2026-03-31 07:00:00'),
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profileB->id,
        'type' => 'redeem',
        'candle_cash_delta' => -5,
        'points' => -5,
        'source' => 'reward',
        'source_id' => 'reward-2',
        'description' => 'Redeemed reward',
        'created_at' => Carbon::parse('2026-03-31 11:00:00'),
        'updated_at' => Carbon::parse('2026-03-31 11:00:00'),
    ]);

    return [
        'profiles' => [$profileA, $profileB],
    ];
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 12:00:00'));

    $host = commercialAnalyticsLandlordHost();
    config()->set('tenancy.landlord.primary_host', $host);
    config()->set('tenancy.landlord.hosts', [$host]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('landlord commercial page bootstraps tenant management rows and apex chart wiring', function (): void {
    $host = commercialAnalyticsLandlordHost();
    $tenant = seedLandlordAnalyticsTenant('Atlas Forest Co', 'atlas-forest', 'growth', true, 2);
    seedTenantActivityFixtures($tenant);
    $user = makeLandlordOperator();

    $this->actingAs($user)
        ->get("http://{$host}/landlord/commercial")
        ->assertOk()
        ->assertSeeText('Tenant table')
        ->assertSeeText('Monthly subscription')
        ->assertSeeText('Sales generated to date')
        ->assertSeeText('Rewards redeemed to date')
        ->assertSeeText('Customers onboarded')
        ->assertSeeText('Atlas Forest Co')
        ->assertSeeText('Advanced Diagnostics')
        ->assertSeeText('Bulk Email Marketing')
        ->assertSee('cdn.jsdelivr.net/npm/apexcharts')
        ->assertSee('table_endpoint')
        ->assertSee('activity_endpoint');
});

test('landlord analytics tenants endpoint returns required tenant table fields and supports filtering sorting and pagination', function (): void {
    $host = commercialAnalyticsLandlordHost();
    $atlas = seedLandlordAnalyticsTenant('Atlas Forest Co', 'atlas-forest', 'growth', true, 2);
    seedTenantActivityFixtures($atlas);
    $quiet = seedLandlordAnalyticsTenant('Birch Trail', 'birch-trail', 'starter', false, 1);
    $user = makeLandlordOperator();

    $response = $this->actingAs($user)
        ->getJson("http://{$host}/landlord/commercial/analytics/tenants?plan=growth&search=atlas&sort=monthly_subscription_cents&direction=desc&page=1&per_page=1");

    $response
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('data.0.id', $atlas->id)
        ->assertJsonPath('data.0.name', 'Atlas Forest Co')
        ->assertJsonPath('data.0.plan_key', 'growth')
        ->assertJsonPath('data.0.monthly_subscription_cents', 34800)
        ->assertJsonPath('data.0.subscription_income_to_date_cents', 34800)
        ->assertJsonPath('data.0.sales_generated_cents', 19950)
        ->assertJsonPath('data.0.rewards_redeemed_cents', 2000)
        ->assertJsonPath('data.0.customers_onboarded', 2)
        ->assertJsonPath('data.0.team_user_count', 2)
        ->assertJsonPath('summary.result_count', 1)
        ->assertJsonPath('summary.filters.plan', 'growth');

    expect($response->json('data.0.last_active_at'))->not->toBeNull();
    expect($response->json('data.0.module_revenue_breakdown'))->toBeArray()->not->toBeEmpty();
    expect($quiet->id)->not->toBe($atlas->id);
});

test('landlord analytics activity endpoint returns grouped currency time series with correct totals', function (): void {
    $host = commercialAnalyticsLandlordHost();
    $tenant = seedLandlordAnalyticsTenant('Atlas Forest Co', 'atlas-forest', 'growth', true, 2);
    seedTenantActivityFixtures($tenant);
    $user = makeLandlordOperator();

    $response = $this->actingAs($user)
        ->getJson("http://{$host}/landlord/commercial/analytics/activity?tenant={$tenant->id}&dataset=revenue&metric=sales_generated&range=30d&group_by=day");

    $response
        ->assertOk()
        ->assertJsonPath('chart.unit', 'currency')
        ->assertJsonPath('chart.metric', 'sales_generated')
        ->assertJsonPath('chart.xaxis_type', 'datetime')
        ->assertJsonCount(30, 'chart.buckets')
        ->assertJsonPath('chart.total', 19950);

    $currentSeries = $response->json('chart.series.0.data');
    $sum = collect($currentSeries)->sum(fn (array $point): int => (int) ($point['y'] ?? 0));

    expect($sum)->toBe(19950);
    expect($response->json('chart.buckets.0'))->toHaveKeys(['label', 'start_at', 'end_at']);
});

test('landlord analytics activity endpoint returns integer metrics with requested grouping', function (): void {
    $host = commercialAnalyticsLandlordHost();
    $tenant = seedLandlordAnalyticsTenant('Atlas Forest Co', 'atlas-forest', 'growth', true, 2);
    seedTenantActivityFixtures($tenant);
    $user = makeLandlordOperator();

    $response = $this->actingAs($user)
        ->getJson("http://{$host}/landlord/commercial/analytics/activity?tenant={$tenant->id}&dataset=customer_activity&metric=users_onboarded&range=90d&group_by=week");

    $response
        ->assertOk()
        ->assertJsonPath('chart.unit', 'count')
        ->assertJsonPath('chart.metric', 'users_onboarded')
        ->assertJsonPath('chart.xaxis_type', 'datetime')
        ->assertJsonPath('chart.total', 2);

    $currentSeries = $response->json('chart.series.0.data');
    expect(collect($currentSeries)->sum(fn (array $point): int => (int) ($point['y'] ?? 0)))->toBe(2);
    expect(count((array) $response->json('chart.buckets')))->toBeGreaterThan(1);
});

test('landlord analytics activity endpoint returns module revenue grouping and subscription income formatting paths', function (): void {
    $host = commercialAnalyticsLandlordHost();
    $tenant = seedLandlordAnalyticsTenant('Atlas Forest Co', 'atlas-forest', 'growth', true, 2);
    seedTenantActivityFixtures($tenant);
    $user = makeLandlordOperator();

    $moduleResponse = $this->actingAs($user)
        ->getJson("http://{$host}/landlord/commercial/analytics/activity?tenant={$tenant->id}&dataset=module_revenue&metric=module_revenue&range=30d&group_by=month");

    $moduleResponse
        ->assertOk()
        ->assertJsonPath('chart.unit', 'currency')
        ->assertJsonPath('chart.chart_type', 'bar')
        ->assertJsonPath('chart.stacked', true)
        ->assertJsonPath('chart.xaxis_type', 'category')
        ->assertJsonPath('chart.total', 34800);

    expect(collect((array) $moduleResponse->json('chart.series'))->pluck('name')->all())
        ->toContain('Growth', 'SMS');

    $subscriptionResponse = $this->actingAs($user)
        ->getJson("http://{$host}/landlord/commercial/analytics/activity?tenant={$tenant->id}&dataset=revenue&metric=subscription_income&range=30d&group_by=day");

    $subscriptionResponse
        ->assertOk()
        ->assertJsonPath('chart.unit', 'currency')
        ->assertJsonPath('chart.total', 34800)
        ->assertJsonPath('chart.delta_label', '0%')
        ->assertJsonPath('chart.delta_tone', 'neutral')
        ->assertJsonPath('chart.empty_state', null);

    $subscriptionSeries = $subscriptionResponse->json('chart.series.0.data');
    expect($subscriptionSeries)->toBeArray()->not->toBeEmpty();
    expect(collect($subscriptionSeries)->contains(fn (array $point): bool => (int) ($point['y'] ?? 0) === 34800))->toBeTrue();
});

test('landlord analytics endpoints return safe empty states for zero-data and no-match filters', function (): void {
    $host = commercialAnalyticsLandlordHost();
    $tenant = seedLandlordAnalyticsTenant('Quiet Pine', 'quiet-pine', 'starter', false, 0);
    $user = makeLandlordOperator();

    $tableResponse = $this->actingAs($user)
        ->getJson("http://{$host}/landlord/commercial/analytics/tenants?search=does-not-exist");

    $tableResponse
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('summary.result_count', 0);

    $activityResponse = $this->actingAs($user)
        ->getJson("http://{$host}/landlord/commercial/analytics/activity?tenant={$tenant->id}&dataset=revenue&metric=sales_generated&range=30d&group_by=day");

    $activityResponse
        ->assertOk()
        ->assertJsonPath('chart.total', 0)
        ->assertJsonPath('chart.previous_total', 0)
        ->assertJsonPath('chart.empty_state', 'No Sales generated data is available for the current filter and time window yet.');

    $salesSeries = $activityResponse->json('chart.series.0.data');
    expect($salesSeries)->toBeArray()->not->toBeEmpty();
    expect(collect($salesSeries)->every(fn (array $point): bool => (int) ($point['y'] ?? 0) === 0))->toBeTrue();
});

test('landlord analytics endpoints remain forbidden to non landlord operators', function (): void {
    $host = commercialAnalyticsLandlordHost();
    $tenant = seedLandlordAnalyticsTenant('Atlas Forest Co', 'atlas-forest', 'growth', true, 2);
    seedTenantActivityFixtures($tenant);
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson("http://{$host}/landlord/commercial/analytics/tenants?tenant={$tenant->id}")
        ->assertForbidden();

    $this->actingAs($user)
        ->getJson("http://{$host}/landlord/commercial/analytics/activity?tenant={$tenant->id}")
        ->assertForbidden();
});
