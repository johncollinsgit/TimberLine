<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Editing catalogs and current bill evidence, without the legacy forecast/history dashboard. */
class VisualContextService
{
    public function build(User $viewer, Space $space, ?int $accountId = null): array
    {
        app(FinanceAccess::class)->authorize($viewer, $space);
        $accounts = Account::where('space_id', $space->id)->get();
        $account = $accountId === null ? null : $accounts->firstWhere('id', $accountId);
        abort_if($accountId !== null && ! $account, 404);
        $records = Record::where('space_id', $space->id)->where('active', true)->whereNotIn('kind', ['sms', 'payroll'])->get();
        $editingRecords = $accountId === null ? $records : $records->filter(fn ($record) => in_array($record->kind, ['category_definition', 'program'], true)
            || ($record->data['account_id'] ?? null) === $accountId || ($record->data['payment_account_id'] ?? null) === $accountId)->values();

        return [
            'projection' => 'visual_context',
            'available' => ['editing_catalogs', 'monthly_bills'],
            'space' => $space->only(['id', 'name', 'kind', 'currency', 'timezone']),
            'today' => now($space->timezone)->toDateString(),
            'account_options' => $accounts->map(fn ($row) => $row->only(['id', 'name', 'kind']))->values()->all(),
            // Account selectors only: no inferred balances or dashboard totals.
            'accounts' => ($accountId === null ? $accounts : $accounts->where('id', $accountId))->map(fn ($row) => $row->only(['id', 'name', 'kind']))->values()->all(),
            'scope_filter' => ['account_id' => $accountId, 'label' => $account?->name ?? 'All accounts'],
            'records' => $editingRecords->values()->all(),
            'definitions' => config('trajectory_records'),
            'category_options' => app(WorkspaceService::class)->categories($space),
            'workspace_settings' => array_intersect_key($space->settings ?? [], array_flip(['income_categories'])),
            'monthly_bills' => app(MonthlyBillsService::class)->build($space, $records, collect(), $accounts, CarbonImmutable::now($space->timezone)->startOfDay(), $viewer, $accountId),
        ];
    }
}
