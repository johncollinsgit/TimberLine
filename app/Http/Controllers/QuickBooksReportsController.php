<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSetting;
use App\Models\Tenant;
use App\Services\FieldService\QuickBooksOwnerReportingService;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class QuickBooksReportsController extends Controller
{
    public function index(
        Request $request,
        Tenant $tenant,
        QuickBooksOwnerReportingService $reports,
        TenantFinancialAccess $access,
    ): View {
        abort_unless($access->allows($request->user(), $tenant), 403);
        $range = $request->validate(['range' => ['nullable', 'in:1d,1w,1m,30d,ytd']])['range'] ?? null;

        return view('quickbooks.reports', [
            'tenant' => $tenant,
            'report' => $reports->report($tenant, $range),
            'settings' => QuickBooksReportingSetting::query()->forTenantId((int) $tenant->id)->first(),
        ]);
    }

    public function updateSettings(Request $request, Tenant $tenant, TenantFinancialAccess $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), $tenant), 403);
        $validated = $request->validate([
            'scheduled_sync_enabled' => ['nullable', 'boolean'],
            'supplies_accounts' => ['nullable', 'string', 'max:5000'],
            'wage_accounts' => ['nullable', 'string', 'max:5000'],
            'contract_labor_accounts' => ['nullable', 'string', 'max:5000'],
            'owner_compensation_accounts' => ['nullable', 'string', 'max:5000'],
            'owner_compensation_adjustments' => ['nullable', 'string', 'max:10000'],
        ]);
        $connection = IntegrationConnection::query()->forTenantId((int) $tenant->id)
            ->where('provider', 'quickbooks')->where('status', IntegrationConnection::STATUS_CONNECTED)->latest('id')->first();
        abort_unless($connection, 422, 'Connect QuickBooks before saving reporting settings.');
        $adjustments = json_decode((string) ($validated['owner_compensation_adjustments'] ?? '{}'), true);
        abort_if(! is_array($adjustments), 422, 'Owner compensation adjustments must be a JSON object keyed by YYYY-MM.');

        QuickBooksReportingSetting::query()->updateOrCreate(
            ['tenant_id' => (int) $tenant->id],
            [
                'integration_connection_id' => (int) $connection->id,
                'scheduled_sync_enabled' => (bool) ($validated['scheduled_sync_enabled'] ?? false),
                'sync_cadence' => 'hourly',
                'supplies_account_mappings' => $this->labels($validated['supplies_accounts'] ?? ''),
                'wage_account_mappings' => $this->labels($validated['wage_accounts'] ?? ''),
                'contract_labor_account_mappings' => $this->labels($validated['contract_labor_accounts'] ?? ''),
                'owner_compensation_account_mappings' => $this->labels($validated['owner_compensation_accounts'] ?? ''),
                'owner_compensation_adjustments' => $adjustments,
                'mappings_reviewed_at' => now(),
                'mappings_reviewed_by_user_id' => (int) $request->user()->id,
            ]
        );

        return back()->with('status', 'QuickBooks reporting settings saved.');
    }

    public function refresh(Request $request, Tenant $tenant, TenantFinancialAccess $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), $tenant), 403);
        $connection = IntegrationConnection::query()->forTenantId((int) $tenant->id)
            ->where('provider', 'quickbooks')->where('status', IntegrationConnection::STATUS_CONNECTED)->latest('id')->first();
        abort_unless($connection, 422, 'Connect QuickBooks before refreshing.');

        $exit = Artisan::call('field-service:sync-quickbooks', [
            '--tenant' => $tenant->slug,
            '--connection-id' => (string) $connection->id,
        ]);

        return back()->with($exit === 0 ? 'status' : 'error', $exit === 0
            ? 'QuickBooks data refreshed. Reporting snapshots update when this report reloads.'
            : 'QuickBooks refresh did not complete. Review integration health before retrying.');
    }

    /** @return array<int,string> */
    protected function labels(string $value): array
    {
        return collect(preg_split('/[\r\n,]+/', $value) ?: [])
            ->map(fn (string $label): string => trim($label))->filter()->unique()->values()->all();
    }
}
