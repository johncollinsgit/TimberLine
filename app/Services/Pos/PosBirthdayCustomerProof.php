<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\BirthdayRewardIssuance;
use App\Models\MarketingProfile;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\BirthdayClubService;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Separate one-use birthday consent. No API exposes this foundation yet.
 * Issuers must first authenticate and freshly verify the customer's account.
 * Binding must share the original quote transaction and annual reward hold.
 */
final class PosBirthdayCustomerProof
{
    private const PREFIX = 'everbranch-birthday:v1:';

    public function __construct(private PosStore $store, private BirthdayClubService $club) {}

    public function termsForAuthenticatedProfile(MarketingProfile $profile, int $cycleYear): array
    {
        $this->assertEnabled();
        $tenantId = (int) $profile->tenant_id;
        $program = TenantMarketingSetting::query()->forTenantId($tenantId)->where('key', 'birthday_club_config')->first();
        if (! $profile->exists || $tenantId < 1 || ($program?->value['enabled'] ?? false) !== true) {
            throw new PosException('birthday_authorization_unavailable', 403);
        }
        try {
            $identity = $this->club->identity($profile);
        } catch (\RuntimeException) {
            throw new PosException('customer_identity_review_required');
        }
        if ((int) $identity['tenant_id'] !== $tenantId || (int) $identity['canonical_profile_id'] !== (int) $profile->id
            || $identity['profile_ids'] !== [(int) $profile->id]) {
            throw new PosException('customer_identity_review_required');
        }
        $coverage = DB::table('birthday_cycle_coverage')->where('tenant_id', $tenantId)->where('canonical_profile_id', $profile->id)
            ->where('cycle_year', $cycleYear)->first();
        $issuance = BirthdayRewardIssuance::query()->whereKey($coverage->issuance_id ?? null)->where('marketing_profile_id', $profile->id)
            ->where('cycle_year', $cycleYear)->first();
        if (! $coverage || $coverage->legacy_covered || ! $issuance || $issuance->reward_type !== 'discount_code'
            || ! in_array($issuance->status, ['issued', 'claimed'], true) || $issuance->redeemed_at || $issuance->isExpired()
            || ! preg_match('/^(\d{1,8})\.(\d{2})$/D', (string) $issuance->reward_value, $parts)) {
            throw new PosException('birthday_authorization_unavailable', 403);
        }
        $amount = ((int) $parts[1] * 100) + (int) $parts[2];
        if ($amount < 1) {
            throw new PosException('birthday_authorization_unavailable', 403);
        }
        $terms = ['tenant_id' => $tenantId, 'profile_id' => (int) $profile->id, 'issuance_id' => (int) $issuance->id,
            'cycle_year' => $cycleYear, 'amount_minor' => $amount, 'disclosure_version' => 'birthday_one_use_v1'];

        return [...$terms, 'terms_hash' => Contract::hash($terms)];
    }

    /** Caller must show the exact terms and obtain an affirmative customer action. */
    public function issueForAuthenticatedProfile(MarketingProfile $profile, int $year, string $acceptedTermsHash, bool $confirmed): array
    {
        $this->assertEnabled();
        $terms = $this->termsForAuthenticatedProfile($profile, $year);
        if (! $confirmed || ! hash_equals($terms['terms_hash'], $acceptedTermsHash)) {
            throw new PosException('birthday_disclosure_changed', 409);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = now()->addSeconds(120);
        DB::table('pos_birthday_authorizations')->insert(['id' => (string) Str::uuid(), 'runtime_id' => $this->store->runtime(),
            'tenant_id' => $terms['tenant_id'], 'profile_id' => $terms['profile_id'], 'issuance_id' => $terms['issuance_id'],
            'cycle_year' => $year, 'token_hash' => hash('sha256', $token), 'terms_hash' => $terms['terms_hash'],
            'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

        return ['qr_payload' => self::PREFIX.$token, 'expires_at' => $expires->toIso8601String()];
    }

    /** Caller must bind the annual hold and quote atomically in the same transaction. */
    public function bind(int $tenantId, string $payload, string $saleId): object
    {
        $this->assertEnabled();
        if (DB::transactionLevel() < 1 || ! preg_match('/^[a-f0-9]{32}$/D', $saleId)
            || ! preg_match('/^everbranch-birthday:v1:([A-Za-z0-9_-]{43})$/D', $payload, $matches)) {
            throw new PosException('birthday_authorization_invalid', 403);
        }
        $query = DB::table('pos_birthday_authorizations')->where('runtime_id', $this->store->runtime())->where('tenant_id', $tenantId)
            ->where('token_hash', hash('sha256', $matches[1]));
        $preview = (clone $query)->first();
        if (! $preview) {
            throw new PosException('birthday_authorization_invalid', 403);
        }
        // Match the profile -> coverage -> issuance lock order used by annual issuance.
        $profile = MarketingProfile::query()->where('tenant_id', $tenantId)->whereKey($preview->profile_id)->lockForUpdate()->first();
        DB::table('birthday_cycle_coverage')->where('tenant_id', $tenantId)->where('canonical_profile_id', $preview->profile_id)
            ->where('cycle_year', $preview->cycle_year)->lockForUpdate()->first();
        BirthdayRewardIssuance::query()->whereKey($preview->issuance_id)->lockForUpdate()->first();
        $proof = $query->lockForUpdate()->first();
        if (! $proof || ($proof->sale_id !== null && $proof->sale_id !== $saleId)
            || ($proof->sale_id === null && now()->greaterThanOrEqualTo($proof->expires_at))) {
            throw new PosException('birthday_authorization_invalid', 403);
        }
        if (! $profile || (int) $proof->profile_id !== (int) $profile->id) {
            throw new PosException('birthday_authorization_invalid', 403);
        }
        $terms = $this->termsForAuthenticatedProfile($profile, (int) $proof->cycle_year);
        if ((int) $proof->issuance_id !== $terms['issuance_id'] || ! hash_equals($proof->terms_hash, $terms['terms_hash'])) {
            throw new PosException('birthday_disclosure_changed', 409);
        }
        if ($proof->sale_id === null) {
            DB::table('pos_birthday_authorizations')->where('id', $proof->id)->update(['sale_id' => $saleId, 'updated_at' => now()]);
            $proof->sale_id = $saleId;
        }

        return $proof;
    }

    private function assertEnabled(): void
    {
        if (config('pos_live.birthday_authorization_enabled', false) !== true) {
            throw new PosException('birthday_authorization_disabled', 403);
        }
    }
}
