<?php

namespace App\Services\Accounting;

use App\Models\AccountingAuditEvent;
use App\Models\AccountingCloseItem;
use App\Models\AccountingClosePeriod;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class MonthlyCloseService
{
    public function forMonth(Tenant $tenant, CarbonInterface $month): AccountingClosePeriod
    {
        return DB::transaction(function () use ($tenant, $month): AccountingClosePeriod {
            $periodStart = $month->copy()->startOfMonth()->toDateString();
            $period = AccountingClosePeriod::query()
                ->forTenantId((int) $tenant->id)
                ->whereDate('period_start', $periodStart)
                ->first();
            $period ??= AccountingClosePeriod::query()->create([
                'tenant_id' => (int) $tenant->id,
                'period_start' => $periodStart,
                'period_end' => $month->copy()->endOfMonth()->toDateString(),
                'status' => 'open',
                'total_items' => count((array) config('accounting_command_center.monthly_close', [])),
            ]);

            foreach (array_values((array) config('accounting_command_center.monthly_close', [])) as $index => $title) {
                $key = array_keys((array) config('accounting_command_center.monthly_close', []))[$index];
                AccountingCloseItem::query()->firstOrCreate(
                    ['accounting_close_period_id' => (int) $period->id, 'definition_key' => $key],
                    [
                        'tenant_id' => (int) $tenant->id,
                        'title' => $title,
                        'sort_order' => $index + 1,
                        'status' => 'open',
                    ]
                );
            }

            return $this->refreshProgress($period);
        });
    }

    public function setItemStatus(Tenant $tenant, AccountingCloseItem $item, User $actor, bool $complete): AccountingClosePeriod
    {
        abort_unless((int) $item->tenant_id === (int) $tenant->id, 404);

        $item->forceFill([
            'status' => $complete ? 'completed' : 'open',
            'completed_at' => $complete ? now() : null,
            'completed_by_user_id' => $complete ? (int) $actor->id : null,
        ])->save();

        AccountingAuditEvent::query()->create([
            'tenant_id' => (int) $tenant->id,
            'actor_user_id' => (int) $actor->id,
            'event_type' => $complete ? 'monthly_close_item_completed' : 'monthly_close_item_reopened',
            'subject_type' => AccountingCloseItem::class,
            'subject_id' => (int) $item->id,
            'context' => ['definition_key' => $item->definition_key],
            'occurred_at' => now(),
        ]);

        return $this->refreshProgress($item->period()->firstOrFail());
    }

    protected function refreshProgress(AccountingClosePeriod $period): AccountingClosePeriod
    {
        $total = $period->items()->count();
        $completed = $period->items()->where('status', 'completed')->count();
        $period->forceFill([
            'total_items' => $total,
            'completed_items' => $completed,
            'status' => $total > 0 && $completed === $total ? 'ready_for_owner' : 'open',
        ])->save();

        return $period->fresh('items');
    }
}
