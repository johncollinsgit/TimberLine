<?php

namespace App\Services\Marketing;

use App\Models\LandlordOperatorAction;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MessagingContactChannelState;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Models\WebsiteCustomer;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Explicit tenant relationship settings, never inferred from purchases or provider data. */
class CustomerPreferenceService
{
    private const KEY = 'customer_preferences.v1.';

    private const FIELDS = ['favorite_scents', 'avoided_scents', 'preferred_channel', 'receipt_email', 'receipt_sms', 'rewards_communications'];

    public function __construct(
        private CanonicalMarketingProfileResolver $identities,
        private CustomerAccountFence $fence,
        private MarketingIdentityNormalizer $normalizer,
        private MessagingContactChannelStateService $channels,
        private MarketingConsentService $consent,
        private LandlordOperatorActionAuditService $audit,
    ) {}

    /** Caller must authorize current tenant/customer capability; never use on a public rewards lookup. */
    public function forStaff(MarketingProfile $profile, int $tenantId): array
    {
        return $this->projection($this->canonical($profile, $tenantId), $tenantId, true);
    }

    /** Caller must authenticate this exact customer through an existing account flow. */
    public function forCustomer(MarketingProfile $profile, int $tenantId): array
    {
        return $this->projection($this->canonical($profile, $tenantId), $tenantId, false);
    }

    /** Only call with the active host-bound session's Website customer, never a request-supplied ID. */
    public function forWebsiteCustomer(WebsiteCustomer $customer, int $tenantId): ?array
    {
        $profile = $this->websiteProfile($customer, $tenantId);

        return $profile ? $this->forCustomer($profile, $tenantId) : null;
    }

    public function updateForWebsiteCustomer(WebsiteCustomer $customer, int $tenantId, array $command): array
    {
        return $this->fence->forTenant($tenantId, function () use ($customer, $tenantId, $command): array {
            $fresh = WebsiteCustomer::query()->forTenantId($tenantId)->whereKey($customer->id)->where('status', 'active')->lockForUpdate()->first();
            abort_unless($fresh, 403);
            $profile = $this->websiteProfile($fresh, $tenantId);
            abort_unless($profile, 409, 'Your customer association needs review before shared preferences can be changed.');

            return $this->update($profile, $tenantId, $command, [
                'actor_type' => 'website_customer', 'actor_id' => (string) $fresh->id,
                'source' => 'website_account', 'verified_email' => $fresh->email,
            ], false);
        });
    }

    /** Staff records an expressly reported choice; existing verified consent flows remain the opt-in authority. */
    public function updateForStaff(MarketingProfile $profile, int $tenantId, User $actor, array $command): array
    {
        $member = $actor->tenants()->whereKey($tenantId)->first();
        abort_unless($actor->is_active && $actor->email_verified_at && $member && $member->pivot->membership_active
            && in_array(strtolower((string) $member->pivot->role), ['owner', 'tenant_owner', 'admin', 'manager'], true), 403);
        Validator::make($command, ['customer_confirmed' => ['required', 'accepted']])->validate();

        return $this->fence->forTenant($tenantId, fn () => $this->update($profile, $tenantId, $command, [
            'actor_type' => 'staff', 'actor_id' => (string) $actor->id, 'source' => 'staff_customer_choice',
        ], true));
    }

    /** Existing authenticated account adapters may supply server-verified contact evidence only. */
    public function updateForCustomer(MarketingProfile $profile, int $tenantId, array $command, string $source, ?string $verifiedEmail = null, ?string $verifiedPhone = null): array
    {
        abort_unless(in_array($source, ['authenticated_customer', 'signed_customer_account', 'mobile_customer_account'], true), 403);

        return $this->fence->forTenant($tenantId, fn () => $this->update($profile, $tenantId, $command, [
            'actor_type' => 'customer', 'actor_id' => (string) $profile->id, 'source' => $source,
            'verified_email' => $verifiedEmail, 'verified_phone' => $verifiedPhone,
        ], false));
    }

