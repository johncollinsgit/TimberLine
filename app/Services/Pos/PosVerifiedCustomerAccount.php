<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use Illuminate\Support\Facades\DB;

/** A signed account attestation is independent of mutable import match labels. */
final class PosVerifiedCustomerAccount
{
    public const SOURCE = 'verified_shopify_customer_account';

    public const CONTACT_SOURCE = 'verified_pos_contact';

    public function __construct(private CanonicalMarketingProfileResolver $identities) {}

    public function signature(int $tenant, int $profile, string $store, string $gid): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new PosException('customer_identity_review_required', 503);
        }

        return hash_hmac('sha256', implode('|', ['pos-account-v1', $tenant, $profile, $store, $gid]), $key);
    }

    public function verified(MarketingProfile $profile, int $linkId): bool
    {
        $tenant = (int) $profile->tenant_id;
        if ($tenant < 1 || $linkId < 1 || $profile->archived_at !== null || $profile->merged_at !== null
            || $this->identities->byId($tenant, (int) $profile->id)?->id !== $profile->id) {
            return false;
        }
        $locked = DB::transactionLevel() > 0;
        $link = MarketingProfileLink::query()->forTenantId($tenant)->whereKey($linkId)->whereIn('source_type', [self::SOURCE, self::CONTACT_SOURCE])
            ->when($locked, fn ($q) => $q->lockForUpdate())->first();
        $meta = $link?->source_meta ?? [];
        if ($link?->source_type === self::CONTACT_SOURCE) {
            $channel = $meta['channel'] ?? '';
            $contact = $this->contact($profile, $channel);

            return (int) $link->marketing_profile_id === (int) $profile->id && $contact !== null
                && ($meta['verification_method'] ?? null) === 'pos_contact_otp_v1' && ($meta['verified_profile_id'] ?? null) === (int) $profile->id
                && is_string($meta['expires_at'] ?? null) && strtotime($meta['expires_at']) !== false && now()->lessThan($meta['expires_at'])
                && ($meta['contact_hash'] ?? null) === hash('sha256', $contact) && is_string($meta['signature'] ?? null)
                && hash_equals($this->contactSignature($tenant, (int) $profile->id, $link->source_id, $channel, $meta['contact_hash'], $meta['expires_at']), $meta['signature']);
        }
        $gid = $meta['shopify_customer_gid'] ?? null;
        $store = $meta['shopify_store_key'] ?? null;
        if (! $link || (int) $link->marketing_profile_id !== (int) $profile->id || ! is_string($gid)
            || ! preg_match('~^gid://shopify/Customer/([1-9][0-9]{0,19})$~D', $gid, $match)
            || ! is_string($store) || ! preg_match('/^[a-z0-9_-]{1,64}$/D', $store)
            || $link->source_id !== $store.':'.$match[1]
            || ! in_array($meta['verification_method'] ?? null, ['new_profile_authenticated_customer_account', 'reviewed_existing_customer_account'], true)
            || ($meta['verified_profile_id'] ?? null) !== (int) $profile->id
            || ! is_string($meta['verified_at'] ?? null) || strtotime($meta['verified_at']) === false
            || ! is_string($meta['signature'] ?? null)
            || ! hash_equals($this->signature($tenant, (int) $profile->id, $store, $gid), $meta['signature'])) {
            return false;
        }

        return $this->mappingsConsistent($profile, $store, $gid);
    }

    public function mappingsConsistent(MarketingProfile $profile, string $store, string $gid, bool $requireLink = true): bool
    {
        $tenant = (int) $profile->tenant_id;
        if (! preg_match('~^gid://shopify/Customer/([1-9][0-9]{0,19})$~D', $gid, $match)) {
            return false;
        }
        $locked = DB::transactionLevel() > 0;
        $digits = $match[1];
        $ids = [$gid, $digits, $store.':'.$digits, $store.':'.$gid, 'shopify:'.$gid, 'shopify:'.$digits];
        $links = MarketingProfileLink::query()->forTenantId($tenant)->where('source_type', 'shopify_customer')
            ->where(function ($q) use ($ids, $gid, $digits): void {
                $q->whereIn('source_id', $ids)->orWhere('source_meta->shopify_customer_gid', $gid)
                    ->orWhere('source_meta->shopify_customer_id', $digits);
            })->when($locked, fn ($q) => $q->lockForUpdate())->limit(21)->get();
        if (($requireLink && $links->isEmpty()) || $links->count() > 20) {
            return false;
        }
        foreach ($links as $candidate) {
            if ($this->identities->byId($tenant, (int) $candidate->marketing_profile_id)?->id !== $profile->id) {
                return false;
            }
        }
        $external = CustomerExternalProfile::query()->forTenantId($tenant)->where(function ($q) use ($gid, $digits): void {
            $q->whereIn('external_customer_id', [$gid, $digits])->orWhereIn('external_customer_gid', [$gid, $digits]);
        })->when($locked, fn ($q) => $q->lockForUpdate())->limit(21)->get();
        if ($external->count() > 20) {
            return false;
        }
        foreach ($external as $record) {
            if ($record->provider !== 'shopify' || $record->integration !== 'shopify_customer' || $record->store_key !== $store
                || $this->identities->byId($tenant, (int) $record->marketing_profile_id)?->id !== $profile->id) {
                return false;
            }
        }

        return true;
    }

    public function contact(MarketingProfile $profile, string $channel): ?string
    {
        if ($channel === 'phone') {
            return app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->toE164($profile->phone);
        }
        $email = strtolower(trim((string) $profile->email));

        return $channel === 'email' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public function contactSignature(int $tenant, int $profile, string $id, string $channel, string $hash, string $expiry): string
    {
        return hash_hmac('sha256', implode('|', ['pos-contact-v1', $tenant, $profile, $id, $channel, $hash, $expiry]), (string) config('app.key'));
    }
}
