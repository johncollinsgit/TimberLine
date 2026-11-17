<?php

use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\ProfitLossRefreshService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00 UTC'));
    config(['trajectory.enabled' => true, 'trajectory.goldapi_key' => null, 'services.quickbooks.api_base' => 'https://qbo-refresh.fixture.invalid', 'services.quickbooks.minor_version' => 75]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->tenant = Tenant::create(['name' => 'Fictional report refresh', 'slug' => 'fictional-report-refresh']);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($this->tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $this->space] = app(PilotService::class)->prepare($this->owner, $this->tenant, true);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'quickbooks', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included_in_plan', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    foreach (['integrations', 'quickbooks'] as $module) {
        TenantModuleState::create(['tenant_id' => $this->tenant->id, 'module_key' => $module, 'enabled_override' => true, 'setup_status' => 'configured']);
    }
    $this->connection = IntegrationConnection::create(['tenant_id' => $this->tenant->id, 'provider' => 'quickbooks', 'external_account_id' => 'fictional-company', 'external_account_secret' => 'fictional-realm', 'status' => 'connected', 'access_token' => 'fictional-access-token', 'expires_at' => now()->addHour()]);
    $this->input = ['from' => '2026-01-01', 'through' => '2026-01-31', 'accounting_basis' => 'cash'];
    $section = fn ($group, $amount) => ['type' => 'Section', 'group' => $group, 'Summary' => ['ColData' => [['value' => $group], ['value' => $amount]]]];
    $this->payload = ['Header' => ['ReportName' => 'ProfitAndLoss', 'ReportBasis' => 'Cash', 'StartPeriod' => '2026-01-01', 'EndPeriod' => '2026-01-31', 'Currency' => 'USD', 'SummarizeColumnsBy' => 'Total'],
        'Columns' => ['Column' => [['ColTitle' => '', 'ColType' => 'Account'], ['ColTitle' => 'Total', 'ColType' => 'Money']]],
        'Rows' => ['Row' => [$section('Income', '1000.00'), $section('COGS', '600.00'), $section('Expenses', '450.00'), $section('OtherIncome', '5.00'), $section('OtherExpenses', '25.00'), $section('NetIncome', '-70.00')]]];
    $this->onRead = null;
    $this->providerReads = 0;
    Http::preventStrayRequests();
    Http::fake(['https://qbo-refresh.fixture.invalid/*' => function () {
        $this->providerReads++;
        if ($this->onRead) {
            ($this->onRead)();
        }

        return Http::response($this->payload);
    }]);
    $this->url = '/trajectory/spaces/'.$this->space->id.'/profit-loss/refresh';
});

test('explicit report load performs provider GET only and saves the exact selected cash report', function (): void {
    expect(ProfitLossRefreshService::available($this->space))->toBeTrue();
    $this->actingAs($this->owner)->postJson($this->url, $this->input)->assertOk()->assertJsonPath('basis', 'cash')->assertJsonPath('period.start', '2026-01-01');
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/reports/ProfitAndLoss')
        && $request['accounting_method'] === 'Cash' && $request['start_date'] === '2026-01-01' && $request['end_date'] === '2026-01-31');
    Http::assertSentCount(1);
    $saved = QuickBooksReportingSnapshot::sole();
    expect($saved->tenant_id)->toBe($this->tenant->id)->and($saved->integration_connection_id)->toBe($this->connection->id)
        ->and($saved->metrics['accounting_method'])->toBe('Cash')->and($saved->metrics['source_report']['Currency'])->toBe('USD')->and((float) $saved->metrics['net_income'])->toBe(-70.0);
    $this->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard?range=custom&from=2026-01-01&through=2026-01-31&accounting_basis=cash')->assertOk()->assertJsonPath('business.profit_loss.net_cents', -7000);
    Http::assertSentCount(1); // Reloading the screen remains cache-only.
});

test('invalid source identities cannot persist a report', function (string $field, mixed $value): void {
    if ($field === 'columns') {
        $this->payload['Columns']['Column'][] = ['ColType' => 'Money'];
    } else {
        $this->payload['Header'][$field] = $value;
    }
    $this->actingAs($this->owner)->postJson($this->url, $this->input)->assertUnprocessable();
    expect(QuickBooksReportingSnapshot::count())->toBe(0);
})->with([['StartPeriod', '2026-01-02'], ['EndPeriod', '2026-02-01'], ['ReportBasis', 'Accrual'], ['Currency', null], ['SummarizeColumnsBy', 'Month'], ['columns', null]]);

