<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use App\Services\Marketing\CanonicalMarketingProfileResolver;

/** An authenticated account may redeem only against an explicitly verified link. */
final class ModernForestryRewardIdentity
{
    public function __construct(private CanonicalMarketingProfileResolver $identities) {}

    public function verified(ModernForestryMobileCustomerSession $session): bool
    {
        $profile = $session->profile;
        $tenant = ModernForestryMobileCheckoutService::TENANT_ID;
        $gid = $session->identity['shopify_customer_id'] ?? null;
        if ((int) $profile->tenant_id !== $tenant || ($session->identity['source_type'] ?? null) !== 'shopify_customer'
            || ! is_string($gid) || ! preg_match('~^gid://shopify/Customer/([1-9][0-9]*)$~D', $gid, $match)
            || ! Tenant::query()->whereKey($tenant)->where('slug', ModernForestryMobileProductCatalogService::TENANT_SLUG)->exists()
            || $this->identities->byId($tenant, (int) $profile->id)?->id !== $profile->id) {
            return false;
        }
        $ids = [$gid, $match[1], 'retail:'.$gid, 'retail:'.$match[1], 'shopify:'.$gid, 'shopify:'.$match[1]];
        $links = MarketingProfileLink::query()->forTenantId($tenant)->where('source_type', 'shopify_customer')
            ->where(function ($query) use ($ids, $gid, $match): void {
                $query->whereIn('source_id', $ids)->orWhere('source_meta->shopify_customer_gid', $gid)
                    ->orWhere('source_meta->shopify_customer_id', $match[1]);
            })->limit(21)->get();
        if ($links->isEmpty() || $links->count() > 20) {
            return false;
        }
        $verified = false;
        foreach ($links as $link) {
            if ($this->identities->byId($tenant, (int) $link->marketing_profile_id)?->id !== $profile->id) {
                return false;
            }
            $meta = $link->source_meta ?? [];
            if ($link->match_method === 'customer_verified_account_link'
                && ($meta['verification_method'] ?? null) === 'authenticated_customer_account'
                && ($meta['shopify_store_key'] ?? null) === 'retail'
                && ($meta['shopify_customer_gid'] ?? null) === $gid
                && is_string($meta['verified_at'] ?? null) && strtotime($meta['verified_at']) !== false) {
                $verified = true;
            }
        }

        $external = CustomerExternalProfile::query()->forTenantId($tenant)
            ->where(function ($query) use ($gid, $match): void {
                $query->whereIn('external_customer_id', [$gid, $match[1]])
                    ->orWhereIn('external_customer_gid', [$gid, $match[1]]);
            })->limit(21)->get();
        if ($external->count() > 20) {
            return false;
        }
        foreach ($external as $record) {
            if ($record->provider !== 'shopify' || $record->integration !== 'shopify_customer' || $record->store_key !== 'retail'
                || $this->identities->byId($tenant, (int) $record->marketing_profile_id)?->id !== $profile->id) {
                return false;
            }
        }

        return $verified;
    }
}
