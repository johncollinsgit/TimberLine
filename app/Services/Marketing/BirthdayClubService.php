<?php

namespace App\Services\Marketing;

use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Tenancy\TenantMarketingSettingsResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BirthdayClubService
{
    public function __construct(
        protected CanonicalMarketingProfileResolver $profiles,
        protected TenantMarketingSettingsResolver $settings
    ) {}

    public function enabled(?int $tenantId): bool
    {
        return $tenantId && (bool) ($this->settings->array('birthday_club_config', $tenantId)['enabled'] ?? false);
    }

    public function signupEnabled(?int $tenantId): bool
    {
        return ! $this->enabled($tenantId) || (bool) ($this->settings->array('birthday_club_config', $tenantId)['signup_bonus_enabled'] ?? true);
    }

    public function pagePath(?int $tenantId, string $legacy = '/pages/birthday-celebration'): string
    {
        return $this->enabled($tenantId) ? '/pages/birthday-club' : $legacy;
    }

    /** Verified provider links and retained merge aliases only; email is never a merge key. */
    public function identity(MarketingProfile $profile): array
    {
        $tenantId = (int) $profile->tenant_id;
        $canonical = $this->profiles->canonical($profile, $tenantId);
        if (! $canonical) {
            throw new RuntimeException('Birthday customer identity requires review.');
        }
        $ids = array_values(array_unique([(int) $canonical->id, (int) $profile->id, ...$this->verifiedCatchupIds($canonical)]));
        for ($pass = 0; $pass < 100; $pass++) {
            $previous = $ids;
            $ids = array_values(array_unique(array_merge($ids, MarketingProfile::query()->where('tenant_id', $tenantId)->whereIn('merged_into_profile_id', $ids)->pluck('id')->all())));
            $keys = MarketingProfileLink::query()->where('tenant_id', $tenantId)->whereIn('marketing_profile_id', $ids)->where('source_type', 'shopify_customer')->where('source_id', 'like', 'retail:%')->pluck('source_id');
            if ($keys->isNotEmpty()) {
                $ids = array_merge($ids, MarketingProfileLink::query()->where('tenant_id', $tenantId)->where('source_type', 'shopify_customer')->whereIn('source_id', $keys)->pluck('marketing_profile_id')->all());
            }
            $externalIds = CustomerExternalProfile::query()->where('tenant_id', $tenantId)->whereIn('marketing_profile_id', $ids)->where('provider', 'shopify')->where('store_key', 'retail')->whereNotNull('external_customer_id')->where('external_customer_id', '<>', '')->pluck('external_customer_id');
            if ($externalIds->isNotEmpty()) {
                $ids = array_merge($ids, CustomerExternalProfile::query()->where('tenant_id', $tenantId)->where('provider', 'shopify')->where('store_key', 'retail')->whereIn('external_customer_id', $externalIds)->pluck('marketing_profile_id')->all());
            }
            $roots = $this->profiles->canonicalCollection(MarketingProfile::query()->where('tenant_id', $tenantId)->whereIn('id', $ids)->get(), $tenantId);
            if ($roots === null) {
                throw new RuntimeException('Birthday customer identity requires review.');
            }
            $ids = array_values(array_unique(array_map('intval', array_merge($ids, $roots->pluck('id')->all()))));
            sort($ids);
            sort($previous);
            if ($ids === $previous) {
                return ['tenant_id' => $tenantId, 'canonical_profile_id' => (int) $roots->min('id'), 'profile_ids' => $ids];
            }
        }
        throw new RuntimeException('Birthday customer alias chain requires review.');
    }

    /** Preserve the existing unique, verified retail catchup recovery without merging profiles. */
    protected function verifiedCatchupIds(MarketingProfile $profile): array
    {
        $email = trim((string) $profile->normalized_email);
        if ($email === '') {
            return [];
        }
        $tenantId = (int) $profile->tenant_id;
        $donors = BirthdayRewardIssuance::query()->whereNotNull('metadata->catchup_campaign_key')
            ->whereHas('marketingProfile', fn ($q) => $q->where('tenant_id', $tenantId)->where('normalized_email', $email))
            ->pluck('marketing_profile_id')->unique()->values();
        if ($donors->count() !== 1) {
            return [];
        }
        $verified = CustomerExternalProfile::query()->where('tenant_id', $tenantId)->where('provider', 'shopify')->where('store_key', 'retail')
            ->where('normalized_email', $email)->whereNotNull('external_customer_id')->where('external_customer_id', '<>', '')->get();
        if ($verified->pluck('external_customer_id')->unique()->count() !== 1) {
            return [];
        }
        $linked = $this->profiles->canonicalCollection(MarketingProfile::query()->where('tenant_id', $tenantId)->whereIn('id', $verified->pluck('marketing_profile_id'))->get(), $tenantId);
        if ($linked === null || $linked->count() !== 1) {
            return [];
        }
        $ids = array_values(array_unique([(int) $donors->first(), (int) $linked->first()->id]));
        if (! in_array((int) $profile->id, $ids, true)) {
            return [];
        }
        $birthdays = CustomerBirthdayProfile::query()->whereIn('marketing_profile_id', $ids)->whereNotNull('birth_month')->whereNotNull('birth_day')->get();
        if ($birthdays->map(fn ($birthday) => $birthday->birth_month.'-'.$birthday->birth_day)->unique()->count() > 1) {
            return [];
        }

        return $ids;
    }

    public function issuances(MarketingProfile $profile): Collection
    {
        $identity = $this->identity($profile);

        return BirthdayRewardIssuance::query()->whereIn('marketing_profile_id', $identity['profile_ids'])
            ->whereHas('marketingProfile', fn ($q) => $q->where('tenant_id', $identity['tenant_id']))->get()
            ->sortBy(function (BirthdayRewardIssuance $reward): string {
                $available = in_array($reward->status, ['issued', 'claimed'], true) && ! $reward->isExpired() && $reward->reward_type !== 'candle_cash';

                return ($available ? '0' : '1').($available ? ($reward->expires_at?->format('YmdHis') ?? '99999999999999') : sprintf('%05d', 9999 - $reward->cycle_year)).sprintf('%020d', $reward->id);
            })->values();
    }

    /** Caller owns the identity lock for the entire issuance transaction. */
    public function withCycleLock(CustomerBirthdayProfile $birthday, int $year, callable $callback): array
    {
        $identity = $this->identity($birthday->marketingProfile);

        return DB::transaction(function () use ($year, $identity, $callback): array {
            MarketingProfile::query()->where('tenant_id', $identity['tenant_id'])->whereKey($identity['canonical_profile_id'])->lockForUpdate()->firstOrFail();
            $key = ['tenant_id' => $identity['tenant_id'], 'canonical_profile_id' => $identity['canonical_profile_id'], 'cycle_year' => $year];
            DB::table('birthday_cycle_coverage')->insertOrIgnore([...$key, 'created_at' => now(), 'updated_at' => now()]);
            $coverage = DB::table('birthday_cycle_coverage')->where($key)->lockForUpdate()->first();
            $existing = BirthdayRewardIssuance::query()->whereIn('marketing_profile_id', $identity['profile_ids'])->where('cycle_year', $year)->orderBy('id')->lockForUpdate()->first();
            $legacyCovered = CustomerBirthdayProfile::query()->whereIn('marketing_profile_id', $identity['profile_ids'])->where('reward_last_issued_year', $year)->exists();
            if ($existing || $coverage->legacy_covered || $coverage->issuance_id || $legacyCovered) {
                DB::table('birthday_cycle_coverage')->where('id', $coverage->id)->update(['issuance_id' => $existing?->id ?: $coverage->issuance_id, 'legacy_covered' => $legacyCovered || $coverage->legacy_covered, 'updated_at' => now()]);

                return ['ok' => (bool) $existing, 'state' => $existing && $existing->status === 'issued' ? 'birthday_reward_ready' : 'already_claimed', 'error' => $existing ? null : 'already_issued_this_cycle', 'issuance' => $existing];
            }
            $result = $callback();
            if (($result['issuance'] ?? null) instanceof BirthdayRewardIssuance) {
                DB::table('birthday_cycle_coverage')->where('id', $coverage->id)->update(['issuance_id' => $result['issuance']->id, 'updated_at' => now()]);
            }

            return $result;
        });
    }

    public function registerExisting(CustomerBirthdayProfile $birthday, int $year): array
    {
        return $this->withCycleLock($birthday, $year, fn () => ['ok' => true, 'state' => 'uncovered', 'issuance' => null]);
    }
}
