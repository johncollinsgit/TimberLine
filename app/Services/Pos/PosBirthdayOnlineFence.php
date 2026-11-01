<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\BirthdayRewardIssuance;
use App\Models\MarketingProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An unexposed foundation for recording online activation operations.
 * This alone cannot establish exclusivity: every online entry point and order
 * ingest must use the fence before any POS redemption may be enabled.
 */
final class PosBirthdayOnlineFence
{
    public function begin(int $tenantId, int $profileId, int $cycleYear, int $issuanceId, string $operationId): void
    {
        $this->assertEnabled($operationId);
        DB::transaction(function () use ($tenantId, $profileId, $cycleYear, $issuanceId, $operationId): void {
            $coverage = $this->lockCycle($tenantId, $profileId, $cycleYear, $issuanceId);
            DB::table('pos_birthday_cycle_fences')->insertOrIgnore(['tenant_id' => $tenantId, 'coverage_id' => $coverage->id,
                'issuance_id' => $issuanceId, 'state' => 'idle', 'created_at' => now(), 'updated_at' => now()]);
            $fence = DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenantId)->where('coverage_id', $coverage->id)->lockForUpdate()->first();
            if (! $fence || (int) $fence->issuance_id !== $issuanceId) {
                throw new PosException('birthday_fence_review_required');
            }
            if ($fence->state === 'online_in_flight' && $fence->operation_id === $operationId) {
                return;
            }
            if ($fence->state !== 'idle') {
                throw new PosException('birthday_fence_review_required');
            }
            DB::table('pos_birthday_cycle_fences')->where('id', $fence->id)->update([
                'state' => 'online_in_flight', 'operation_id' => $operationId, 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** A timeout, crash, or otherwise unverified outcome must be recorded as uncertain. */
    public function finish(int $tenantId, int $profileId, int $cycleYear, int $issuanceId, string $operationId, bool $providerConfirmed): void
    {
        $this->assertEnabled($operationId);
        DB::transaction(function () use ($tenantId, $profileId, $cycleYear, $issuanceId, $operationId, $providerConfirmed): void {
            $coverage = $this->lockCycle($tenantId, $profileId, $cycleYear, $issuanceId);
            $fence = DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenantId)->where('coverage_id', $coverage->id)->lockForUpdate()->first();
            if (! $fence || (int) $fence->issuance_id !== $issuanceId || $fence->state !== 'online_in_flight' || $fence->operation_id !== $operationId) {
                throw new PosException('birthday_fence_review_required');
            }
            DB::table('pos_birthday_cycle_fences')->where('id', $fence->id)->update([
                'state' => $providerConfirmed ? 'idle' : 'review', 'operation_id' => $providerConfirmed ? null : $operationId, 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** Called inside the same transaction that persists an observed online redemption. */
    public function recordOnlineUse(int $tenantId, int $profileId, int $cycleYear, int $issuanceId, string $orderKey): bool
    {
        if (config('pos_live.birthday_channel_fence_enabled', false) !== true || DB::transactionLevel() < 1 || trim($orderKey) === '') {
            throw new PosException('birthday_fence_invalid', 422);
        }
        $coverage = $this->lockCycle($tenantId, $profileId, $cycleYear, $issuanceId);
        DB::table('pos_birthday_cycle_fences')->insertOrIgnore(['tenant_id' => $tenantId, 'coverage_id' => $coverage->id,
            'issuance_id' => $issuanceId, 'state' => 'idle', 'created_at' => now(), 'updated_at' => now()]);
        $fence = DB::table('pos_birthday_cycle_fences')->where('tenant_id', $tenantId)->where('coverage_id', $coverage->id)->lockForUpdate()->first();
        if (! $fence || (int) $fence->issuance_id !== $issuanceId) {
            throw new PosException('birthday_fence_review_required');
        }
        $hash = hash('sha256', $orderKey);
        if ($fence->state === 'online_consumed' && is_string($fence->online_order_hash) && hash_equals($fence->online_order_hash, $hash)) {
            return false;
        }
        $review = $fence->state !== 'idle';
        DB::table('pos_birthday_cycle_fences')->where('id', $fence->id)->update([
            'state' => $review ? 'review' : 'online_consumed', 'online_order_hash' => $fence->online_order_hash ?: $hash, 'updated_at' => now(),
        ]);

        return $review;
    }

    private function lockCycle(int $tenantId, int $profileId, int $cycleYear, int $issuanceId): object
    {
        if ($tenantId < 1 || $profileId < 1 || $issuanceId < 1 || $cycleYear < 1900 || $cycleYear > 9999) {
            throw new PosException('birthday_fence_invalid', 422);
        }
        $profile = MarketingProfile::query()->where('tenant_id', $tenantId)->whereKey($profileId)->lockForUpdate()->first();
        $coverage = DB::table('birthday_cycle_coverage')->where('tenant_id', $tenantId)->where('canonical_profile_id', $profileId)
            ->where('cycle_year', $cycleYear)->lockForUpdate()->first();
        $issuance = BirthdayRewardIssuance::query()->whereKey($issuanceId)->where('marketing_profile_id', $profileId)
            ->where('cycle_year', $cycleYear)->lockForUpdate()->first();
        if (! $profile || ! $coverage || ! $issuance || (int) $coverage->issuance_id !== $issuanceId || $coverage->legacy_covered) {
            throw new PosException('birthday_fence_review_required');
        }

        return $coverage;
    }

    private function assertEnabled(string $operationId): void
    {
        if (config('pos_live.birthday_channel_fence_enabled', false) !== true) {
            throw new PosException('birthday_fence_disabled', 403);
        }
        if (! Str::isUuid($operationId)) {
            throw new PosException('birthday_fence_invalid', 422);
        }
    }
}