test('revoked finance or source connection access during provider read prevents cache persistence', function (string $change): void {
    $this->onRead = function () use ($change): void {
        match ($change) {
            'membership' => $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]),
            'disconnect' => $this->connection->update(['status' => 'disconnected']),
            'company' => $this->connection->update(['external_account_secret' => 'different-fictional-company']),
            'module' => TenantModuleState::where('tenant_id', $this->tenant->id)->where('module_key', 'quickbooks')->update(['enabled_override' => false]),
        };
    };
    $this->actingAs($this->owner)->postJson($this->url, $this->input)->assertForbidden();
    expect(QuickBooksReportingSnapshot::count())->toBe(0);
})->with(['membership', 'disconnect', 'company', 'module']);

test('report source pins are tenant scoped and cannot change dates or basis', function (): void {
    $foreignTenant = Tenant::create(['name' => 'Other fictional company', 'slug' => 'other-fictional-company']);
    $foreign = QuickBooksReportingSnapshot::create(['tenant_id' => $foreignTenant->id, 'integration_connection_id' => $this->connection->id, 'range_key' => 'foreign', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'metrics' => ['accounting_method' => 'Cash'], 'observed_at' => now()]);
    $this->actingAs($this->owner)->postJson($this->url, [...$this->input, 'snapshot_id' => $foreign->id])->assertNotFound();
    $own = QuickBooksReportingSnapshot::create(['tenant_id' => $this->tenant->id, 'integration_connection_id' => $this->connection->id, 'range_key' => 'own', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'metrics' => ['accounting_method' => 'Accrual'], 'observed_at' => now()]);
    $this->postJson($this->url, [...$this->input, 'snapshot_id' => $own->id])->assertUnprocessable();
    Http::assertNothingSent();
});

test('unavailable access invalid controls and a shared provider lock never dispatch a report read', function (): void {
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($stranger)->postJson($this->url, $this->input)->assertForbidden();
    $this->actingAs($this->owner)->postJson($this->url, [...$this->input, 'accounting_basis' => 'bogus'])->assertUnprocessable();
    $this->postJson($this->url, [...$this->input, 'through' => '2025-01-01'])->assertUnprocessable();
    $this->postJson($this->url, [...$this->input, 'location' => 'other'])->assertUnprocessable();
    $lock = Cache::lock('quickbooks-sync:'.$this->connection->id, 45);
    $lock->get();
    try {
        $this->postJson($this->url, $this->input)->assertConflict();
    } finally {
        $lock->release();
    }
    Http::assertNothingSent();
});

test('expired credentials without a refresh token require reconnection and never dispatch a report read', function (): void {
    $this->connection->update(['expires_at' => now()->subMinute(), 'refresh_token' => null]);
    expect(ProfitLossRefreshService::available($this->space))->toBeFalse();
    $this->actingAs($this->owner)->postJson($this->url, $this->input)->assertUnprocessable();
    Http::assertNothingSent();
});

test('expired and expiring report access renews credentials normally before a financial GET', function (int $minutes): void {
    $this->connection->update(['expires_at' => now()->addMinutes($minutes), 'refresh_token' => 'fictional-refresh-token']);
    Http::fake(['https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response(['access_token' => 'renewed-access', 'refresh_token' => 'rotated-refresh', 'expires_in' => 3600])]);
    expect(ProfitLossRefreshService::available($this->space))->toBeTrue();
    $this->actingAs($this->owner)->postJson($this->url, $this->input)->assertOk();
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' && $request->method() === 'POST' && $request['grant_type'] === 'refresh_token');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/reports/ProfitAndLoss') && $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer renewed-access'));
    Http::assertSentCount(2);
    expect($this->connection->fresh()->refresh_token)->toBe('rotated-refresh')->and($this->connection->fresh()->expires_at->isFuture())->toBeTrue();
})->with([-1, 2]);

test('OAuth failures and authorization changes cannot persist credentials or retrieve a financial report', function (string $change): void {
    $this->connection->update(['expires_at' => now()->subMinute(), 'refresh_token' => 'fictional-refresh-token']);
    $oauthCalls = 0;
    Http::fake(['https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => function () use ($change, &$oauthCalls) {
        $oauthCalls++;
        match ($change) {
            'member' => $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]),
            'module' => TenantModuleState::where('tenant_id', $this->tenant->id)->where('module_key', 'quickbooks')->update(['enabled_override' => false]),
            'space' => $this->space->update(['kind' => 'household']),
            'disconnect' => $this->connection->update(['status' => 'disconnected']),
            'realm' => $this->connection->update(['external_account_secret' => 'reconnected-company']),
            default => null,
        };

        return Http::response(['access_token' => 'unsafe-access', 'refresh_token' => 'unsafe-refresh', 'expires_in' => 3600], $change === 'failure' ? 400 : 200);
    }]);
    $expected = match ($change) {
        'failure' => 502, 'disconnect', 'realm', 'space' => 409, default => 403
    };
    $this->actingAs($this->owner)->postJson($this->url, $this->input)->assertStatus($expected);
    expect($oauthCalls)->toBe(1)->and($this->providerReads)->toBe(0)->and(QuickBooksReportingSnapshot::count())->toBe(0)
        ->and($this->connection->fresh()->access_token)->toBe('fictional-access-token')->and($this->connection->fresh()->refresh_token)->toBe('fictional-refresh-token');
})->with(['failure', 'member', 'module', 'space', 'disconnect', 'realm']);

