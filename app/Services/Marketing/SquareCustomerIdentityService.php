<?php

namespace App\Services\Marketing;

use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Square IDs may maintain a source association. Contact/card similarities only propose review. */
class SquareCustomerIdentityService
{
    public function __construct(private CanonicalMarketingProfileResolver $identities, private CustomerAccountFence $fence,
        private MarketingIdentityNormalizer $normalizer, private SquareCustomerSource $source, private MarketingConsentService $consent) {}

    public function sync(array $identity, int $tenantId, bool $dryRun, array $context = []): array
    {
        abort_unless($tenantId > 0, 403);
        $work = function () use ($identity, $tenantId, $dryRun, $context): array {
            $links = array_values(array_filter((array) ($identity['source_links'] ?? []), fn ($row) => in_array($row['source_type'] ?? '', ['square_customer', 'square_order', 'square_payment'], true) && filled($row['source_id'] ?? null)));
            $profiles = collect();
            $conflict = false;
            foreach ($links as $link) {
                foreach (MarketingProfileLink::query()->where('tenant_id', $tenantId)->where('source_type', $link['source_type'])->where('source_id', $link['source_id'])->get() as $existing) {
                    $profile = $this->identities->byId($tenantId, (int) $existing->marketing_profile_id);
                    if (! $profile || $profile->archived_at || (! empty($existing->source_meta['merchant_id']) && ! empty($context['merchant_id']) && ($existing->source_meta['merchant_id'] !== $context['merchant_id'] || (! empty($existing->source_meta['environment']) && $existing->source_meta['environment'] !== ($context['environment'] ?? null))))) {
                        $conflict = true;

                        continue;
                    }
                    $profiles->put($profile->id, $profile);
                }
            }
            // Old original associations survive ownership migrations. Never create a
            // second customer merely because an original link still needs review.
            foreach ($links as $link) {
                foreach (MarketingProfileLink::whereNull('tenant_id')->where('source_type', $link['source_type'])->where('source_id', $link['source_id'])->get() as $legacy) {
                    $original = $this->identities->byId($tenantId, (int) $legacy->marketing_profile_id);
                    if (! $original || ! $profiles->has($original->id)) {
                        $conflict = true;
                    }
                }
            }
            if (! $conflict && $profiles->count() === 1) {
                $profile = $profiles->first();
                $created = 0;
                foreach ($links as $link) {
                    $query = MarketingProfileLink::query()->where('tenant_id', $tenantId)->where('source_type', $link['source_type'])->where('source_id', $link['source_id']);
                    if (! $query->exists()) {
                        $created++;
                        if (! $dryRun) {
                            MarketingProfileLink::create(['tenant_id' => $tenantId, 'marketing_profile_id' => $profile->id,
                                'source_type' => $link['source_type'], 'source_id' => $link['source_id'], 'match_method' => 'explicit_square_source_id',
                                'source_meta' => array_replace((array) ($link['source_meta'] ?? []), $this->binding($context))]);
                        }
                    } elseif (! $dryRun && ($context['source_label'] ?? '') === 'square_customer_reference_reconciliation'
                        && isset($link['source_meta']['square_customer_redirect'])) {
                        foreach ($query->get() as $existing) {
                            $meta = (array) $existing->source_meta;
                            $proof = $link['source_meta']['square_customer_redirect'];
                            if (data_get($meta, 'square_customer_redirect.current_customer_id') === $proof['current_customer_id']) {
                                continue;
                            }
                            if (isset($meta['square_customer_redirect'])) {
                                $meta['square_customer_redirect_history'] = [...(array) ($meta['square_customer_redirect_history'] ?? []), $meta['square_customer_redirect']];
                            }
                            $existing->update(['source_meta' => array_replace($meta, $this->binding($context), ['square_customer_redirect' => $proof])]);
                        }
                    }
                }

                if (! $dryRun) {
                    foreach ($links as $link) {
                        if ($link['source_type'] === 'square_customer') {
                            $this->source->applySavedLocation($profile, $link['source_id']);
                            if (! empty($context['reconcile_recorded_history'])) {
                                $this->linkRecordedHistory($tenantId, $profile, $link['source_id'], $context);
                            }
                        }
                    }
                }

                return $this->result('updated', 'existing_source_link', $profile->id, $created, 0);
            }
            $primary = $identity['primary_source'] ?? ($links[0] ?? []);
            if (empty($primary['source_id'])) {
                return $this->result('skipped', 'missing_square_source_id', null, 0, 0);
            }
            $candidates = array_values(array_unique([...$this->candidates($tenantId, $identity), ...$this->cardCandidates($tenantId, $identity, $context)]));
            if (! $conflict && $profiles->isEmpty() && ! $candidates && ($primary['source_type'] ?? '') === 'square_customer') {
                if (! empty($context['create_unmatched'])) {
                    $actor = User::findOrFail((int) ($context['actor_id'] ?? 0));
                    $this->authorize($tenantId, $actor);
                    $profile = null;
                    if (! $dryRun) {
                        $profile = MarketingProfile::create(['tenant_id' => $tenantId, 'first_name' => $identity['first_name'] ?? null, 'last_name' => $identity['last_name'] ?? null,
                            'email' => $identity['raw_email'] ?? null, 'normalized_email' => $this->normalizer->normalizeEmail($identity['raw_email'] ?? null),
                            'phone' => $identity['raw_phone'] ?? null, 'normalized_phone' => $this->normalizer->normalizePhone($identity['raw_phone'] ?? null),
                            'source_channels' => ['square'], 'accepts_email_marketing' => false, 'accepts_sms_marketing' => false]);
                        foreach ($links as $link) {
                            MarketingProfileLink::create(['tenant_id' => $tenantId, 'marketing_profile_id' => $profile->id,
                                'source_type' => $link['source_type'], 'source_id' => $link['source_id'], 'match_method' => 'owner_confirmed_unmatched_square_source',
                                'source_meta' => array_replace((array) ($link['source_meta'] ?? []), $this->binding($context), ['reviewed_by' => $actor->id, 'request_id' => $context['request_id'] ?? null])]);
                        }
                    }

                    if ($profile) {
                        foreach ($links as $link) {
                            if ($link['source_type'] === 'square_customer') {
                                $this->source->applySavedLocation($profile, $link['source_id']);
                                if (! empty($context['reconcile_recorded_history'])) {
                                    $this->linkRecordedHistory($tenantId, $profile, $link['source_id'], $context);
                                }
                            }
                        }
                    }

                    return array_replace($this->result('created', 'owner_confirmed_unmatched_source', $profile?->id, count($links), 0), ['profiles_created' => 1]);
                }
                if (($context['source_label'] ?? '') === 'marketing_profiles_sync') {
                    return $this->result('skipped', 'unlinked_source_requires_explicit_connection_refresh', null, 0, 0);
                }
            }
            $reason = $conflict || $profiles->count() > 1 ? 'square_source_link_conflict' : ($candidates ? 'square_contact_candidates_require_review' : 'square_unlinked_customer_requires_review');
            $query = MarketingIdentityReview::query()->where('source_type', $primary['source_type'])->where('source_id', $primary['source_id'])
                ->where('payload->source_context->tenant_id', $tenantId)->where('status', 'pending');
            $review = $query->first();
            $created = $review ? 0 : 1;
            if (! $dryRun) {
                $review ??= new MarketingIdentityReview;
                $review->fill(['status' => 'pending', 'source_type' => $primary['source_type'], 'source_id' => $primary['source_id'],
                    'raw_email' => $identity['raw_email'] ?? null, 'raw_phone' => $identity['raw_phone'] ?? null,
                    'raw_first_name' => $identity['first_name'] ?? null, 'raw_last_name' => $identity['last_name'] ?? null,
                    'conflict_reasons' => [$reason], 'payload' => ['source_context' => ['tenant_id' => $tenantId] + $this->binding($context),
                        'source_snapshot_hash' => $this->sourceHash($tenantId, (string) $primary['source_type'], (string) $primary['source_id']),
                        'source_links' => $links, 'candidate_profile_ids' => $candidates, 'identity_policy' => 'explicit_source_ids_only']])->save();
            }

            return $this->result('review', $reason, null, 0, $created);
        };

        return $dryRun ? $work() : $this->fence->forTenant($tenantId, $work);
    }