    private function update(MarketingProfile $profile, int $tenantId, array $command, array $actor, bool $staff): array
    {
        $data = Validator::make($command, [
            'request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'string', 'size:64'],
            'changes' => ['required', 'array:favorite_scents,avoided_scents,preferred_channel,receipt_email,receipt_sms,rewards_communications,email_marketing,sms_marketing,first_name,last_name,email,phone', 'min:1'],
            'changes.favorite_scents' => ['sometimes', 'nullable', 'array', 'max:20'],
            'changes.favorite_scents.*' => ['required', 'string', 'max:120'],
            'changes.avoided_scents' => ['sometimes', 'nullable', 'array', 'max:20'],
            'changes.avoided_scents.*' => ['required', 'string', 'max:120'],
            'changes.preferred_channel' => ['sometimes', 'nullable', Rule::in(['email', 'sms', 'none'])],
            'changes.receipt_email' => ['sometimes', 'nullable', 'boolean'], 'changes.receipt_sms' => ['sometimes', 'nullable', 'boolean'],
            'changes.rewards_communications' => ['sometimes', 'nullable', 'boolean'],
            'changes.email_marketing' => ['sometimes', 'boolean'], 'changes.sms_marketing' => ['sometimes', 'boolean'],
            'changes.first_name' => ['sometimes', 'nullable', 'string', 'max:120'], 'changes.last_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'changes.email' => ['prohibited'], 'changes.phone' => ['prohibited'],
        ], ['changes.email.prohibited' => 'Changing a sign-in email requires the existing contact verification and identity review flow.',
            'changes.phone.prohibited' => 'Changing a shared phone requires the existing contact verification and identity review flow.'])->validate();
        $profile = $this->canonical($profile, $tenantId);
        $changes = $data['changes'];
        foreach ($changes as $key => &$value) {
            if ($value === '' && in_array($key, ['preferred_channel', 'receipt_email', 'receipt_sms', 'rewards_communications'], true)) {
                $value = null;
            }
            if (in_array($key, ['favorite_scents', 'avoided_scents'], true) && is_array($value)) {
                $value = array_values(array_unique(array_map(fn ($v) => trim($v), $value)));
                if (in_array('', $value, true)) {
                    throw ValidationException::withMessages(['changes.'.$key => 'Each scent needs a name.']);
                }
            } elseif (in_array($key, ['receipt_email', 'receipt_sms', 'rewards_communications', 'email_marketing', 'sms_marketing'], true) && $value !== null) {
                $value = (bool) $value;
            } elseif (in_array($key, ['first_name', 'last_name'], true)) {
                $value = trim((string) $value) ?: null;
            }
        }
        unset($value);
        if (($changes['preferred_channel'] ?? null) === 'none') {
            if (($changes['email_marketing'] ?? false) === true || ($changes['sms_marketing'] ?? false) === true) {
                throw ValidationException::withMessages(['changes.preferred_channel' => 'No marketing contact cannot be combined with a marketing opt-in.']);
            }
            $changes['email_marketing'] = false;
            $changes['sms_marketing'] = false;
        }
        ksort($changes);
        $hash = hash('sha256', json_encode([$data['expected_version'], $changes], JSON_THROW_ON_ERROR));
        $prior = LandlordOperatorAction::query()->forTenantId($tenantId)->where('action_type', 'customer_preferences_update')
            ->where('target_type', 'marketing_profile')->where('target_id', (string) $profile->id)
            ->where('context->request_id', $data['request_id'])->first();
        if ($prior) {
            abort_unless(hash_equals((string) data_get($prior->context, 'command_hash', ''), $hash)
                && data_get($prior->context, 'actor_type') === $actor['actor_type']
                && data_get($prior->context, 'actor_id') === $actor['actor_id'], 409, 'This request ID was already used for another change.');

            return $this->projection($profile, $tenantId, $staff);
        }
        // Existing email unsubscribe writers lock this same profile before channel changes.
        $profile = MarketingProfile::query()->forTenantId($tenantId)->whereKey($profile->id)->lockForUpdate()->firstOrFail();
        $before = $this->projection($profile, $tenantId, true);
        abort_unless(hash_equals($before['version'], $data['expected_version']), 409, 'Preferences changed. Reload before saving again.');
        $state = $this->state($profile, $tenantId);
        $fields = $state['own_fields'];
        $at = now()->toIso8601String();
        foreach ($changes as $key => $value) {
            if (in_array($key, self::FIELDS, true)) {
                $fields[$key] = ['value' => $value, 'recorded_at' => $at, 'source' => $actor['source'],
                    'actor_type' => $actor['actor_type'], 'actor_id' => $actor['actor_id'], 'profile_id' => (int) $profile->id,
                    'alias_basis' => $state['alias_basis']];
            }
        }
        $this->applyConsent($profile, $tenantId, $changes, $actor, $data['request_id']);
        $contact = array_intersect_key($changes, array_flip(['first_name', 'last_name']));
        if ($contact !== []) {
            $profile->fill($contact)->save();
        }
        $revision = $state['revision'] + 1;
        TenantMarketingSetting::query()->updateOrCreate(['tenant_id' => $tenantId, 'key' => self::KEY.$profile->id], [
            'value' => ['schema' => 1, 'profile_id' => (int) $profile->id, 'revision' => $revision, 'updated_at' => $at, 'fields' => $fields],
            'description' => 'Explicit canonical customer relationship preferences; private account access only.',
        ]);
        $after = $this->projection($profile->fresh(), $tenantId, true);
        // Preference audit deliberately excludes raw contact values, staff notes and authentication evidence.
        $auditChanges = array_intersect_key($changes, array_flip(self::FIELDS));
        $this->audit->record($tenantId, $staff ? (int) $actor['actor_id'] : null, 'customer_preferences_update', 'success', 'marketing_profile', (string) $profile->id,
            ['request_id' => $data['request_id'], 'command_hash' => $hash, 'actor_type' => $actor['actor_type'], 'actor_id' => $actor['actor_id'],
                'source' => $actor['source'], 'changed_fields' => array_keys($changes)], [],
            ['revision' => $before['revision'], 'preferences' => array_intersect_key($before['recorded_choices'], $auditChanges)],
            ['revision' => $revision, 'preferences' => $auditChanges], ['version' => $after['version'], 'revision' => $revision]);

        return $this->projection($profile, $tenantId, $staff);
    }

