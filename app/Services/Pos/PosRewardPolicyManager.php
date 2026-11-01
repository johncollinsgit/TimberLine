<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\TenantMarketingSetting;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Facades\DB;

/** Owner-reviewed POS redemption terms; this never changes the rewards ledger or rollout gates. */
final class PosRewardPolicyManager
{
    private const DRAFT_KEY = 'pos_candle_cash_redemption_draft';

    public function __construct(private PosStore $pos, private PosCustomerRewards $rewards) {}

    public function get(array $scope): array
    {
        $this->authorize($scope);
        $tenant = $scope['tenant_id'];
        $draft = $this->draft($tenant);
        $published = $this->published($tenant);
        $register = $this->pos->rows('registers', $tenant)->where('id', $scope['register']->id)->where('active', true)->first();
        if (! $register) {
            throw new PosException('register_unavailable', 404);
        }
        $config = $this->pos->decrypt($register->configuration);
        $program = $this->rewards->policy($tenant);

        return ['draft' => $draft, 'draft_version' => Contract::hash($draft), 'published' => $published,
            'published_version' => $published === null ? null : Contract::hash($published),
            'register_reviewed_version' => $config['pos_candle_cash_reviewed_version'] ?? null,
            'program_version' => $program['version'] ?? null,
            'management_enabled' => config('pos_live.reward_policy_management_enabled', false) === true,
            // This switch alone does not establish customer authorization or
            // the end-to-end payment lifecycle. Availability stays hard off.
            'redemption_enabled' => config('pos_live.in_person_rewards_enabled', false) === true,
            'redemption_available' => false, 'publication_available' => false];
    }

    public function save(array $scope, array $input, string $expected): array
    {
        $this->authorize($scope);
        $this->assertManagement();
        $draft = $this->validateDraft($input);

        return DB::transaction(function () use ($scope, $draft, $expected): array {
            $this->lockTenant($scope['tenant_id']);
            $before = $this->get($scope);
            if (! hash_equals($before['draft_version'], $expected)) {
                throw new PosException('reward_policy_changed', 409);
            }
            $this->write($scope['tenant_id'], self::DRAFT_KEY, $draft);
            $this->pos->event($scope['tenant_id'], 'reward_policy', 'reward_policy_draft_saved', [
                'actor_id' => $scope['actor_id'], 'before' => $before['draft_version'], 'after' => Contract::hash($draft),
            ]);

            return $this->get($scope);
        }, 3);
    }

    public function publish(array $scope, string $draftVersion, ?string $previousPublished, ?string $previousRegister, ?string $programVersion, bool $confirmed): array
    {
        $this->authorize($scope);
        $this->assertManagement();
        if (! $confirmed) {
            throw new PosException('reward_policy_confirmation_required', 422);
        }

        // Payment start and policy publication do not yet share a durable fence.
        // A switch cannot safely override this until that race is resolved.
        throw new PosException('reward_policy_publication_unavailable', 503);
    }

    private function draft(int $tenant): array
    {
        $row = TenantMarketingSetting::query()->forTenantId($tenant)->where('key', self::DRAFT_KEY)->first();

        return $this->validateDraft($row?->value ?? ['enabled' => false, 'max_minor' => 500, 'channel' => 'in_person',
            'refund_policy' => 'restore_full_review_partial']);
    }

    private function published(int $tenant): ?array
    {
        $value = TenantMarketingSetting::query()->forTenantId($tenant)->where('key', PosRewardRedemptionSettings::SETTING_KEY)->first()?->value;
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new PosException('reward_policy_corrupt', 503);
        }

        // Explicit ordering keeps optimistic versions stable across MySQL JSON key ordering.
        return ['enabled' => $value['enabled'] ?? null, 'max_minor' => $value['max_minor'] ?? null,
            'refund_policy' => $value['refund_policy'] ?? null, 'reviewed_version' => $value['reviewed_version'] ?? null,
            'reviewed_by' => $value['reviewed_by'] ?? null, 'reviewed_at' => $value['reviewed_at'] ?? null];
    }

    private function validateDraft(array $draft): array
    {
        if (array_diff(array_keys($draft), ['enabled', 'max_minor', 'channel', 'refund_policy']) || ! is_bool($draft['enabled'] ?? null)
            || ! is_int($draft['max_minor'] ?? null) || $draft['max_minor'] < 1 || $draft['max_minor'] > Contract::MAX_MINOR
            || ($draft['channel'] ?? null) !== 'in_person' || ($draft['refund_policy'] ?? null) !== 'restore_full_review_partial') {
            throw new PosException('reward_policy_invalid', 422);
        }

        return ['enabled' => $draft['enabled'], 'max_minor' => $draft['max_minor'], 'channel' => 'in_person',
            'refund_policy' => 'restore_full_review_partial'];
    }

    private function write(int $tenant, string $key, array $value): void
    {
        TenantMarketingSetting::query()->updateOrCreate(['tenant_id' => $tenant, 'key' => $key], ['value' => $value]);
    }

    private function lockTenant(int $tenant): void
    {
        if (! DB::table('tenants')->where('id', $tenant)->lockForUpdate()->first()) {
            throw new PosException('tenant_unavailable', 404);
        }
    }

    private function authorize(array $scope): void
    {
        if (! is_int($scope['tenant_id'] ?? null) || $scope['tenant_id'] < 1 || ! is_int($scope['actor_id'] ?? null)
            || $scope['actor_id'] < 1 || ! in_array($scope['role'] ?? null, ['owner', 'tenant_owner', 'admin'], true)
            || ! is_object($scope['register'] ?? null)) {
            throw new PosException('organization_admin_required', 403);
        }
    }

    private function assertManagement(): void
    {
        if (config('pos_live.reward_policy_management_enabled', false) !== true) {
            throw new PosException('reward_policy_management_disabled', 503);
        }
    }
}
