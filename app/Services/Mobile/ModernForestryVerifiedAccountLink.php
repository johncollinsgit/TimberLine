<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use App\Services\Pos\PosException;
use App\Services\Pos\PosVerifiedCustomerAccount;
use Illuminate\Support\Facades\DB;

/** Creates only a new account-owned profile; uncertain existing identities require review. */
final class ModernForestryVerifiedAccountLink
{
    public const SOURCE = PosVerifiedCustomerAccount::SOURCE;

    public function forFreshSubject(array $identity, bool $createNew): MarketingProfile
    {
        if (config('pos_live.verified_customer_linking_enabled', false) !== true) {
            throw new PosException('customer_account_linking_unavailable', 503);
        }
        $gid = $identity['shopify_customer_id'] ?? null;
        $tenant = ModernForestryMobileCheckoutService::TENANT_ID;
        if (($identity['source_type'] ?? null) !== 'shopify_customer' || ! is_string($gid)
            || ! preg_match('~^gid://shopify/Customer/([1-9][0-9]{0,19})$~D', $gid, $match)) {
            throw new PosException('customer_identity_review_required', 409);
        }
        $digits = $match[1];
        $key = 'retail:'.$digits;

        return DB::transaction(function () use ($identity, $gid, $tenant, $digits, $key, $createNew): MarketingProfile {
            $store = Tenant::query()->whereKey($tenant)->where('slug', ModernForestryMobileProductCatalogService::TENANT_SLUG)->lockForUpdate()->first();
            if (! $store) {
                throw new PosException('customer_identity_review_required', 409);
            }
            $attestation = MarketingProfileLink::query()->forTenantId($tenant)->where('source_type', self::SOURCE)
                ->where('source_id', $key)->lockForUpdate()->first();
            if ($attestation) {
                $profile = MarketingProfile::query()->forTenantId($tenant)->whereKey($attestation->marketing_profile_id)->lockForUpdate()->first();
                if (! $profile || ! $this->validAttestation($attestation, $profile, $gid)
                    || ! app(ModernForestryRewardIdentity::class)->verifiedSubject($profile, $gid)) {
                    throw new PosException('customer_identity_review_required', 409);
                }

                return $profile;
            }
            // Existing matches may have originated from email or phone. A
            // Shopify login does not prove ownership of their wallet.
            $ids = [$gid, $digits, $key, 'retail:'.$gid, 'shopify:'.$gid, 'shopify:'.$digits];
            $linksExist = MarketingProfileLink::query()->forTenantId($tenant)->where('source_type', 'shopify_customer')
                ->where(function ($q) use ($ids, $gid, $digits): void {
                    $q->whereIn('source_id', $ids)->orWhere('source_meta->shopify_customer_gid', $gid)
                        ->orWhere('source_meta->shopify_customer_id', $digits);
                })->lockForUpdate()->exists();
            $externalExists = CustomerExternalProfile::query()->forTenantId($tenant)->where(function ($q) use ($gid, $digits): void {
                $q->whereIn('external_customer_id', [$gid, $digits])->orWhereIn('external_customer_gid', [$gid, $digits]);
            })->lockForUpdate()->exists();
            if ($linksExist || $externalExists || ! $createNew) {
                throw new PosException('customer_identity_review_required', 409);
            }
            $first = is_string($identity['first_name'] ?? null) ? mb_substr(trim($identity['first_name']), 0, 100) : '';
            $last = is_string($identity['last_name'] ?? null) ? mb_substr(trim($identity['last_name']), 0, 100) : '';
            $profile = MarketingProfile::query()->create(['tenant_id' => $tenant, 'first_name' => $first ?: 'Customer',
                'last_name' => $last ?: null, 'accepts_email_marketing' => false, 'accepts_sms_marketing' => false,
                'source_channels' => ['authenticated_shopify_account']]);
            $meta = ['verification_method' => 'new_profile_authenticated_customer_account', 'shopify_store_key' => 'retail',
                'shopify_customer_gid' => $gid, 'verified_profile_id' => $profile->id, 'verified_at' => now()->toIso8601String()];
            $meta['signature'] = app(PosVerifiedCustomerAccount::class)->signature($tenant, (int) $profile->id, 'retail', $gid);
            foreach ([self::SOURCE, 'shopify_customer'] as $type) {
                MarketingProfileLink::query()->create(['tenant_id' => $tenant, 'marketing_profile_id' => $profile->id,
                    'source_type' => $type, 'source_id' => $key, 'source_meta' => $meta,
                    'match_method' => 'customer_verified_account_link', 'confidence' => 1]);
            }

            return $profile;
        }, 3);
    }

    public function validAttestation(MarketingProfileLink $link, MarketingProfile $profile, string $gid): bool
    {
        $meta = $link->source_meta ?? [];

        return $link->source_type === self::SOURCE && (int) $link->tenant_id === (int) $profile->tenant_id
            && (int) $link->marketing_profile_id === (int) $profile->id && $profile->merged_at === null && $profile->archived_at === null
            && ($meta['verification_method'] ?? null) === 'new_profile_authenticated_customer_account'
            && ($meta['shopify_store_key'] ?? null) === 'retail' && ($meta['shopify_customer_gid'] ?? null) === $gid
            && ($meta['verified_profile_id'] ?? null) === (int) $profile->id
            && is_string($meta['verified_at'] ?? null) && strtotime($meta['verified_at']) !== false
            && is_string($meta['signature'] ?? null)
            && hash_equals(app(PosVerifiedCustomerAccount::class)->signature((int) $profile->tenant_id, (int) $profile->id, 'retail', $gid), $meta['signature']);
    }
}