    private function applyConsent(MarketingProfile $profile, int $tenantId, array $changes, array $actor, string $requestId): void
    {
        $context = ['tenant_id' => $tenantId, 'source_type' => 'customer_preference_center', 'source_id' => $requestId,
            'details' => ['source' => $actor['source'], 'actor_type' => $actor['actor_type']]];
        if (array_key_exists('email_marketing', $changes)) {
            if ($changes['email_marketing']) {
                $email = $this->normalizer->normalizeEmail($profile->email);
                $verified = $this->normalizer->normalizeEmail($actor['verified_email'] ?? null);
                $unambiguous = $email ? $this->identities->byEmail($tenantId, $email) : null;
                if (! $email || $email !== $verified || $unambiguous?->id !== $profile->id) {
                    throw ValidationException::withMessages(['changes.email_marketing' => 'Email opt-in requires verification of this account’s existing email and an unambiguous customer association.']);
                }
                $blockedAddress = MessagingContactChannelState::query()->forTenantId($tenantId)->where('email', $email)->whereIn('email_status', ['suppressed', 'bounced'])->exists();
                if ($blockedAddress) {
                    throw ValidationException::withMessages(['changes.email_marketing' => 'This address needs delivery review before marketing can be enabled.']);
                }
            }
            $this->consent->setEmailConsent($profile, $changes['email_marketing'], $context);
        }
        if (array_key_exists('sms_marketing', $changes)) {
            if ($changes['sms_marketing']) {
                $phone = $this->normalizer->toE164($profile->phone);
                $verified = $this->normalizer->toE164($actor['verified_phone'] ?? null);
                $matches = MarketingProfile::query()->forTenantId($tenantId)->whereIn('normalized_phone', $this->normalizer->phoneMatchCandidates($profile->phone))->get();
                $unambiguous = $this->identities->oneCanonical($matches, $tenantId);
                if (! $phone || $phone !== $verified || $unambiguous?->id !== $profile->id) {
                    throw ValidationException::withMessages(['changes.sms_marketing' => 'SMS opt-in requires the existing phone confirmation flow. Saving receipt or scent preferences never grants SMS consent.']);
                }
                $this->channels->markSmsSubscribed($tenantId, $profile, $profile->phone, 'explicit_customer_choice', 'customer_preference_center', $context['details']);
                $this->consent->setSmsConsent($profile, true, $context);
            } else {
                $this->channels->markSmsUnsubscribed($tenantId, $profile, $profile->phone, 'explicit_customer_choice', 'customer_preference_center', $context['details']);
            }
        }
    }

