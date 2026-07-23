<?php

use App\Models\AccountingCloseItem;
use App\Models\AccountingProfile;
use App\Models\IntegrationConnection;
use App\Models\Order;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Accounting\AccountingCommandCenterService;
use App\Services\Accounting\AccountingDateRangeService;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\MonthlyCloseService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->withoutVite();
});

function accountingWorkspace(string $slug = 'accounting-workspace'): array
{
    $tenant = Tenant::query()->create(['name' => str($slug)->headline()->toString(), 'slug' => $slug]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    foreach (['integrations', 'quickbooks', 'accounting_command_center'] as $module) {
        TenantModuleEntitlement::query()->create([
            'tenant_id' => $tenant->id,
            'module_key' => $module,
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'included_in_plan',
            'entitlement_source' => 'test',
            'price_source' => 'catalog',
        ]);
    }
    $owner = User::factory()->tenantAdmin()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $member = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
    $member->tenants()->attach($tenant->id, ['role' => 'member']);

    return [$tenant, $owner, $member];
}

test('the reusable branch is registered with financial capabilities and disabled by default', function (): void {
    $module = config('module_catalog.modules.accounting_command_center');

    expect($module['display_name'])->toBe('Accounting Command Center')
        ->and($module['activation_policy'])->toBe('integration_required')
        ->and($module['default_enabled'])->toBeFalse()
        ->and($module['dependencies'])->toContain('quickbooks')
        ->and($module['capabilities'])->toContain(
            'accounting.dashboard',
            'accounting.transactions',
            'accounting.close',
            'accounting.compliance',
            'accounting.debt',
            'accounting.event_profitability',
            'accounting.settings',
        );
});

test('accounting ranges preserve the shared current month and support calendar choices', function (): void {
    $service = app(AccountingDateRangeService::class);
    $now = CarbonImmutable::parse('2026-07-23 12:00:00');

    expect($service->resolve('current_month', now: $now)['starts_at']->toDateString())->toBe('2026-07-01')
        ->and($service->resolve('previous_month', now: $now)['starts_at']->toDateString())->toBe('2026-06-01')
        ->and($service->resolve('quarter_to_date', now: $now)['starts_at']->toDateString())->toBe('2026-07-01')
        ->and($service->resolve('calendar_year', now: $now)['ends_at']->toDateString())->toBe('2026-12-31')
        ->and($service->resolve('previous_calendar_year', now: $now)['starts_at']->toDateString())->toBe('2025-01-01')
        ->and($service->resolve('custom', '2026-01-15', '2026-06-30', $now)['aggregation'])->toBe('month');
});

test('owners can open the command center while members and other tenants cannot receive financial data', function (): void {
    [$tenant, $owner, $member] = accountingWorkspace();
    app(AccountingSetupService::class)->applyPreset($tenant, 'modern-forestry');

    $this->actingAs($owner)
        ->get(route('accounting.index', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSeeText('Accounting Command Center')
        ->assertSee('<caption class="sr-only">Recent QuickBooks transactions for the selected period</caption>', false)
        ->assertSeeText('Google Drive is the preferred source of truth');

    $this->actingAs($member)
        ->get(route('accounting.index', ['tenant' => $tenant->slug]))
        ->assertForbidden();

    [$otherTenant, $otherOwner] = accountingWorkspace('other-accounting-workspace');
    $this->actingAs($otherOwner)
        ->get(route('accounting.index', ['tenant' => $tenant->slug]))
        ->assertForbidden();
    expect(AccountingProfile::query()->forTenantId($otherTenant->id)->count())->toBe(0);
});

test('quickbooks remains ledger truth and operational sources are never added to it', function (): void {
    [$tenant] = accountingWorkspace();
    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => hash('sha256', 'accounting-test'),
        'external_account_secret' => 'realm-accounting',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'access',
        'refresh_token' => 'refresh',
        'expires_at' => now()->addHour(),
    ]);
    $range = app(AccountingDateRangeService::class)->resolve('current_month');
    QuickBooksReportingSnapshot::query()->create([
        'tenant_id' => $tenant->id,
        'integration_connection_id' => $connection->id,
        'range_key' => 'accounting:current_month',
        'period_start' => $range['starts_at'],
        'period_end' => $range['ends_at'],
        'metrics' => [
            'accounting_method' => 'Accrual',
            'total_income' => 1000,
            'total_expenses' => 600,
            'net_income' => 400,
            'account_lines' => [],
        ],
        'observed_at' => now(),
    ]);
    Order::query()->create([
        'tenant_id' => $tenant->id,
        'shopify_order_id' => 'shopify-accounting-1',
        'order_type' => 'retail',
        'ordered_at' => now(),
        'total_price' => 500,
        'refund_total' => 0,
    ]);
    SquarePayment::query()->create([
        'tenant_id' => $tenant->id,
        'square_payment_id' => 'square-accounting-1',
        'amount_money' => 20000,
        'currency' => 'USD',
        'status' => 'COMPLETED',
        'created_at_source' => now(),
        'synced_at' => now(),
    ]);

    $payload = app(AccountingCommandCenterService::class)->dashboard($tenant, 'current_month');

    expect(data_get($payload, 'ledger.gross_income'))->toBe(1000.0)
        ->and(data_get($payload, 'ledger.expenses'))->toBe(600.0)
        ->and(data_get($payload, 'ledger.net_operating_result'))->toBe(400.0)
        ->and(data_get($payload, 'revenue_mix.streams.online.amount'))->toBe(500.0)
        ->and(data_get($payload, 'revenue_mix.streams.events.amount'))->toBe(200.0)
        ->and(data_get($payload, 'guardrails.operational_sources_added_to_ledger'))->toBeFalse()
        ->and(data_get($payload, 'revenue_mix.streams.online.percentage'))->toBeNull();
});

test('modern forestry preset identifies the live drive workbook but keeps mappings and deadlines unverified', function (): void {
    [$tenant] = accountingWorkspace('modern-forestry-accounting');
    $profile = app(AccountingSetupService::class)->applyPreset($tenant, 'modern-forestry');

    expect(data_get($profile->configuration, 'event_source.preferred_source'))->toBe('google_drive')
        ->and(data_get($profile->configuration, 'event_source.google_drive_file_id'))->toBe('1V9FAzTg6FA7tzEnGyDQDQ-OYgHqbxiBot9PWlj7txDw')
        ->and($profile->setup_status)->toBe('needs_review')
        ->and($profile->complianceTasks)->not->toBeEmpty()
        ->and($profile->complianceTasks->every(fn ($task): bool => $task->due_at === null && $task->confidence === 'unverified'))->toBeTrue();
});

test('monthly close generation is idempotent and completion can be reopened with an audit trail', function (): void {
    [$tenant, $owner] = accountingWorkspace();
    $service = app(MonthlyCloseService::class);
    $period = $service->forMonth($tenant, now());
    $again = $service->forMonth($tenant, now());

    expect($again->id)->toBe($period->id)
        ->and($period->items)->toHaveCount(count((array) config('accounting_command_center.monthly_close')));

    $item = $period->items->first();
    $service->setItemStatus($tenant, $item, $owner, true);
    expect($item->fresh()->status)->toBe('completed');

    $service->setItemStatus($tenant, $item->fresh(), $owner, false);
    expect($item->fresh()->status)->toBe('open')
        ->and(\App\Models\AccountingAuditEvent::query()->forTenantId($tenant->id)->count())->toBe(2);

    $other = Tenant::query()->create(['name' => 'Isolation', 'slug' => 'accounting-isolation']);
    expect(fn () => $service->setItemStatus($other, AccountingCloseItem::query()->findOrFail($item->id), $owner, true))->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});
