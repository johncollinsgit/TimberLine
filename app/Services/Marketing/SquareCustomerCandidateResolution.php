<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Str;

/** Read-only evidence for a source association, never a profile merge or account attestation. */
final class SquareCustomerCandidateResolution
{
    public function __construct(private CanonicalMarketingProfileResolver $identities, private MarketingIdentityNormalizer $normalizer) {}

    public function resolve(int $tenant, array $identity, array $candidateIds): ?array
    {
        // A bounded contact lookup must not mistake a truncated result for agreement.
        if (count($candidateIds) < 2 || count($candidateIds) > 25) {
            return null;
        }
        $profiles = [];
        foreach ($candidateIds as $id) {
            $profile = $this->identities->byId($tenant, $id);
            if (! $profile || $profile->archived_at || ! $this->contactAgrees($identity, $profile)) {
                return null;
            }
            $profiles[$profile->id] = $profile;
        }
        if (count($profiles) === 1) {
            return ['profile_id' => array_key_first($profiles), 'basis' => 'existing_canonical_aliases',
                'candidate_profile_ids' => $candidateIds, 'canonical_profile_ids' => array_keys($profiles)];
        }

        // Separate import rows may refer to one original Shopify customer. Only
        // its current tenant-owned external mapping may select the destination.
        $claims = [];
        foreach (MarketingProfileLink::whereIn('marketing_profile_id', $candidateIds)
            ->where(fn ($q) => $q->where('tenant_id', $tenant)->orWhereNull('tenant_id'))
            ->whereIn('source_type', ['shopify_customer', 'growave_customer'])->get() as $link) {
            $profile = $this->identities->byId($tenant, $link->marketing_profile_id);
            $key = $this->sourceKey((string) $link->source_id);
            if (! $profile || ! $key) {
                return null;
            }
            $claims[$profile->id][$key] = true;
        }
        $keys = [];
        foreach (array_keys($profiles) as $id) {
            if (count($claims[$id] ?? []) !== 1) {
                return null;
            }
            $keys[array_key_first($claims[$id])] = true;
        }
        if (count($keys) !== 1) {
            return null;
        }
        $key = array_key_first($keys);
        [$store, $digits] = explode(':', $key);
        $anchors = CustomerExternalProfile::forTenantId($tenant)->where('provider', 'shopify')->where('integration', 'shopify_customer')
            ->where('store_key', $store)->where(fn ($q) => $q->whereIn('external_customer_id', [$digits, 'gid://shopify/Customer/'.$digits])
            ->orWhere('external_customer_gid', 'gid://shopify/Customer/'.$digits))->get();
        $destinations = [];
        foreach ($anchors as $anchor) {
            $ids = array_values(array_filter([$anchor->external_customer_id, $anchor->external_customer_gid], fn ($id) => filled($id)));
            if (! $anchor->synced_at || ! $ids || ! $this->contactAgrees($identity, $anchor)
                || collect($ids)->contains(fn ($id) => $this->digits((string) $id) !== $digits)) {
                return null;
            }
            $profile = $this->identities->byId($tenant, (int) $anchor->marketing_profile_id);
            if (! $profile || $profile->archived_at || ! isset($profiles[$profile->id])) {
                return null;
            }
            $destinations[$profile->id] = true;
        }
        if (count($destinations) !== 1 || ! $this->profilesAgree(array_values($profiles))) {
            return null;
        }

        return ['profile_id' => array_key_first($destinations), 'basis' => 'same_original_shopify_customer',
            'candidate_profile_ids' => $candidateIds, 'canonical_profile_ids' => array_keys($profiles),
            'shopify_store_key' => $store, 'shopify_customer_id' => $digits, 'external_profile_ids' => $anchors->modelKeys()];
    }

    private function contactAgrees(array $identity, MarketingProfile|CustomerExternalProfile $profile): bool
    {
        $matched = false;
        foreach ([['raw_email', 'email'], ['raw_phone', 'phone']] as [$input, $field]) {
            $expected = $this->contact($field, $identity[$input] ?? null);
            $actual = $this->contact($field, $profile->{'normalized_'.$field} ?: $profile->$field);
            if ($expected && $actual && $expected !== $actual) {
                return false;
            }
            $matched = $matched || ($expected && $actual === $expected);
        }
        foreach (['first_name', 'last_name'] as $field) {
            if (filled($identity[$field] ?? null) && filled($profile->$field)
                && $this->name($identity[$field]) !== $this->name($profile->$field)) {
                return false;
            }
        }

        return $matched;
    }

    private function profilesAgree(array $profiles): bool
    {
        foreach (['email', 'phone', 'first_name', 'last_name'] as $field) {
            $values = [];
            foreach ($profiles as $profile) {
                $value = in_array($field, ['email', 'phone'], true)
                    ? $this->contact($field, $profile->{'normalized_'.$field} ?: $profile->$field) : $this->name($profile->$field);
                if ($value !== null && $value !== '') {
                    $values[$value] = true;
                }
            }
            if (count($values) > 1) {
                return false;
            }
        }

        return true;
    }

    private function contact(string $field, ?string $value): ?string
    {
        return $field === 'email' ? $this->normalizer->normalizeEmail($value) : $this->normalizer->normalizePhone($value);
    }

    private function name(?string $value): string
    {
        return Str::lower(trim((string) $value));
    }

    private function sourceKey(string $id): ?string
    {
        if (! preg_match('~^([a-z0-9_-]+):((?:gid://shopify/Customer/)?[1-9][0-9]{0,19})$~D', $id, $match)) {
            return null;
        }

        return $match[1].':'.$this->digits($match[2]);
    }

    private function digits(string $id): ?string
    {
        return preg_match('~^(?:gid://shopify/Customer/)?([1-9][0-9]{0,19})$~D', $id, $match) ? $match[1] : null;
    }
}
