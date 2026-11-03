<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\BirthdayProfileService;
use App\Services\Marketing\MarketingConsentService;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Str;

/** Cashier-recorded choices for an explicitly selected tenant customer. */
final class PosCustomerEnrollment
{
    private const KEY = 'pos_customer_enrollment';

    public function __construct(private PosStore $store, private PosAccess $access, private PosCustomerDirectory $customers) {}

    public function settings(string $register): array
    {
        $scope = $this->access->register($register, 'catalog');

        return [...$this->policy((int) $scope['tenant_id']), 'can_manage' => in_array($scope['role'], ['owner', 'tenant_owner', 'admin'], true),
            'can_create_customers' => $this->canCreateCustomer($scope)];
    }

    /** The caller must have already validated the assigned register and device. */
    public function canCreateCustomer(array $scope): bool
    {
        return in_array($scope['role'], ['owner', 'tenant_owner', 'admin'], true)
            || $this->policy((int) $scope['tenant_id'])['cashier_create_enabled'];
    }

    public function configure(string $register, array $input): array
    {
        $scope = $this->access->register($register, 'admin');
        if (! in_array($scope['role'], ['owner', 'tenant_owner', 'admin'], true)) {
            throw new PosException('organization_admin_required', 403);
        }
        if (($input['confirmed'] ?? null) !== true) {
            throw new PosException('enrollment_review_required', 422);
        }

        return $this->store->db->transaction(function () use ($scope, $input, $register): array {
            $tenant = Tenant::query()->whereKey($scope['tenant_id'])->lockForUpdate()->firstOrFail();
            $current = $this->policy((int) $scope['tenant_id']);
            if (! hash_equals($current['version'], $input['version'])) {
                throw new PosException('enrollment_settings_changed');
            }
            $values = ['cashier_create_enabled' => ($input['cashier_create_enabled'] ?? false) === true,
                'birthday_enabled' => $input['birthday_enabled'], 'email_enabled' => $input['email_enabled'],
                'email_wording' => trim($input['email_wording'] ?? ''), 'sms_enabled' => $input['sms_enabled'],
                'sms_wording' => trim($input['sms_wording'] ?? '')];
            foreach (['email', 'sms'] as $channel) {
                $text = $values[$channel.'_wording'];
                if ($values[$channel.'_enabled'] && ($text === '' || mb_strlen($text) > 1000 || stripos($text, $tenant->name) === false)) {
                    throw new PosException('enrollment_wording_invalid', 422);
                }
            }
            if ($values['sms_enabled'] && (! preg_match('/\\bSTOP\\b/i', $values['sms_wording'])
                || ! preg_match('/(?:message|msg).*?(?:data|rates)/i', $values['sms_wording'])
                || ! preg_match('/frequency/i', $values['sms_wording']))) {
                throw new PosException('enrollment_wording_invalid', 422);
            }
            TenantMarketingSetting::query()->forTenantId($scope['tenant_id'])->updateOrCreate(
                ['tenant_id' => $scope['tenant_id'], 'key' => self::KEY], ['value' => [...$values, 'reviewed_hash' => Contract::hash($values),
                    'reviewed_by' => $scope['actor_id'], 'reviewed_at' => now()->toIso8601String()]]);
            $this->store->event($scope['tenant_id'], substr(hash('sha256', 'enrollment-settings:'.$scope['tenant_id'].':'.Str::uuid()), 0, 32),
                'enrollment_settings_reviewed', ['actor_id' => $scope['actor_id'], 'register_id' => $register, 'version' => Contract::hash($values)]);

            return [...$this->policy((int) $scope['tenant_id']), 'can_manage' => true, 'can_create_customers' => true];
        }, 3);
    }

