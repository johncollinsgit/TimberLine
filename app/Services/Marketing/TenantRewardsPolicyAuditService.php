<?php

namespace App\Services\Marketing;

use App\Models\LandlordOperatorAction;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Facades\Schema;

class TenantRewardsPolicyAuditService
{
    public function __construct(
        protected LandlordOperatorActionAuditService $auditService
    ) {
    }

    /**
     * @param  array<string,mixed>  $changes
     * @param  array<string,mixed>  $context
     */
    public function record(
        int $tenantId,
        int $policyVersion,
        string $launchState,
        array $changes,
        array $context = []
    ): void {
        if (! Schema::hasTable('landlord_operator_actions')) {
            return;
        }

        $actorUserId = is_numeric($context['actor_user_id'] ?? null)
            ? (int) $context['actor_user_id']
            : null;

        $changedFields = array_keys($changes);
        $actionType = $launchState === 'published'
            ? 'tenant_rewards_policy_publish'
            : 'tenant_rewards_policy_save';

        $this->auditService->record(
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            actionType: $actionType,
            status: 'success',
            targetType: 'tenant_rewards_policy',
            targetId: (string) $policyVersion,
            context: [
                'policy_version' => $policyVersion,
                'changed_fields' => $changedFields,
                'changed_field_count' => count($changedFields),
                'shopify_admin_user_id' => $this->nullableString($context['shopify_admin_user_id'] ?? null),
                'shopify_admin_session_id' => $this->nullableString($context['shopify_admin_session_id'] ?? null),
                'source' => $this->nullableString($context['source'] ?? null) ?? 'policy_editor',
            ],
            confirmation: [],
            beforeState: [
                'changed' => collect($changes)
                    ->map(fn ($row): mixed => is_array($row) ? ($row['old'] ?? null) : null)
                    ->all(),
            ],
            afterState: [
                'changed' => collect($changes)
                    ->map(fn ($row): mixed => is_array($row) ? ($row['new'] ?? null) : null)
                    ->all(),
            ],
            result: [
                'policy_version' => $policyVersion,
                'changed_fields' => $changedFields,
            ]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recentForTenant(int $tenantId, int $limit = 20): array
    {
        if (! Schema::hasTable('landlord_operator_actions')) {
            return [];
        }

        $limit = max(1, min(100, $limit));

        return LandlordOperatorAction::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('action_type', ['tenant_rewards_policy_save', 'tenant_rewards_policy_publish'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (LandlordOperatorAction $entry): array {
                $context = is_array($entry->context) ? $entry->context : [];
                $result = is_array($entry->result) ? $entry->result : [];

                return [
                    'id' => (int) $entry->id,
                    'action' => (string) $entry->action_type,
                    'status' => (string) $entry->status,
                    'created_at' => optional($entry->created_at)?->toIso8601String(),
                    'policy_version' => (int) ($result['policy_version'] ?? $context['policy_version'] ?? 0),
                    'changed_fields' => array_values(array_filter((array) ($result['changed_fields'] ?? $context['changed_fields'] ?? []), fn ($item): bool => is_string($item))),
                    'actor_user_id' => is_numeric($entry->actor_user_id) ? (int) $entry->actor_user_id : null,
                    'shopify_admin_user_id' => $this->nullableString($context['shopify_admin_user_id'] ?? null),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>|null  $beforeState
     * @param  array<string,mixed>|null  $afterState
     * @param  array<string,mixed>|null  $result
     */
    public function recordSupportAction(
        int $tenantId,
        string $actionType,
        string $reason,
        ?string $targetType = 'tenant_rewards_reminder',
        int|string|null $targetId = null,
        array $context = [],
        ?array $beforeState = null,
        ?array $afterState = null,
        ?array $result = null
    ): void {
        if (! Schema::hasTable('landlord_operator_actions')) {
            return;
        }

        $actorUserId = is_numeric($context['actor_user_id'] ?? null)
            ? (int) $context['actor_user_id']
            : null;

        $this->auditService->record(
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            actionType: $actionType,
            status: 'success',
            targetType: $targetType,
            targetId: $targetId,
            context: [
                'reason' => $reason,
                'policy_version' => max(0, (int) ($context['policy_version'] ?? 0)),
                'reward_identifier' => $this->nullableString($context['reward_identifier'] ?? null),
                'marketing_profile_id' => is_numeric($context['marketing_profile_id'] ?? null)
                    ? (int) $context['marketing_profile_id']
                    : null,
                'channel' => $this->nullableString($context['channel'] ?? null),
                'timing_days_before_expiration' => is_numeric($context['timing_days_before_expiration'] ?? null)
                    ? max(0, (int) $context['timing_days_before_expiration'])
                    : null,
                'shopify_admin_user_id' => $this->nullableString($context['shopify_admin_user_id'] ?? null),
                'shopify_admin_session_id' => $this->nullableString($context['shopify_admin_session_id'] ?? null),
                'source' => $this->nullableString($context['source'] ?? null) ?? 'rewards_support_tool',
            ],
            confirmation: [
                'reason' => $reason,
            ],
            beforeState: $beforeState,
            afterState: $afterState,
            result: $result
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recentSupportActionsForTenant(int $tenantId, int $limit = 20): array
    {
        if (! Schema::hasTable('landlord_operator_actions')) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $actionTypes = [
            'tenant_rewards_reminder_explain',
            'tenant_rewards_reminder_requeue',
            'tenant_rewards_reminder_mark_skipped',
            'tenant_rewards_reminder_customer_history',
        ];

        return LandlordOperatorAction::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('action_type', $actionTypes)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (LandlordOperatorAction $entry): array {
                $context = is_array($entry->context) ? $entry->context : [];
                $confirmation = is_array($entry->confirmation) ? $entry->confirmation : [];
                $result = is_array($entry->result) ? $entry->result : [];

                return [
                    'id' => (int) $entry->id,
                    'action' => (string) $entry->action_type,
                    'status' => (string) $entry->status,
                    'created_at' => optional($entry->created_at)?->toIso8601String(),
                    'reason' => $this->nullableString($confirmation['reason'] ?? $context['reason'] ?? null),
                    'policy_version' => (int) ($result['policy_version'] ?? $context['policy_version'] ?? 0),
                    'reward_identifier' => $this->nullableString($context['reward_identifier'] ?? null),
                    'marketing_profile_id' => is_numeric($context['marketing_profile_id'] ?? null)
                        ? (int) $context['marketing_profile_id']
                        : null,
                    'channel' => $this->nullableString($context['channel'] ?? null),
                    'timing_days_before_expiration' => is_numeric($context['timing_days_before_expiration'] ?? null)
                        ? max(0, (int) $context['timing_days_before_expiration'])
                        : null,
                    'actor_user_id' => is_numeric($entry->actor_user_id) ? (int) $entry->actor_user_id : null,
                ];
            })
            ->all();
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
