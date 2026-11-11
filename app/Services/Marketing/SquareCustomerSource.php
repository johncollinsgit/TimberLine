<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use Illuminate\Support\Facades\Crypt;

/** Provider observations are distinct from a verified marketing permission or wallet identity. */
class SquareCustomerSource
{
    public function customer(array $payload): array
    {
        $safe = array_intersect_key($payload, array_flip(['id', 'version', 'created_at', 'updated_at', 'given_name', 'family_name',
            'company_name', 'email_address', 'phone_number', 'reference_id', 'creation_source', 'group_ids', 'segment_ids']));
        $safe['address'] = $this->savedAddress((array) ($payload['address'] ?? []));
        $safe['preferences'] = array_intersect_key((array) ($payload['preferences'] ?? []), array_flip(['email_unsubscribed', 'sms_unsubscribed']));
        $safe['cards'] = $this->cards((array) ($payload['cards'] ?? []), (string) ($payload['id'] ?? ''), $payload['_source_binding']['merchant_id'] ?? null);
        if (is_array($payload['_source_binding'] ?? null)) {
            $safe['_source_binding'] = array_intersect_key($payload['_source_binding'], array_flip(['connection_id', 'connection_generation', 'runtime_id', 'merchant_id', 'environment', 'location_ids', 'source_purpose']));
        }

        return $safe;
    }

    /** Exact Customer API saved address; card/billing addresses never enter saved customer locations. */
    private function savedAddress(array $address): array
    {
        $fields = ['address_line_1' => 255, 'address_line_2' => 255, 'locality' => 190, 'administrative_district_level_1' => 190, 'postal_code' => 32, 'country' => 2];
        $safe = [];
        foreach ($fields as $field => $limit) {
            $value = $address[$field] ?? null;
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                continue;
            }
            if ($field === 'country' && ! preg_match('/^[A-Z]{2}$/D', $value)) {
                continue;
            }
            $safe[$field] = trim($value);
        }