    public function enroll(string $register, int $profileId, array $input): array
    {
        $scope = $this->access->register($register, 'quote');
        $profile = $this->customers->profile((int) $scope['tenant_id'], $profileId);
        if ((int) $profile->id !== $profileId) {
            throw new PosException('customer_selection_changed');
        }
        $key = substr(Contract::hash(['customer-enrollment', $this->store->runtime(), $scope['tenant_id'], $register,
            $scope['actor_id'], $scope['device_id'], strtolower($input['request_id'])]), 0, 32);
        $hash = Contract::hash([$profileId, $input]);

        return $this->store->db->transaction(function () use ($scope, $register, $profileId, $input, $key, $hash): array {
            Tenant::query()->whereKey($scope['tenant_id'])->lockForUpdate()->firstOrFail();
            $prior = $this->store->rows('events', $scope['tenant_id'])->where('sale_id', $key)->where('code', 'customer_enrollment')->first();
            if ($prior) {
                $stored = $this->store->decrypt($prior->details);
                if (! hash_equals($stored['input_hash'], $hash)) {
                    throw new PosException('customer_enrollment_request_conflict');
                }

                return $stored['result'];
            }
            $current = $this->customers->profile((int) $scope['tenant_id'], $profileId);
            if ((int) $current->id !== $profileId) {
                throw new PosException('customer_selection_changed');
            }
            $profile = MarketingProfile::query()->forTenantId($scope['tenant_id'])->whereKey($profileId)->lockForUpdate()->firstOrFail();
            $policy = $this->policy((int) $scope['tenant_id']);
            if (! hash_equals($policy['version'], $input['version'])) {
                throw new PosException('enrollment_settings_changed');
            }
            $birthday = $input['birthday'] ?? null;
            $email = ($input['email_opt_in'] ?? false) === true;
            $sms = ($input['sms_opt_in'] ?? false) === true;
            if (! $birthday && ! $email && ! $sms) {
                throw new PosException('enrollment_choice_required', 422);
            }
            if ($birthday && ! $policy['birthday_enabled']) {
                throw new PosException('birthday_enrollment_unavailable', 422);
            }
            if ($email && (! $policy['email_enabled'] || ! filter_var((string) $profile->email, FILTER_VALIDATE_EMAIL))) {
                throw new PosException('email_enrollment_unavailable', 422);
            }
            if ($sms && (! $policy['sms_enabled'] || ! app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->toE164((string) $profile->phone))) {
                throw new PosException('sms_enrollment_unavailable', 422);
            }
            $smsReasons = $sms ? app(\App\Services\Marketing\MarketingSmsEligibilityService::class)->evaluateProfile($profile, (int) $scope['tenant_id'])['reason_codes'] : [];
            if (($email && $profile->email_opted_out_at) || ($sms && $profile->sms_opted_out_at)
                || ($email && app(\App\Services\Marketing\MarketingEmailUnsubscribeService::class)->suppressed((int) $scope['tenant_id'], (string) $profile->email))
                || array_intersect($smsReasons, ['sms_stop_suppressed', 'sms_unsubscribed', 'sms_suppressed', 'sms_bounced'])) {
                throw new PosException('customer_consent_review_required');
            }
            if ($birthday) {
                $month = $birthday['month'];
                $day = $birthday['day'];
                if (! checkdate($month, $day, 2000)) {
                    throw new PosException('invalid_birthday', 422);
                }
                $existing = app(BirthdayProfileService::class)->birthdayForProfile($profile);
                if ($existing && $existing->birth_month && $existing->birth_day
                    && ((int) $existing->birth_month !== $month || (int) $existing->birth_day !== $day)) {
                    throw new PosException('birthday_change_requires_review');
                }
                if (! $existing || ! $existing->birth_month || ! $existing->birth_day) {
                    app(BirthdayProfileService::class)->captureForProfile($profile, ['birth_month' => $month, 'birth_day' => $day], ['source' => 'everbranch_pay']);
                }
            }
            foreach (['email' => $email, 'sms' => $sms] as $channel => $accepted) {
                if (! $accepted) {
                    continue;
                }
                $details = ['wording' => $policy[$channel.'_wording'], 'version' => $policy['version'],
                    'register_id' => $register, 'actor_id' => $scope['actor_id'], 'destination' => $channel === 'email' ? $profile->email : $profile->phone];
                $changed = app(MarketingConsentService::class)->applyToProfile($profile, ['accepts_'.$channel.'_marketing' => true], [
                    'tenant_id' => $scope['tenant_id'], 'source_type' => 'everbranch_pay_customer_choice', 'source_id' => $key, 'details' => $details]);
                if (! $changed) {
                    MarketingConsentEvent::query()->create(['tenant_id' => $scope['tenant_id'], 'marketing_profile_id' => $profile->id,
                        'channel' => $channel, 'event_type' => 'confirmed', 'source_type' => 'everbranch_pay_customer_choice',
                        'source_id' => $key, 'details' => $details, 'occurred_at' => now()]);
                }
            }
            $result = ['customer_id' => $profileId, 'birthday_saved' => (bool) $birthday, 'email_opt_in_recorded' => $email, 'sms_opt_in_recorded' => $sms];
            $this->store->event($scope['tenant_id'], $key, 'customer_enrollment', ['input_hash' => $hash, 'result' => $result,
                'actor_id' => $scope['actor_id'], 'register_id' => $register]);

            return $result;
        }, 3);
    }

    private function policy(int $tenantId): array
    {
        $raw = TenantMarketingSetting::query()->forTenantId($tenantId)->where('key', self::KEY)->value('value');
        $stored = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);
        $stored = is_array($stored) ? $stored : [];
        $values = ['cashier_create_enabled' => ($stored['cashier_create_enabled'] ?? false) === true,
            'birthday_enabled' => ($stored['birthday_enabled'] ?? false) === true,
            'email_enabled' => ($stored['email_enabled'] ?? false) === true, 'email_wording' => (string) ($stored['email_wording'] ?? ''),
            'sms_enabled' => ($stored['sms_enabled'] ?? false) === true, 'sms_wording' => (string) ($stored['sms_wording'] ?? '')];
        $hash = Contract::hash($values);
        $reviewed = is_string($stored['reviewed_hash'] ?? null) && hash_equals($hash, $stored['reviewed_hash'])
            && (int) ($stored['reviewed_by'] ?? 0) > 0 && is_string($stored['reviewed_at'] ?? null);
        // POS enrollment requires an explicit tenant program; a global fallback must not activate another business.
        $birthdaySetting = TenantMarketingSetting::query()->forTenantId($tenantId)->where('key', 'birthday_club_config')->first();
        $birthdayProgramEnabled = ($birthdaySetting?->value['enabled'] ?? false) === true;
        $version = Contract::hash(['settings_hash' => $hash, 'reviewed' => $reviewed, 'birthday_program_enabled' => $birthdayProgramEnabled]);
        $birthdayConfigured = $reviewed && $values['birthday_enabled'];
        $values['cashier_create_enabled'] = $reviewed && $values['cashier_create_enabled'];
        $values['birthday_enabled'] = $birthdayConfigured && $birthdayProgramEnabled;
        $values['email_enabled'] = $reviewed && $values['email_enabled'];
        $values['sms_enabled'] = $reviewed && $values['sms_enabled'];

        return [...$values, 'birthday_configured' => $birthdayConfigured, 'version' => $version];
    }
}
