<?php

namespace App\Services\FieldService;

use App\Jobs\RefreshQuickBooksHomeMetrics;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceReminderSetting;
use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

class FieldServiceOwnerHomeMetricsService
{
    /** @return array<string,mixed> */
    public function build(Tenant $tenant, string $period = 'month'): array
    {
        $timezone = FieldServiceReminderSetting::query()->forTenantId((int) $tenant->id)->value('timezone') ?: config('app.timezone');
        $period = in_array($period, ['today', 'week', 'month'], true) ? $period : 'month';
        $now = CarbonImmutable::now($timezone);
        $start = match ($period) {
            'today' => $now->startOfDay(),
            'week' => $now->startOfWeek(CarbonImmutable::MONDAY),
            default => $now->startOfMonth(),
        };
        $rangeKey = 'home:cash:'.$period;
        $connection = IntegrationConnection::query()->forTenantId((int) $tenant->id)
            ->where('provider', 'quickbooks')->where('status', IntegrationConnection::STATUS_CONNECTED)->latest('id')->first();
        $snapshot = QuickBooksReportingSnapshot::query()->forTenantId((int) $tenant->id)
            ->where('range_key', $rangeKey)
            ->whereDate('period_start', $start->toDateString())
            ->whereDate('period_end', $now->toDateString())
            ->latest('observed_at')->first();
        $fresh = $snapshot?->observed_at?->isAfter(now()->subHour()) ?? false;

        if ($connection && ! $fresh) {
            RefreshQuickBooksHomeMetrics::dispatch(
                (int) $tenant->id,
                (int) $connection->id,
                $rangeKey,
                $start->toDateString(),
                $now->toDateString(),
            );
        }

        $metrics = (array) ($snapshot?->metrics ?? []);
        $completed = FieldServiceJob::query()->forTenantId((int) $tenant->id)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start->utc(), $now->utc()])
            ->count();

        return [
            'period' => $period,
            'options' => [
                ['key' => 'today', 'label' => 'Today'],
                ['key' => 'week', 'label' => 'This Week'],
                ['key' => 'month', 'label' => 'This Month'],
            ],
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $now->toIso8601String(),
            'timezone' => $timezone,
            'money_in' => is_numeric($metrics['total_income'] ?? null) ? round((float) $metrics['total_income'], 2) : null,
            'money_spent' => is_numeric($metrics['total_expenses'] ?? null) ? round(abs((float) $metrics['total_expenses']), 2) : null,
            'finished_jobs' => $completed,
            'quickbooks' => [
                'state' => ! $connection ? 'disconnected' : ($fresh ? 'updated' : ($snapshot ? 'stale' : 'refreshing')),
                'updated_at' => $snapshot?->observed_at?->toIso8601String(),
                'message' => ! $connection ? 'Connect QuickBooks to see money in and money spent.' : ($fresh ? 'Updated from QuickBooks.' : 'Refreshing quietly from QuickBooks.'),
            ],
        ];
    }
}