        return $safe;
    }

    /** Fill coherent missing saved locations only after an original owned customer association is confirmed. */
    public function applySavedLocation(MarketingProfile $profile, string $customerId): void
    {
        $tenant = (int) $profile->tenant_id;
        app(CustomerAccountFence::class)->forTenant($tenant, function () use ($tenant, $profile, $customerId): void {
            $resolver = app(CanonicalMarketingProfileResolver::class);
            $canonical = $resolver->lockedById($tenant, $profile->id);
            abort_unless($canonical, 409);
            $links = MarketingProfileLink::forTenantId($tenant)->where('source_type', 'square_customer')->where('source_id', $customerId)->lockForUpdate()->get();
            if ($links->isEmpty() || $links->contains(fn ($link) => $resolver->byId($tenant, $link->marketing_profile_id)?->id !== $canonical->id)) {
                return;
            }
            $source = SquareCustomer::forTenantId($tenant)->where('square_customer_id', $customerId)->lockForUpdate()->first();
            $address = $this->savedAddress((array) ($source?->raw_payload['address'] ?? []));
            if (! $address) {
                return;
            }
            $mapping = ['address_line_1' => 'address_line_1', 'address_line_2' => 'address_line_2', 'locality' => 'city', 'administrative_district_level_1' => 'state', 'postal_code' => 'postal_code', 'country' => 'country'];
            $incoming = [];
            foreach ($address as $key => $value) {
                $incoming[$mapping[$key]] = $value;
            }
            $existing = array_filter($canonical->only(array_values($mapping)), fn ($value) => filled($value));
            $conflicts = array_keys(array_filter($existing, fn ($value, $key) => isset($incoming[$key]) && mb_strtolower(trim((string) $value)) !== mb_strtolower($incoming[$key]), ARRAY_FILTER_USE_BOTH));
            $overlap = array_intersect_key($existing, $incoming);
            $review = $conflicts || ($existing && ! $overlap);
            $missing = $review ? [] : array_diff_key($incoming, $existing);
            if ($missing) {
                $canonical->fill($missing)->save();
            }
            $status = $review ? 'review_required' : 'source_saved_location';
            $hash = hash('sha256', json_encode($address, JSON_THROW_ON_ERROR));
            foreach ($links as $link) {
                $prior = $link->source_meta['square_saved_location'] ?? [];
                if (($prior['source_hash'] ?? null) === $hash && ($prior['status'] ?? null) === $status) {
                    continue;
                }
                $link->source_meta = [...($link->source_meta ?? []), 'square_saved_location' => ['status' => $status, 'source_hash' => $hash,
                    'basis' => 'original_customer_api_saved_address', 'source_record_id' => $source->id, 'observed_at' => now()->toIso8601String(),
                    'filled_fields' => array_keys($missing), 'conflicting_fields' => $conflicts]];
                $link->save();
            }
        });
    }

    public function cards(array $cards, string $customerId, ?string $merchantId = null): array
    {
        $safe = [];
        foreach ($cards as $card) {
            if (! is_array($card) || empty($card['id']) || (! empty($card['customer_id']) && $card['customer_id'] !== $customerId)
                || ($merchantId && ! empty($card['merchant_id']) && $card['merchant_id'] !== $merchantId)) {
                continue;
            }
            $row = array_intersect_key($card, array_flip(['id', 'customer_id', 'merchant_id', 'fingerprint', 'card_brand', 'last_4', 'enabled', 'version']));
            if (isset($row['last_4']) && ! preg_match('/^\d{4}$/D', (string) $row['last_4'])) {
                unset($row['last_4']);
            }
            $safe[] = $row;
        }

        return $safe;
    }

    public function consent(array $payload): array
    {
        $preferences = (array) ($payload['preferences'] ?? []);

        return [
            'accepts_email_marketing' => ($preferences['email_unsubscribed'] ?? null) === true ? false : null,
            'accepts_sms_marketing' => ($preferences['sms_unsubscribed'] ?? null) === true ? false : null,
            'email_opted_out_at' => null,
            'sms_opted_out_at' => null,
        ];
    }

    public function observedPreferences(array $payload): array
    {
        $preferences = (array) ($payload['preferences'] ?? []);
        $states = [];
        foreach (['email', 'sms'] as $channel) {
            $value = $preferences[$channel.'_unsubscribed'] ?? null;
            $states[$channel] = ['state' => $value === true ? 'unsubscribed' : ($value === false ? 'not_unsubscribed' : 'unknown'),
                'affirmative_permission_proven' => false, 'source_field' => 'preferences.'.$channel.'_unsubscribed',
                'source_value' => is_bool($value) ? $value : null];
        }

        return $states;
    }

    public function applyConsent(MarketingProfile $profile, array $payload, array $context = []): void
    {
        app(CustomerAccountFence::class)->forTenant((int) $profile->tenant_id, function () use ($profile, $payload, $context) {
            $current = app(CanonicalMarketingProfileResolver::class)->lockedById((int) $profile->tenant_id, (int) $profile->id);
            abort_unless($current, 409);
            $metadata = ['basis' => 'explicit_provider_unsubscribe_only', 'source_id' => $payload['id'] ?? null,
                'source_version' => $payload['version'] ?? null, 'provider_preferences' => $this->observedPreferences($payload)];
            $normalizer = app(\App\Support\Marketing\MarketingIdentityNormalizer::class);
            $email = $normalizer->normalizeEmail($payload['email_address'] ?? null);
            $phone = $normalizer->normalizePhone($payload['phone_number'] ?? null);
            $emailApplies = $email && $email === $normalizer->normalizeEmail($current->email) && ($payload['preferences']['email_unsubscribed'] ?? null) === true;
            $smsApplies = $phone && $phone === $normalizer->normalizePhone($current->phone) && ($payload['preferences']['sms_unsubscribed'] ?? null) === true;
            $channels = app(MessagingContactChannelStateService::class);
            if ($emailApplies) {
                $channels->markEmailStatus((int) $current->tenant_id, $current, $current->email, 'unsubscribed', 'provider_unsubscribe', 'square_customer_sync', $metadata);
            }
            if ($smsApplies
                && $channels->resolveSmsStatus((int) $current->tenant_id, $current, $current->phone) !== 'unsubscribed') {
                $channels->markSmsUnsubscribed((int) $current->tenant_id, $current, $current->phone, 'provider_unsubscribe', 'square_customer_sync', $metadata);
            }
            if ($emailApplies || $smsApplies) {
                app(MarketingConsentService::class)->applyToProfile($current, [
                    'accepts_email_marketing' => $emailApplies ? false : null, 'accepts_sms_marketing' => $smsApplies ? false : null,
                ], $context + ['source_type' => 'square_customer_sync', 'source_id' => $payload['id'] ?? null, 'details' => $metadata]);
            }
        });
    }

    /** Retain original transaction facts, removing unsupported protected card details. */
    public function purchase(array $payload): array
    {
        $blocked = ['pan', 'card_number', 'cvv', 'cvc', 'bin', 'cardholder_name', 'billing_address', 'exp_month', 'exp_year', 'cryptogram'];
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                unset($payload[$key]);

                continue;
            }
            if (is_array($value)) {
                $payload[$key] = $this->purchase($value);
            }
        }

        return $payload;
    }

    /** A review signal only. Never clear an existing independently acquired permission here. */
    public function legacyConsentRisk(MarketingProfile $profile, ?array $profileIds = null): array
    {
        $result = [];
        foreach (['email', 'sms'] as $channel) {
            $base = MarketingConsentEvent::query()->where('tenant_id', $profile->tenant_id)->whereIn('marketing_profile_id', $profileIds ?? [$profile->id])->where('channel', $channel);
            $legacy = (clone $base)->where('source_type', 'square_customer_sync')->where('event_type', 'imported')->count();
            $independent = 0;
            $normalizer = app(\App\Support\Marketing\MarketingIdentityNormalizer::class);
            $normalize = fn ($value) => $channel === 'email' ? $normalizer->normalizeEmail($value) : $normalizer->normalizePhone($value);
            $currentContact = $normalize($profile->getAttribute($channel === 'email' ? 'email' : 'phone'));
            foreach ((clone $base)->orderByDesc('occurred_at')->orderByDesc('id')->cursor() as $event) {
                if (in_array($event->event_type, ['revoked', 'opted_out', 'unsubscribed'], true)) {
                    break;
                }
                if ($event->source_type === 'square_customer_sync' || ! in_array($event->event_type, ['confirmed', 'opted_in', 'subscribed'], true)) {
                    continue;
                }
                $details = $event->details ?? [];
                $contact = $details[$channel === 'email' ? 'email' : 'phone'] ?? data_get($details, 'metadata.'.($channel === 'email' ? 'email' : 'phone'));
                if ($currentContact && $normalize($contact) === $currentContact) {
                    $independent = 1;
                    break;
                }
            }
            $result[$channel] = ['current_permission' => (bool) $profile->getAttribute('accepts_'.$channel.'_marketing'),
                'legacy_square_positive_events' => $legacy, 'independent_affirmative_events' => $independent,
                'evidence_basis' => $independent ? 'current_contact_affirmative_after_latest_revocation' : 'current_contact_affirmative_proof_unknown',
                'review_required' => (bool) $profile->getAttribute('accepts_'.$channel.'_marketing') && $legacy > 0 && $independent === 0];
        }

        return $result;
    }

    /** Private owner/admin projection. Never include card references in shopper, QR or AI draft payloads. */
    public function forStaff(MarketingProfile $profile, int $tenantId): array
    {
        return $this->staffProjection($profile, $tenantId);
    }

    /** Cached reads only. The caller must freshly authorize tenant owner/admin access. */
    public function historyPage(MarketingProfile $profile, int $tenantId, string $kind, ?string $cursor = null): array
    {
        abort_unless(in_array($kind, ['square_order', 'square_payment'], true), 422);
        $projection = $this->staffProjection($profile, $tenantId, ['kind' => $kind, 'cursor' => $cursor]);

        return ['customer_id' => $projection['customer_id'], 'history' => $projection['history'],
            'issues' => $projection['issues'], 'historical_import_earns_rewards' => false];
    }

    private function staffProjection(MarketingProfile $profile, int $tenantId, ?array $historyPage = null): array
    {
        $resolver = app(CanonicalMarketingProfileResolver::class);
        $canonical = $resolver->byId($tenantId, $profile->id);
        abort_unless($canonical, 404);
        $ids = [$canonical->id];
        $frontier = $ids;
        for ($depth = 0; $depth < 32 && $frontier; $depth++) {
            $frontier = MarketingProfile::query()->where('tenant_id', $tenantId)->whereIn('merged_into_profile_id', $frontier)->whereNotIn('id', $ids)->limit(1001)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }
        $sources = [];
        $issues = [];
        $links = MarketingProfileLink::query()->where('tenant_id', $tenantId)->whereIn('marketing_profile_id', $ids)->where('source_type', 'square_customer')->get();
        foreach ($links->unique('source_id') as $link) {
            $all = MarketingProfileLink::query()->where('tenant_id', $tenantId)->where('source_type', 'square_customer')->where('source_id', $link->source_id)->get();
            if ($all->contains(fn ($other) => $resolver->byId($tenantId, $other->marketing_profile_id)?->id !== $canonical->id)) {
                $issues[] = 'square_source_identity_review_required';

                continue;
            }
            if (($link->source_meta['square_saved_location']['status'] ?? null) === 'review_required') {
                $issues[] = 'square_saved_location_review_required';
            }
            $source = SquareCustomer::query()->where('tenant_id', $tenantId)->where('square_customer_id', $link->source_id)->first();
            $originalBinding = (array) ($source?->raw_payload['_source_binding'] ?? []);
            $sourceBinding = (array) ($link->source_meta ?? []) + $originalBinding;
            $normalizer = app(\App\Support\Marketing\MarketingIdentityNormalizer::class);
            if (($source?->raw_payload['preferences']['email_unsubscribed'] ?? null) === true && $normalizer->normalizeEmail($source?->email) !== $normalizer->normalizeEmail($canonical->email)) {
                $issues[] = 'square_email_unsubscribe_contact_changed_review_required';
            }
            if (($source?->raw_payload['preferences']['sms_unsubscribed'] ?? null) === true && $normalizer->normalizePhone($source?->phone) !== $normalizer->normalizePhone($canonical->phone)) {
                $issues[] = 'square_sms_unsubscribe_contact_changed_review_required';
            }
            $cards = [];
            foreach ($historyPage === null ? CustomerExternalProfile::query()->where('tenant_id', $tenantId)->where('provider', 'square')->where('integration', 'square_customer')->where('external_customer_id', $link->source_id)->get() : [] as $external) {
                $binding = data_get($external->raw_metafields, 'square.source_binding', []);
                if (empty($sourceBinding['merchant_id']) || empty($sourceBinding['environment']) || $sourceBinding['merchant_id'] !== ($binding['merchant_id'] ?? null) || $sourceBinding['environment'] !== ($binding['environment'] ?? null)) {
                    $issues[] = 'square_card_merchant_review_required';

                    continue;
                }
                foreach ($this->cards(data_get($external->raw_metafields, 'square.cards', []), $link->source_id, $binding['merchant_id'] ?? null) as $card) {
                    $cards[] = ['provider_reference' => $card['id'], 'brand' => $card['card_brand'] ?? null, 'last_4' => $card['last_4'] ?? null,
                        'enabled' => $card['enabled'] ?? null, 'fingerprint_available' => ! empty($card['fingerprint']), 'source_binding' => $binding];
                }
            }
            $sources[] = ['square_customer_id' => $link->source_id, 'identity_basis' => $link->match_method, 'source_binding' => $sourceBinding,
                'synced_at' => $source?->synced_at?->toIso8601String(), 'provider_preferences' => $this->observedPreferences($source?->raw_payload ?? []),
                'cards' => $cards, 'card_identity_or_payment_authority' => false];
        }

        return ['customer_id' => $canonical->id, 'sources' => $sources, 'legacy_consent_review' => $historyPage === null ? $this->legacyConsentRisk($canonical, $ids) : [],
            'history' => $this->history($tenantId, $ids, $sources, $issues, $historyPage), 'issues' => array_values(array_unique($issues)), 'historical_import_earns_rewards' => false];
    }

    private function history(int $tenantId, array $profileIds, array $sources, array &$issues, ?array $page = null): array
    {
        $orders = [];
        $payments = [];
        $coverage = [];
        foreach (['square_order' => [\App\Models\SquareOrder::class, 'square_order_id'], 'square_payment' => [\App\Models\SquarePayment::class, 'square_payment_id']] as $type => [$model, $column]) {
            if ($page && $page['kind'] !== $type) {
                continue;
            }
            $links = MarketingProfileLink::query()->where('tenant_id', $tenantId)->whereIn('marketing_profile_id', $profileIds)->whereIn('source_type', ['square_customer', $type])->orderBy('id')->get();
            $linked = $links->where('source_type', $type)->pluck('source_id');
            $scopeIds = $profileIds;
            sort($scopeIds, SORT_NUMERIC);
            $scopeSources = array_map(fn ($source) => [$source['square_customer_id'],
                $source['source_binding']['merchant_id'] ?? null, $source['source_binding']['environment'] ?? null,
                $source['source_binding']['connection_id'] ?? null, $source['source_binding']['connection_generation'] ?? null], $sources);
            sort($scopeSources);
            $scope = hash('sha256', json_encode([$tenantId, $profileIds[0], $scopeIds, $type, $scopeSources,
                $links->map(fn ($link) => [(int) $link->id, (int) $link->marketing_profile_id, $link->source_type, $link->source_id,
                    $link->source_meta['merchant_id'] ?? null, $link->source_meta['environment'] ?? null])->values()->all()], JSON_THROW_ON_ERROR));
            $continuation = $this->historyCursor($page['cursor'] ?? null, $tenantId, $profileIds[0], $type, $scope);
            $query = $model::query()->where('tenant_id', $tenantId)->where(function ($query) use ($column, $linked, $sources) {
                $query->whereIn($column, $linked);
                foreach ($sources as $source) {
                    $binding = $source['source_binding'];
                    if (empty($binding['merchant_id']) || empty($binding['environment'])) {
                        continue;
                    }
                    $query->orWhere(fn ($row) => $row->where('square_customer_id', $source['square_customer_id'])
                        ->where('raw_payload->_source_binding->merchant_id', $binding['merchant_id'])->where('raw_payload->_source_binding->environment', $binding['environment']));
                }
            });
            $ceiling = $continuation['ceiling'] ?? (int) (clone $query)->max('id');
            $query->where('id', '<=', $ceiling);
            $count = (clone $query)->count();
            if ($continuation) {
                $query->where('id', '<', $continuation['before']);
            }
            // Immutable local IDs keep a refresh of an older source from moving it between pages.
            $rows = $query->orderByDesc('id')->limit(51)->get();
            $visibleRows = $rows->take(50);
            $hasMore = $rows->count() > 50;
            $expires = $continuation['expires'] ?? now()->addMinutes(30)->timestamp;
            $coverage[$type] = ['cached_count' => $count, 'limit' => 50, 'has_more' => $hasMore, 'provider_complete' => false,
                'page' => ($continuation['page'] ?? 0) + 1, 'scanned_count' => $visibleRows->count(), 'order' => 'cached_id_desc',
                'next_cursor' => $hasMore ? Crypt::encryptString(json_encode(['version' => 1, 'tenant' => $tenantId, 'profile' => $profileIds[0],
                    'kind' => $type, 'scope' => $scope, 'ceiling' => $ceiling, 'before' => (int) $visibleRows->last()->id,
                    'page' => ($continuation['page'] ?? 0) + 1, 'expires' => $expires], JSON_THROW_ON_ERROR)) : null];
            foreach ($visibleRows as $row) {
                $claims = MarketingProfileLink::query()->where('tenant_id', $tenantId)->where('source_type', $type)->where('source_id', $row->$column)->get();
                if ($claims->contains(fn ($claim) => app(CanonicalMarketingProfileResolver::class)->byId($tenantId, (int) $claim->marketing_profile_id)?->id !== $profileIds[0])) {
                    $issues[] = 'square_purchase_identity_review_required';

                    continue;
                }
                $raw = $row->raw_payload ?? [];
                $base = ['id' => $row->$column, 'customer_id' => $row->square_customer_id, 'location_id' => $row->location_id, 'location_is_event' => false,
                    'source_binding' => $raw['_source_binding'] ?? [], 'synced_at' => $row->synced_at?->toIso8601String()];
                if ($type === 'square_order') {
                    $items = [];
                    foreach (array_slice((array) ($raw['line_items'] ?? []), 0, 100) as $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        $items[] = ['label' => is_string($item['name'] ?? null) ? $item['name'] : null, 'variation' => is_string($item['variation_name'] ?? null) ? $item['variation_name'] : null,
                            'quantity' => is_scalar($item['quantity'] ?? null) ? (string) $item['quantity'] : null, 'catalog_object_id' => $item['catalog_object_id'] ?? null,
                            'total_amount' => $item['total_money']['amount'] ?? null, 'currency' => $item['total_money']['currency'] ?? null, 'scent_inferred' => false];
                    }
                    $orders[] = $base + ['state' => $row->state, 'closed_at' => $row->closed_at?->toIso8601String(), 'total_amount' => $row->total_money_amount, 'currency' => $row->total_money_currency,
                        'items' => $items, 'items_complete' => count((array) ($raw['line_items'] ?? [])) <= 100, 'recorded_refunds' => count((array) ($raw['refunds'] ?? []))];
                } else {
                    $payments[] = $base + ['order_id' => $row->square_order_id, 'status' => $row->status, 'amount' => $row->amount_money, 'currency' => $row->currency,
                        'recorded_at' => $row->created_at_source?->toIso8601String(), 'refunded_amount' => data_get($raw, 'refunded_money.amount'), 'reward_earning_proof' => false];
                }
            }
            $coverage[$type]['displayed_count'] = count($type === 'square_order' ? $orders : $payments);
            $coverage[$type]['withheld_count'] = $visibleRows->count() - $coverage[$type]['displayed_count'];
        }

        return ['orders' => $orders, 'payments' => $payments, 'coverage' => $coverage, 'basis' => 'recorded_original_square_sources', 'historical_rewards_earned' => 0];
    }

    private function historyCursor(?string $cursor, int $tenant, int $profile, string $kind, string $scope): ?array
    {
        if ($cursor === null) {
            return null;
        }
        try {
            $value = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, 'This history page is invalid. Restart the cached history.');
        }
        abort_unless(is_array($value) && ($value['version'] ?? null) === 1
            && ($value['tenant'] ?? null) === $tenant && ($value['profile'] ?? null) === $profile && ($value['kind'] ?? null) === $kind
            && is_string($value['scope'] ?? null) && hash_equals($scope, $value['scope']), 409, 'The customer or source association changed. Restart the cached history.');
        abort_unless(is_int($value['expires'] ?? null) && $value['expires'] > now()->timestamp, 409, 'This history page expired. Restart the cached history.');
        abort_unless(is_int($value['ceiling'] ?? null) && is_int($value['before'] ?? null) && $value['before'] > 0 && $value['before'] <= $value['ceiling']
            && is_int($value['page'] ?? null) && $value['page'] > 0 && $value['page'] < 100000, 422, 'This history page is invalid. Restart the cached history.');

        return $value;
    }
}
