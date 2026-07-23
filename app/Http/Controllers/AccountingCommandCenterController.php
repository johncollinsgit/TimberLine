<?php

namespace App\Http\Controllers;

use App\Models\AccountingCloseItem;
use App\Models\Tenant;
use App\Services\Accounting\AccountingCommandCenterService;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Accounting\MonthlyCloseService;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountingCommandCenterController extends Controller
{
    public function index(
        Request $request,
        Tenant $tenant,
        AccountingCommandCenterService $commandCenter,
        TenantFinancialAccess $access,
    ): View {
        abort_unless($access->allows($request->user(), $tenant), 403);
        $validated = $request->validate([
            'range' => ['nullable', Rule::in(array_keys(app(\App\Services\Accounting\AccountingDateRangeService::class)->options()))],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
        ]);

        return view('accounting.index', [
            'tenant' => $tenant,
            'commandCenter' => $commandCenter->dashboard(
                $tenant,
                $validated['range'] ?? null,
                $validated['start'] ?? null,
                $validated['end'] ?? null,
            ),
        ]);
    }

    public function applyPreset(
        Request $request,
        Tenant $tenant,
        AccountingSetupService $setup,
        TenantFinancialAccess $access,
    ): RedirectResponse {
        abort_unless($access->allows($request->user(), $tenant), 403);
        $presetKeys = array_keys((array) config('accounting_command_center.presets', []));
        $validated = $request->validate(['preset' => ['required', Rule::in($presetKeys)]]);
        $setup->applyPreset($tenant, $validated['preset']);

        return back()->with('status', 'Accounting setup draft created. Review mappings and obligations before relying on it.');
    }

    public function updateCloseItem(
        Request $request,
        Tenant $tenant,
        AccountingCloseItem $item,
        MonthlyCloseService $monthlyClose,
        TenantFinancialAccess $access,
    ): RedirectResponse {
        abort_unless($access->allows($request->user(), $tenant), 403);
        $validated = $request->validate(['completed' => ['required', 'boolean']]);
        $monthlyClose->setItemStatus($tenant, $item, $request->user(), (bool) $validated['completed']);

        return back()->with('status', $validated['completed'] ? 'Close item completed.' : 'Close item reopened.');
    }
}