    private function projection(MarketingProfile $profile, int $tenantId, bool $staff): array
    {
        $state = $this->state($profile, $tenantId);
        $choice = fn (string $key) => $state['fields'][$key]['value'] ?? null;
        $email = $this->channels->resolveEmailStatus($tenantId, $profile);
        $sms = $profile->sms_opted_out_at ? 'unsubscribed' : $this->channels->resolveSmsStatus($tenantId, $profile, $profile->phone);
        if ($profile->email_opted_out_at) {
            $email = 'unsubscribed';
        }
        $contact = ['first_name' => $profile->first_name, 'last_name' => $profile->last_name,
            'email' => $profile->email, 'phone' => $profile->phone, 'identity_contact_changes' => 'verification_required'];
        $version = hash('sha256', json_encode([$profile->id, $state['versions'], $contact, $email, $sms,
            $profile->email_opted_out_at?->toIso8601String(), $profile->sms_opted_out_at?->toIso8601String()], JSON_THROW_ON_ERROR));
        $provenance = [];
        foreach ($state['fields'] as $key => $entry) {
            $provenance[$key] = $staff ? array_diff_key($entry, ['value' => true, 'alias_basis' => true])
                : array_intersect_key($entry, array_flip(['recorded_at', 'source', 'actor_type']));
        }
        $result = ['schema_version' => 1, 'profile_id' => (int) $profile->id, 'version' => $version, 'revision' => $state['revision'],
            'updated_at' => $state['updated_at'], 'contact' => $contact,
            'declared' => ['favorite_scents' => $choice('favorite_scents'), 'avoided_scents' => $choice('avoided_scents'), 'basis' => 'explicit_customer_choice'],
            'communication' => ['preferred_channel' => $choice('preferred_channel'), 'email_marketing' => $email, 'sms_marketing' => $sms],
            'receipts' => ['email' => $choice('receipt_email'), 'sms' => $choice('receipt_sms'), 'grants_marketing_consent' => false],
            'rewards' => ['communications' => $choice('rewards_communications'), 'membership_status' => 'unknown'],
            'provenance' => $provenance, 'issues' => $state['issues']];
        if ($staff) {
            $result['recorded_choices'] = collect($state['fields'])->map(fn ($entry) => $entry['value'])->all();
        }

        return $result;
    }

    /** Retained aliases share current settings; contradictory old declarations stay unknown until reviewed. */
    private function state(MarketingProfile $profile, int $tenantId): array
    {
        $ids = [(int) $profile->id];
        do {
            $previous = count($ids);
            $ids = array_values(array_unique([...$ids, ...MarketingProfile::query()->forTenantId($tenantId)->whereIn('merged_into_profile_id', $ids)->pluck('id')->map('intval')->all()]));
        } while (count($ids) !== $previous);
        sort($ids);
        $rows = TenantMarketingSetting::query()->forTenantId($tenantId)->whereIn('key', array_map(fn ($id) => self::KEY.$id, $ids))->orderBy('key')->get();
        $versions = $rows->mapWithKeys(fn ($row) => [$row->key => $row->value])->all();
        $aliasValues = array_diff_key($versions, [self::KEY.$profile->id => true]);
        $aliasBasis = hash('sha256', json_encode($aliasValues, JSON_THROW_ON_ERROR));
        $own = (array) ($versions[self::KEY.$profile->id] ?? []);
        $fields = [];
        $issues = [];
        foreach (self::FIELDS as $field) {
            $entries = [];
            foreach ($versions as $row) {
                if (array_key_exists($field, $row['fields'] ?? [])) {
                    $entries[] = $row['fields'][$field];
                }
            }
            if ($entries === []) {
                continue;
            }
            $entry = $own['fields'][$field] ?? $entries[0];
            $values = array_unique(array_map(fn ($item) => json_encode($item['value'], JSON_THROW_ON_ERROR), $entries));
            if (count($values) > 1 && ($entry['alias_basis'] ?? '') !== $aliasBasis) {
                $issues[] = 'alias_preference_review_required';

                continue;
            }
            $fields[$field] = $entry;
        }

        return ['fields' => $fields, 'own_fields' => (array) ($own['fields'] ?? []), 'versions' => $versions, 'alias_basis' => $aliasBasis,
            'revision' => (int) ($own['revision'] ?? 0), 'updated_at' => $rows->map(fn ($row) => data_get($row->value, 'updated_at'))->filter()->sort()->last(),
            'issues' => array_values(array_unique($issues))];
    }

    private function canonical(MarketingProfile $profile, int $tenantId): MarketingProfile
    {
        // Reload rather than trusting a model captured before a merge, revocation or tenant change.
        $fresh = $tenantId > 0 ? $this->identities->byId($tenantId, (int) $profile->id) : null;
        abort_unless($fresh && (int) $profile->tenant_id === $tenantId && $fresh->archived_at === null, 404);

        return $fresh;
    }

    private function websiteProfile(WebsiteCustomer $customer, int $tenantId): ?MarketingProfile
    {
        if ($tenantId < 1 || (int) $customer->tenant_id !== $tenantId || $customer->status !== 'active') {
            return null;
        }
        $links = MarketingProfileLink::query()->forTenantId($tenantId)->where('source_type', 'website_customer')
            ->where('source_id', (string) $customer->id)->with('marketingProfile')->get();
        if ($links->contains(fn ($link) => $link->marketingProfile === null)) {
            return null;
        }
        $profile = $this->identities->oneCanonical($links->pluck('marketingProfile'), $tenantId);

        return $profile && $profile->archived_at === null ? $profile : null;
    }
}