test('refreshing a selected source preserves currency and refuses a statement changed during retrieval', function (): void {
    $snapshot = QuickBooksReportingSnapshot::create(['tenant_id' => $this->tenant->id, 'integration_connection_id' => $this->connection->id, 'range_key' => 'selected', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'metrics' => ['accounting_method' => 'Cash', 'source_report' => ['Currency' => 'EUR']], 'observed_at' => now()]);
    $this->actingAs($this->owner)->postJson($this->url, [...$this->input, 'snapshot_id' => $snapshot->id])->assertUnprocessable();
    expect(QuickBooksReportingSnapshot::count())->toBe(1)->and($snapshot->fresh()->metrics['source_report']['Currency'])->toBe('EUR');
    $snapshot->update(['metrics' => ['accounting_method' => 'Cash', 'source_report' => ['Currency' => 'USD']]]);
    $this->onRead = fn () => $snapshot->update(['metrics' => ['accounting_method' => 'Cash', 'total_income' => '12345.00', 'source_report' => ['Currency' => 'USD']]]);
    $this->postJson($this->url, [...$this->input, 'snapshot_id' => $snapshot->id])->assertConflict();
    expect(QuickBooksReportingSnapshot::count())->toBe(1)->and($snapshot->fresh()->metrics['total_income'])->toBe('12345.00');
});

test('network failure and ambiguous companies cannot create or silently substitute source reports', function (): void {
    $this->onRead = fn () => throw new \Illuminate\Http\Client\ConnectionException('private-provider-diagnostic');
    $this->actingAs($this->owner)->postJson($this->url, $this->input)->assertStatus(502)->assertDontSee('private-provider-diagnostic');
    expect(QuickBooksReportingSnapshot::count())->toBe(0);
    $readsAfterFailure = $this->providerReads;
    expect($readsAfterFailure)->toBeGreaterThan(0);
    IntegrationConnection::create(['tenant_id' => $this->tenant->id, 'provider' => 'quickbooks', 'external_account_id' => 'other-fictional-company', 'status' => 'connected', 'access_token' => 'other-fictional-token', 'expires_at' => now()->addHour()]);
    $this->postJson($this->url, $this->input)->assertUnprocessable();
    expect($this->providerReads)->toBe($readsAfterFailure)->and(QuickBooksReportingSnapshot::count())->toBe(0);
});

test('an explicit refresh can repair unknown legacy basis only from the exact newly declared source report', function (): void {
    $snapshot = QuickBooksReportingSnapshot::create(['tenant_id' => $this->tenant->id, 'integration_connection_id' => $this->connection->id, 'range_key' => 'legacy-unknown-basis', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'metrics' => ['total_income' => '500.00'], 'observed_at' => now()->subDay()]);
    $response = $this->actingAs($this->owner)->postJson($this->url, [...$this->input, 'snapshot_id' => $snapshot->id])->assertOk()->assertJsonPath('basis', 'cash');
    $saved = QuickBooksReportingSnapshot::findOrFail($response->json('snapshot_id'));
    expect($saved->metrics['accounting_method'])->toBe('Cash')->and($saved->metrics['source_report']['Currency'])->toBe('USD');
});