    public function version(MarketingIdentityReview $review): string
    {
        return hash('sha256', json_encode([$review->id, $review->status, $review->payload, $review->updated_at?->toIso8601String()], JSON_THROW_ON_ERROR));
    }

    public function review(int $tenantId, User $actor, MarketingIdentityReview $review, array $command): array
    {
        $this->authorize($tenantId, $actor);
        $input = Validator::make($command, ['request_id' => 'required|uuid', 'expected_version' => 'required|string|size:64',
            'decision' => ['required', Rule::in(['link_existing', 'create_new', 'skip'])], 'profile_id' => 'nullable|integer|min:1',
            'identity_confirmed' => 'required|accepted', 'reason' => 'required|string|min:12|max:1000'])->validate();

        return $this->fence->forTenant($tenantId, function () use ($tenantId, $actor, $review, $input): array {
            $this->authorize($tenantId, $actor);
            $fresh = MarketingIdentityReview::whereKey($review->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) data_get($fresh->payload, 'source_context.tenant_id') === $tenantId && $fresh->source_type === 'square_customer', 404);
            $receipt = $fresh->payload['square_review'] ?? null;
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            if ($receipt) {
                abort_unless($receipt['request_id'] === $input['request_id'] && hash_equals($receipt['hash'], $hash), 409, 'This source review is already resolved.');

                return $receipt['result'];
            }
            abort_unless($fresh->status === 'pending' && hash_equals($this->version($fresh), $input['expected_version']), 409, 'The Square review changed. Refresh it before deciding.');
            $binding = (array) data_get($fresh->payload, 'source_context', []);
            if (isset($binding['connection_id'], $binding['connection_generation'])) {
                app(SquareConnectionContext::class)->assertCurrent($tenantId, $binding);
            }
            $customer = SquareCustomer::query()->where('tenant_id', $tenantId)->where('square_customer_id', $fresh->source_id)->lockForUpdate()->firstOrFail();
            abort_unless(empty($fresh->payload['source_snapshot_hash']) || hash_equals($fresh->payload['source_snapshot_hash'], $this->sourceHash($tenantId, 'square_customer', $customer->square_customer_id)), 409, 'The Square source changed. Refresh this review first.');
            $profile = null;
            if ($input['decision'] === 'link_existing') {
                $profile = $this->identities->lockedById($tenantId, (int) ($input['profile_id'] ?? 0));
                abort_unless($profile && ! $profile->archived_at && $profile->id === (int) $input['profile_id'], 409, 'Select the current canonical tenant customer.');
            } elseif ($input['decision'] === 'create_new') {
                $profile = MarketingProfile::create(['tenant_id' => $tenantId, 'first_name' => $customer->given_name, 'last_name' => $customer->family_name,
                    'email' => $customer->email, 'normalized_email' => $this->normalizer->normalizeEmail($customer->email),
                    'phone' => $customer->phone, 'normalized_phone' => $this->normalizer->normalizePhone($customer->phone),
                    'accepts_email_marketing' => false, 'accepts_sms_marketing' => false, 'source_channels' => ['square']]);
            }
            if ($profile) {
                $existing = MarketingProfileLink::query()->where('tenant_id', $tenantId)->where('source_type', 'square_customer')->where('source_id', $customer->square_customer_id)->get();
                abort_if($existing->contains(fn ($link) => $this->identities->byId($tenantId, (int) $link->marketing_profile_id)?->id !== $profile->id), 409, 'The Square source is already linked to another customer.');
                if ($existing->isEmpty()) {
                    MarketingProfileLink::create(['tenant_id' => $tenantId, 'marketing_profile_id' => $profile->id,
                        'source_type' => 'square_customer', 'source_id' => $customer->square_customer_id, 'match_method' => 'reviewed_square_source_id',
                        'source_meta' => ['review_id' => $fresh->id, 'reviewed_by' => $actor->id] + (array) data_get($fresh->payload, 'source_context', [])]);
                }
                $this->source->applyConsent($profile, $customer->raw_payload ?? [], ['source_type' => 'square_customer_sync',
                    'source_id' => $customer->square_customer_id, 'tenant_id' => $tenantId, 'details' => ['basis' => 'explicit_provider_unsubscribe_only']]);
                $this->source->applySavedLocation($profile, $customer->square_customer_id);
                $history = $this->linkRecordedHistory($tenantId, $profile, $customer->square_customer_id, $binding);
            }
            $result = ['review_id' => $fresh->id, 'status' => $input['decision'] === 'skip' ? 'skipped' : 'resolved', 'profile_id' => $profile?->id,
                'history_link_conflicts' => $history ?? 0, 'messages_sent' => 0, 'rewards_earned' => 0];
            $payload = $fresh->payload;
            $payload['square_review'] = ['request_id' => $input['request_id'], 'hash' => $hash, 'result' => $result];
            $fresh->fill(['status' => $result['status'], 'payload' => $payload, 'proposed_marketing_profile_id' => $profile?->id,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'resolution_notes' => $input['reason']])->save();

            return $result;
        });
    }

    public function authorize(int $tenantId, User $actor): void
    {
        $tenant = \App\Models\Tenant::query()->whereKey($tenantId)->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
        $access = \App\Models\TenantAccessProfile::where('tenant_id', $tenantId)->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
        $state = \App\Models\TenantModuleState::where('tenant_id', $tenantId)->where('module_key', 'customers')->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
        abort_if(data_get($access?->metadata, 'workspace_focus') === 'soccer' || ($state && $state->getRawOriginal('enabled_override') !== null && ! $state->enabled_override), 403);
        abort_unless(app(TenantModuleAccessResolver::class)->canAccess($tenantId, 'customers'), 403);
        $current = User::query()->whereKey($actor->id)->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
        $member = $current?->tenants()->whereKey($tenantId)->wherePivot('membership_active', true)->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($current?->is_active && $current->email_verified_at && $tenant && app(CustomerProfileAccess::class)->allows($current, $tenant) && $member
            && in_array(strtolower((string) $member->pivot->role), ['owner', 'tenant_owner', 'admin'], true), 403);
    }

    private function candidates(int $tenant, array $identity): array
    {
        $email = $this->normalizer->normalizeEmail($identity['raw_email'] ?? null);
        $phone = $this->normalizer->normalizePhone($identity['raw_phone'] ?? null);
        $name = trim(($identity['first_name'] ?? '').' '.($identity['last_name'] ?? ''));
        $first = strtolower(trim((string) ($identity['first_name'] ?? '')));
        $last = strtolower(trim((string) ($identity['last_name'] ?? '')));
        if (! $email && ! $phone && $name === '') {
            return [];
        }

        return MarketingProfile::query()->where('tenant_id', $tenant)->where(function ($query) use ($email, $phone, $name, $first, $last) {
            if ($email) {
                $query->orWhere('normalized_email', $email)->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]);
            }
            if ($phone) {
                $query->orWhere('normalized_phone', $phone);
                $digits = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', '')";
                $query->orWhereRaw($digits.' IN (?, ?)', [$phone, strlen($phone) === 10 ? '1'.$phone : $phone]);
            }
            if ($name !== '') {
                $query->orWhere(fn ($names) => $names->whereRaw("LOWER(TRIM(COALESCE(first_name, ''))) = ?", [$first])->whereRaw("LOWER(TRIM(COALESCE(last_name, ''))) = ?", [$last]));
            }
        })->limit(25)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function linkRecordedHistory(int $tenantId, MarketingProfile $profile, string $customerId, array $binding): int
    {
        $conflicts = 0;
        foreach (['square_order' => [SquareOrder::class, 'square_order_id'], 'square_payment' => [SquarePayment::class, 'square_payment_id']] as $type => [$model, $key]) {
            foreach ($model::query()->where('tenant_id', $tenantId)->where('square_customer_id', $customerId)->cursor() as $source) {
                $original = $source->raw_payload['_source_binding'] ?? [];
                if (empty($binding['merchant_id']) || empty($binding['environment']) || ($original['merchant_id'] ?? null) !== $binding['merchant_id'] || ($original['environment'] ?? null) !== $binding['environment']) {
                    $conflicts++;

                    continue;
                }
                $links = MarketingProfileLink::query()->where('tenant_id', $tenantId)->where('source_type', $type)->where('source_id', $source->$key)->get();
                if ($links->contains(fn ($link) => $this->identities->byId($tenantId, (int) $link->marketing_profile_id)?->id !== $profile->id)) {
                    $conflicts++;

                    continue;
                }
                if ($links->isEmpty()) {
                    MarketingProfileLink::create(['tenant_id' => $tenantId, 'marketing_profile_id' => $profile->id, 'source_type' => $type,
                        'source_id' => $source->$key, 'match_method' => 'reviewed_square_customer_relation', 'source_meta' => ['square_customer_id' => $customerId, 'location_id' => $source->location_id, 'location_is_event' => false] + $this->binding($binding)]);
                }
            }
        }

        return $conflicts;
    }

    /** Only provider IDs/fingerprints within one merchant namespace can suggest a card collision. */
    private function cardCandidates(int $tenantId, array $identity, array $context): array
    {
        $customerId = (string) data_get($identity, 'primary_source.source_id', '');
        if (data_get($identity, 'primary_source.source_type') !== 'square_customer' || empty($context['merchant_id'])) {
            return [];
        }
        $rows = \App\Models\CustomerExternalProfile::query()->where('tenant_id', $tenantId)->where('provider', 'square')->where('integration', 'square_customer')
            ->where('raw_metafields->square->source_binding->merchant_id', $context['merchant_id'])
            ->where('raw_metafields->square->source_binding->environment', $context['environment'] ?? null);
        $owned = (clone $rows)->where('external_customer_id', $customerId)->get()->flatMap(fn ($row) => data_get($row->raw_metafields, 'square.cards', []));
        if ($owned->isEmpty()) {
            return [];
        }
        $ids = $owned->pluck('id')->filter()->all();
        $fingerprints = $owned->pluck('fingerprint')->filter()->all();
        $result = [];
        foreach ((clone $rows)->where('external_customer_id', '!=', $customerId)->cursor() as $row) {
            $collision = collect(data_get($row->raw_metafields, 'square.cards', []))->contains(fn ($card) => in_array($card['id'] ?? '', $ids, true) || (filled($card['fingerprint'] ?? null) && in_array($card['fingerprint'], $fingerprints, true)));
            if (! $collision) {
                continue;
            }
            foreach (MarketingProfileLink::query()->where('tenant_id', $tenantId)->where('source_type', 'square_customer')->where('source_id', $row->external_customer_id)->pluck('marketing_profile_id') as $id) {
                $canonical = $this->identities->byId($tenantId, (int) $id);
                if ($canonical) {
                    $result[] = $canonical->id;
                }
            }
        }

        return array_values(array_unique($result));
    }

    private function sourceHash(int $tenantId, string $type, string $id): ?string
    {
        if ($type !== 'square_customer') {
            return null;
        }
        $customer = SquareCustomer::query()->where('tenant_id', $tenantId)->where('square_customer_id', $id)->first();

        return $customer ? hash('sha256', json_encode($this->source->customer($customer->raw_payload ?? []), JSON_THROW_ON_ERROR)) : null;
    }

    private function binding(array $context): array
    {
        return array_intersect_key($context, array_flip(['merchant_id', 'environment', 'connection_id', 'connection_generation', 'runtime_id', 'source_label', 'source_purpose']));
    }

    private function result(string $status, string $reason, ?int $profile, int $links, int $reviews): array
    {
        return ['status' => $status, 'reason' => $reason, 'profile_id' => $profile, 'profiles_created' => 0, 'profiles_updated' => 0,
            'links_created' => $links, 'links_reused' => $profile ? 1 : 0, 'reviews_created' => $reviews, 'records_skipped' => $status === 'skipped' ? 1 : 0];
    }
}
