<?php

namespace App\Services\Tenancy;

use App\Models\LandlordOperatorAction;
use Illuminate\Support\Collection;

class LandlordOperatorActionAuditService
{
    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $confirmation
     * @param array<string,mixed>|null $beforeState
     * @param array<string,mixed>|null $afterState
     * @param array<string,mixed>|null $result
     */
    public function record(
        ?int $tenantId,
        ?int $actorUserId,
        string $actionType,
        string $status = 'success',
        ?string $targetType = null,
        int|string|null $targetId = null,
        array $context = [],
        array $confirmation = [],
        ?array $beforeState = null,
        ?array $afterState = null,
        ?array $result = null
    ): LandlordOperatorAction {
        return LandlordOperatorAction::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'action_type' => strtolower(trim($actionType)),
            'status' => strtolower(trim($status)) ?: 'success',
            'target_type' => $targetType ? strtolower(trim($targetType)) : null,
            'target_id' => $targetId !== null ? (string) $targetId : null,
            'context' => $context === [] ? null : $context,
            'confirmation' => $confirmation === [] ? null : $confirmation,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'result' => $result,
        ]);
    }

    /**
     * @return Collection<int,LandlordOperatorAction>
     */
    public function recentForTenant(int $tenantId, int $limit = 20): Collection
    {
        $limit = max(1, min(200, $limit));

        return LandlordOperatorAction::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}

