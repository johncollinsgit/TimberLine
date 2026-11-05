<?php

namespace App\Services\Wholesale;

use App\Models\User;
use App\Models\WholesaleFollowUp;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class WholesaleFollowUpService
{
    public function __construct(private LandlordOperatorActionAuditService $audit) {}

    public function update(int $tenantId, string $publicId, User $actor, string $action, ?CarbonImmutable $dueAt): WholesaleFollowUp
    {
        if (! in_array($action, ['complete', 'reschedule'], true)) {
            throw new DomainException('Choose a supported follow-up action.');
        }
        if ($action === 'reschedule' && (! $dueAt || ! $dueAt->isFuture())) {
            throw new DomainException('Choose a future follow-up date.');
        }

        return DB::transaction(function () use ($tenantId, $publicId, $actor, $action, $dueAt): WholesaleFollowUp {
            $followUp = WholesaleFollowUp::query()->forAllTenants()->where('tenant_id', $tenantId)
                ->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            if ($action === 'complete' && $followUp->status === 'completed') {
                return $followUp;
            }
            if (! in_array($followUp->status, ['open', 'in_progress'], true)) {
                throw new DomainException('Only open follow-ups can be changed. Completed history is retained.');
            }
            $before = $followUp->only(['status', 'due_at', 'completed_at']);
            $followUp->forceFill($action === 'complete'
                ? ['status' => 'completed', 'completed_at' => now()]
                : ['due_at' => $dueAt->utc()])->save();
            $this->audit->record($tenantId, $actor->id, 'wholesale.follow_up.'.$action,
                targetType: 'wholesale_follow_up', targetId: $followUp->id,
                context: ['surface' => 'shopify_embedded_wholesale'],
                beforeState: $before, afterState: $followUp->only(['status', 'due_at', 'completed_at']));

            return $followUp;
        });
    }
}
