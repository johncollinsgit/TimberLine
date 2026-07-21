<?php

namespace App\Services\Operations;

use App\Models\OperatorRecurringCost;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBudSetting;
use App\Models\TenantMessagingUsagePeriod;
use App\Models\TenantSupportTicket;
use Carbon\CarbonImmutable;

class OperatorDashboardService
{
    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $weekCost = OperatorRecurringCost::query()->where('active', true)->get()->sum(fn ($c) => match ($c->cadence) { 'weekly' => $c->amount_cents, 'annual' => (int) round($c->amount_cents / 52), default => (int) round($c->amount_cents / 4.345) });
        $yearStart = CarbonImmutable::now()->startOfYear();
        $revenue = TenantBillingReceipt::withoutGlobalScopes()->where('paid_at', '>=', $yearStart)->sum('total_amount_cents');
        $paying = TenantBillingReceipt::withoutGlobalScopes()->where('paid_at', '>=', CarbonImmutable::now()->subMonths(2))->distinct('tenant_id')->count('tenant_id');
        $averageWeekly = $paying ? max(1, (int) round(($revenue / max(1, CarbonImmutable::now()->dayOfYear)) * 7 / $paying)) : 0;
        $usage = TenantMessagingUsagePeriod::withoutGlobalScopes()
            ->with('tenant:id,name')
            ->whereDate('period_end', '>=', CarbonImmutable::today())
            ->get()
            ->groupBy('tenant_id')
            ->map(fn ($periods): array => [
                'tenant' => $periods->first()?->tenant?->name ?? 'Workspace',
                'email_used' => (int) $periods->where('channel', 'email')->sum('used_units'),
                'sms_used' => (int) $periods->whereIn('channel', ['sms', 'mms'])->sum('used_units'),
            ])->values();

        return [
            'weekly_cost_cents' => (int) $weekCost, 'ytd_revenue_cents' => (int) $revenue, 'active_paying_tenants' => $paying,
            'break_even_clients' => $averageWeekly ? (int) ceil($weekCost / $averageWeekly) : null,
            'costs' => OperatorRecurringCost::query()->where('active', true)->orderBy('vendor')->get(),
            'open_tickets' => TenantSupportTicket::withoutGlobalScopes()->whereNotIn('status', ['resolved', 'closed'])->count(),
            'bud_pending' => TenantBudSetting::query()->where('status', 'pending')->with(['tenant', 'requester'])->get(),
            'usage' => $usage,
        ];
    }
}
