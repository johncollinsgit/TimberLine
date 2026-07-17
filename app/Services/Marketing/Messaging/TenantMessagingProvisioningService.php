<?php

namespace App\Services\Marketing\Messaging;

use App\Models\Tenant;
use App\Models\TenantMessagingAccount;
use App\Models\TenantMessagingSenderProfile;
use Aws\Credentials\Credentials;
use Aws\SesV2\SesV2Client;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class TenantMessagingProvisioningService
{
    /** @return array{email:TenantMessagingAccount,sms:?TenantMessagingAccount} */
    public function bootstrap(Tenant $tenant, ?string $replyToEmail = null, bool $includeSms = false): array
    {
        return [
            'email' => $this->provisionManagedEmail($tenant, $replyToEmail),
            'sms' => $includeSms ? $this->provisionSms($tenant) : null,
        ];
    }

    public function provisionManagedEmail(Tenant $tenant, ?string $replyToEmail = null): TenantMessagingAccount
    {
        $this->assertProvisioningEnabled();
        $apiKey = trim((string) config('services.sendgrid.api_key'));
        $domain = strtolower(trim((string) config('services.sendgrid.managed_email_domain')));
        $domainAuthenticationId = trim((string) config('services.sendgrid.managed_domain_authentication_id'));
        $replyToEmail = strtolower(trim((string) ($replyToEmail ?: config('services.sendgrid.managed_reply_to'))));

        if ($apiKey === '') {
            throw new RuntimeException('SendGrid parent API key is not configured.');
        }
        if ($domainAuthenticationId === '' || ! filter_var('postmaster@'.$domain, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The verified Everbranch managed email domain is not configured.');
        }
        if ($replyToEmail === '' || ! filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid customer reply-to email address is required.');
        }

        $username = 'everbranch_tenant_'.$tenant->id;
        $existing = TenantMessagingAccount::query()->forAllTenants()
            ->where('tenant_id', $tenant->id)->where('channel', 'email')->first();
        if ($existing && ($existing->provider !== 'sendgrid_subuser' || $existing->mode !== 'platform_managed')) {
            throw new RuntimeException('This tenant already has a different email provider configuration.');
        }

        $account = $existing ?: TenantMessagingAccount::query()->forAllTenants()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'email',
            'provider' => 'sendgrid_subuser',
            'mode' => 'platform_managed',
            'status' => 'provisioning',
            'authenticated_domain' => $domain,
            'provider_config' => ['identity_mode' => 'managed_platform'],
            'diagnostics' => ['provisioning_started_at' => now()->toIso8601String()],
        ]);

        if ($account->isReady()) {
            $this->upsertManagedSenderProfile($tenant, $account, $replyToEmail);

            return $account->fresh();
        }

        if (blank($account->provider_account_id)) {
            $payload = array_filter([
                'username' => $username,
                'email' => 'postmaster@'.$domain,
                'password' => Str::password(32, symbols: true),
                'ips' => array_values(array_filter(array_map('trim', explode(',', (string) config('services.sendgrid.subuser_ips'))))),
                'region' => config('services.sendgrid.subuser_region'),
            ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
            $created = $this->sendGridRequest($apiKey)->post('https://api.sendgrid.com/v3/subusers', $payload);
            if ($created->failed() && $created->status() !== 409) {
                $this->recordProvisioningFailure($account, 'sendgrid_subuser', $created->json());
                throw new RuntimeException('SendGrid subuser provisioning failed: '.$this->providerError($created->json()));
            }
            $account->update(['provider_account_id' => $username]);
        }

        if (blank($account->provider_resource_id)) {
            $associated = $this->sendGridRequest($apiKey)
                ->post("https://api.sendgrid.com/v3/whitelabel/domains/{$domainAuthenticationId}/subuser", ['username' => $username]);
            if ($associated->failed() && $associated->status() !== 409) {
                $this->recordProvisioningFailure($account, 'sendgrid_domain_association', $associated->json());
                throw new RuntimeException('SendGrid managed-domain association failed: '.$this->providerError($associated->json()));
            }
            $account->update(['provider_resource_id' => $domainAuthenticationId]);
        }

        if (blank(data_get($account->fresh()->credentials, 'api_key'))) {
            $keyResponse = $this->sendGridRequest($apiKey)
                ->withHeaders(['On-Behalf-Of' => $username])
                ->post('https://api.sendgrid.com/v3/api_keys', [
                    'name' => 'Everbranch tenant '.$tenant->id,
                    'scopes' => ['mail.send'],
                ]);
            if ($keyResponse->failed() || blank(data_get($keyResponse->json(), 'api_key'))) {
                $this->recordProvisioningFailure($account, 'sendgrid_api_key', $keyResponse->json());
                throw new RuntimeException('SendGrid tenant API key provisioning failed: '.$this->providerError($keyResponse->json()));
            }
            $account->update(['credentials' => ['api_key' => data_get($keyResponse->json(), 'api_key')]]);
        }

        $account->update([
            'status' => TenantMessagingAccount::STATUS_READY,
            'authenticated_domain' => $domain,
            'provider_config' => [
                'username' => $username,
                'domain_authentication_id' => $domainAuthenticationId,
                'identity_mode' => 'managed_platform',
            ],
            'dns_records' => [],
            'verified_at' => now(),
            'last_error_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'diagnostics' => [
                ...(array) $account->diagnostics,
                'provisioned_at' => now()->toIso8601String(),
                'managed_domain_verified' => true,
            ],
        ]);

        $this->upsertManagedSenderProfile($tenant, $account->fresh(), $replyToEmail);

        return $account->fresh();
    }

    public function provisionEmail(Tenant $tenant, string $domain, ?string $provider = null): TenantMessagingAccount
    {
        $this->assertProvisioningEnabled();
        $provider = strtolower(trim((string) ($provider ?: config('marketing.messaging.platform.default_email_provider'))));
        $domain = strtolower(trim($domain));
        if (! filter_var('postmaster@'.$domain, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid tenant sending domain is required.');
        }

        return match ($provider) {
            'ses_tenant' => $this->provisionSesTenant($tenant, $domain),
            'sendgrid_subuser' => $this->provisionSendGridSubuser($tenant, $domain),
            default => throw new RuntimeException('Unsupported tenant email provider.'),
        };
    }

    public function provisionSms(Tenant $tenant): TenantMessagingAccount
    {
        $this->assertProvisioningEnabled();
        $parentSid = trim((string) config('services.twilio.account_sid'));
        $parentToken = trim((string) config('services.twilio.auth_token'));
        if ($parentSid === '' || $parentToken === '') {
            throw new RuntimeException('Twilio parent credentials are not configured.');
        }

        $existing = TenantMessagingAccount::query()->forAllTenants()
            ->where('tenant_id', $tenant->id)->where('channel', 'sms')->first();
        if ($existing?->provider_account_id) {
            return $existing;
        }

        $response = Http::asForm()->acceptJson()->withBasicAuth($parentSid, $parentToken)
            ->timeout(30)->post("https://api.twilio.com/2010-04-01/Accounts/{$parentSid}.json", [
                'FriendlyName' => 'Everbranch - '.$tenant->name.' - '.$tenant->id,
            ]);
        if ($response->failed()) {
            throw new RuntimeException('Twilio subaccount provisioning failed: '.$this->providerError($response->json()));
        }

        $payload = (array) $response->json();

        return TenantMessagingAccount::query()->forAllTenants()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'channel' => 'sms'],
            [
                'provider' => 'twilio_subaccount',
                'mode' => 'platform_managed',
                'status' => 'awaiting_business_profile',
                'provider_account_id' => $payload['sid'] ?? null,
                'credentials' => ['auth_token' => $payload['auth_token'] ?? null],
                'registration' => [
                    'sender_type' => (string) config('marketing.messaging.platform.automatic_sms_sender_type', 'toll_free'),
                    'business_profile' => 'required',
                    'messaging_service' => 'pending',
                    'phone_number' => 'pending',
                    'carrier_verification' => 'not_submitted',
                ],
                'diagnostics' => ['provisioned_at' => now()->toIso8601String()],
            ],
        );
    }

    /** @param array<string,mixed> $profile */
    public function stageSmsComplianceProfile(Tenant $tenant, array $profile): TenantMessagingAccount
    {
        $this->assertProvisioningEnabled();
        $existing = TenantMessagingAccount::query()->forAllTenants()
            ->where('tenant_id', $tenant->id)->where('channel', 'sms')->first();
        if ($existing && $existing->provider !== 'twilio_subaccount') {
            throw new RuntimeException('This tenant already has a different SMS provider configuration.');
        }
        if (! $existing) {
            TenantMessagingAccount::query()->forAllTenants()->create([
                'tenant_id' => $tenant->id,
                'channel' => 'sms',
                'provider' => 'twilio_subaccount',
                'mode' => 'platform_managed',
                'status' => 'awaiting_provider_setup',
                'registration' => [
                    'sender_type' => (string) config('marketing.messaging.platform.automatic_sms_sender_type', 'toll_free'),
                    'business_profile' => 'required',
                    'messaging_service' => 'pending',
                    'phone_number' => 'pending',
                    'carrier_verification' => 'not_submitted',
                ],
            ]);
        }

        return $this->saveSmsComplianceProfile((int) $tenant->id, $profile);
    }

    /** @param array<string,mixed> $profile */
    public function saveSmsComplianceProfile(int $tenantId, array $profile): TenantMessagingAccount
    {
        $this->assertProvisioningEnabled();
        foreach ([
            'business_name', 'business_website', 'notification_email', 'use_case_categories',
            'use_case_summary', 'production_message_sample', 'opt_in_image_urls', 'opt_in_type',
            'message_volume', 'privacy_policy_url', 'terms_and_conditions_url',
        ] as $key) {
            if (blank($profile[$key] ?? null)) {
                throw new RuntimeException("Text-message registration is missing {$key}.");
            }
        }

        return DB::transaction(function () use ($tenantId, $profile): TenantMessagingAccount {
            $account = TenantMessagingAccount::query()->forAllTenants()
                ->where('tenant_id', $tenantId)->where('channel', 'sms')->lockForUpdate()->firstOrFail();
            $registration = (array) $account->registration;
            $account->update([
                'compliance_profile' => $profile,
                'status' => 'awaiting_carrier_submission',
                'registration' => [...$registration, 'business_profile' => 'complete'],
                'last_error_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);

            return $account->fresh();
        });
    }

    public function submitTollFreeVerification(int $tenantId): TenantMessagingAccount
    {
        $this->assertProvisioningEnabled();
        $account = TenantMessagingAccount::query()->forAllTenants()
            ->where('tenant_id', $tenantId)->where('channel', 'sms')->firstOrFail();
        if ($account->isReady() || filled(data_get($account->registration, 'toll_free_verification_sid'))) {
            return $account;
        }

        $subaccountSid = trim((string) $account->provider_account_id);
        $subaccountToken = trim((string) data_get($account->credentials, 'auth_token'));
        $profile = (array) $account->compliance_profile;
        if ($subaccountSid === '' || $subaccountToken === '' || $profile === []) {
            throw new RuntimeException('The Twilio subaccount and customer business profile must exist before carrier submission.');
        }

        $registration = (array) $account->registration;
        try {
            if (blank($registration['messaging_service_sid'] ?? null)) {
                $response = $this->twilioRequest($subaccountSid, $subaccountToken)
                    ->post('https://messaging.twilio.com/v1/Services', array_filter([
                        'FriendlyName' => 'Everbranch tenant '.$tenantId,
                        'InboundRequestUrl' => trim((string) config('services.twilio.inbound_callback_url')),
                        'StatusCallback' => trim((string) config('services.twilio.status_callback_url')),
                    ]));
                $this->assertTwilioSuccess($response, 'Twilio Messaging Service setup failed');
                $registration['messaging_service_sid'] = (string) data_get($response->json(), 'sid');
                $registration['messaging_service'] = 'complete';
                $account->update(['registration' => $registration]);
            }

            if (blank($registration['phone_number_sid'] ?? null)) {
                $available = $this->twilioRequest($subaccountSid, $subaccountToken)
                    ->get("https://api.twilio.com/2010-04-01/Accounts/{$subaccountSid}/AvailablePhoneNumbers/US/TollFree.json", ['PageSize' => 1]);
                $this->assertTwilioSuccess($available, 'No toll-free number could be reserved');
                $phoneNumber = trim((string) data_get($available->json(), 'available_phone_numbers.0.phone_number'));
                if ($phoneNumber === '') {
                    throw new RuntimeException('Twilio did not return an available toll-free number.');
                }
                $purchased = $this->twilioRequest($subaccountSid, $subaccountToken)
                    ->post("https://api.twilio.com/2010-04-01/Accounts/{$subaccountSid}/IncomingPhoneNumbers.json", [
                        'PhoneNumber' => $phoneNumber,
                    ]);
                $this->assertTwilioSuccess($purchased, 'Toll-free number purchase failed');
                $registration['phone_number_sid'] = (string) data_get($purchased->json(), 'sid');
                $registration['phone_number'] = 'complete';
                $account->update([
                    'sender_identifier' => (string) data_get($purchased->json(), 'phone_number', $phoneNumber),
                    'registration' => $registration,
                ]);
            }

            if (! (bool) ($registration['phone_attached'] ?? false)) {
                $attached = $this->twilioRequest($subaccountSid, $subaccountToken)
                    ->post('https://messaging.twilio.com/v1/Services/'.$registration['messaging_service_sid'].'/PhoneNumbers', [
                        'PhoneNumberSid' => $registration['phone_number_sid'],
                    ]);
                $this->assertTwilioSuccess($attached, 'Toll-free number attachment failed');
                $registration['phone_attached'] = true;
                $account->update([
                    'provider_resource_id' => $registration['messaging_service_sid'],
                    'registration' => $registration,
                ]);
            }

            $submitted = $this->twilioRequest($subaccountSid, $subaccountToken)
                ->post('https://messaging.twilio.com/v1/Tollfree/Verifications', $this->tollFreeVerificationPayload(
                    $profile,
                    (string) $registration['phone_number_sid'],
                    $tenantId,
                ));
            $this->assertTwilioSuccess($submitted, 'Toll-free carrier verification submission failed');
            $registration['toll_free_verification_sid'] = (string) data_get($submitted->json(), 'sid');
            $registration['carrier_verification'] = strtolower((string) data_get($submitted->json(), 'status', 'pending_review'));
            $account->update([
                'status' => 'pending_verification',
                'registration' => $registration,
                'last_error_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'diagnostics' => [
                    ...(array) $account->diagnostics,
                    'carrier_submitted_at' => now()->toIso8601String(),
                ],
            ]);

            return $account->fresh();
        } catch (\Throwable $exception) {
            $account->update([
                'status' => 'carrier_submission_failed',
                'last_error_at' => now(),
                'last_error_code' => 'twilio_toll_free_submission',
                'last_error_message' => Str::limit($exception->getMessage(), 500),
            ]);
            throw $exception;
        }
    }

    public function refreshSmsVerification(int $tenantId): TenantMessagingAccount
    {
        $this->assertProvisioningEnabled();
        $account = TenantMessagingAccount::query()->forAllTenants()
            ->where('tenant_id', $tenantId)->where('channel', 'sms')->firstOrFail();
        $verificationSid = trim((string) data_get($account->registration, 'toll_free_verification_sid'));
        $subaccountSid = trim((string) $account->provider_account_id);
        $subaccountToken = trim((string) data_get($account->credentials, 'auth_token'));
        if ($verificationSid === '' || $subaccountSid === '' || $subaccountToken === '') {
            throw new RuntimeException('Text-message carrier verification has not been submitted.');
        }

        $response = $this->twilioRequest($subaccountSid, $subaccountToken)
            ->get("https://messaging.twilio.com/v1/Tollfree/Verifications/{$verificationSid}");
        $this->assertTwilioSuccess($response, 'Toll-free carrier verification refresh failed');
        $providerStatus = strtoupper(trim((string) data_get($response->json(), 'status', 'PENDING_REVIEW')));
        $approved = in_array($providerStatus, ['TWILIO_APPROVED', 'APPROVED', 'VERIFIED'], true);
        $rejected = in_array($providerStatus, ['TWILIO_REJECTED', 'REJECTED'], true);
        $registration = [...(array) $account->registration, 'carrier_verification' => strtolower($providerStatus)];
        $account->update([
            'status' => $approved ? TenantMessagingAccount::STATUS_READY : ($rejected ? 'needs_changes' : 'pending_verification'),
            'registration' => $registration,
            'verified_at' => $approved ? now() : null,
            'last_error_at' => $rejected ? now() : null,
            'last_error_code' => $rejected ? 'carrier_rejected' : null,
            'last_error_message' => $rejected ? Str::limit((string) data_get($response->json(), 'rejection_reason', 'Carrier verification needs changes.'), 500) : null,
            'diagnostics' => [
                ...(array) $account->diagnostics,
                'carrier_status_checked_at' => now()->toIso8601String(),
            ],
        ]);

        return $account->fresh();
    }

    /** @param array<string,mixed> $resources */
    public function completeTwilioRegistration(int $tenantId, array $resources): TenantMessagingAccount
    {
        $this->assertProvisioningEnabled();
        foreach (['secondary_customer_profile_sid', 'brand_sid', 'campaign_sid', 'messaging_service_sid', 'phone_number'] as $key) {
            if (trim((string) ($resources[$key] ?? '')) === '') {
                throw new RuntimeException("Twilio registration is missing {$key}.");
            }
        }

        return DB::transaction(function () use ($tenantId, $resources): TenantMessagingAccount {
            $account = TenantMessagingAccount::query()->forAllTenants()
                ->where('tenant_id', $tenantId)->where('channel', 'sms')->lockForUpdate()->firstOrFail();
            $account->update([
                'provider_resource_id' => $resources['messaging_service_sid'],
                'sender_identifier' => $resources['phone_number'],
                'registration' => $resources,
                'status' => 'ready',
                'verified_at' => now(),
                'last_error_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);

            return $account->fresh();
        });
    }

    public function refreshEmailVerification(int $tenantId): TenantMessagingAccount
    {
        $this->assertProvisioningEnabled();
        $account = TenantMessagingAccount::query()->forAllTenants()
            ->where('tenant_id', $tenantId)->where('channel', 'email')->firstOrFail();

        $verified = match ($account->provider) {
            'sendgrid_subuser' => $this->refreshSendGridDomainVerification($account),
            'ses_tenant' => $this->refreshSesDomainVerification($account),
            default => throw new RuntimeException('This email provider does not support managed domain verification.'),
        };

        return DB::transaction(function () use ($account, $verified): TenantMessagingAccount {
            $account->update([
                'status' => $verified ? TenantMessagingAccount::STATUS_READY : 'pending_verification',
                'verified_at' => $verified ? now() : null,
                'diagnostics' => [
                    ...(array) $account->diagnostics,
                    'verification_checked_at' => now()->toIso8601String(),
                    'domain_verified' => $verified,
                ],
            ]);

            if ($verified) {
                TenantMessagingSenderProfile::query()->forAllTenants()
                    ->where('tenant_id', $account->tenant_id)
                    ->where('tenant_messaging_account_id', $account->id)
                    ->where('verification_status', 'pending')
                    ->update(['verification_status' => 'verified', 'verified_at' => now()]);
            }

            return $account->fresh();
        });
    }

    protected function provisionSesTenant(Tenant $tenant, string $domain): TenantMessagingAccount
    {
        $client = $this->sesClient();
        $tenantName = 'everbranch-tenant-'.$tenant->id;
        try {
            $client->createTenant([
                'TenantName' => $tenantName,
                'Tags' => [['Key' => 'everbranch_tenant_id', 'Value' => (string) $tenant->id]],
            ]);
        } catch (\Throwable $exception) {
            if (! str_contains(strtolower($exception->getMessage()), 'already')) {
                throw $exception;
            }
        }

        try {
            $identity = $client->createEmailIdentity([
                'EmailIdentity' => $domain,
                'Tags' => [['Key' => 'everbranch_tenant_id', 'Value' => (string) $tenant->id]],
            ])->toArray();
        } catch (\Throwable $exception) {
            if (! str_contains(strtolower($exception->getMessage()), 'already')) {
                throw $exception;
            }
            $identity = $client->getEmailIdentity(['EmailIdentity' => $domain])->toArray();
        }

        $accountId = trim((string) config('services.ses.account_id'));
        if ($accountId === '') {
            throw new RuntimeException('AWS_ACCOUNT_ID is required to associate a sending domain with an SES tenant.');
        }
        try {
            $client->createTenantResourceAssociation([
                'TenantName' => $tenantName,
                'ResourceArn' => sprintf('arn:aws:ses:%s:%s:identity/%s', config('services.ses.region'), $accountId, $domain),
            ]);
        } catch (\Throwable $exception) {
            if (! str_contains(strtolower($exception->getMessage()), 'already')) {
                throw $exception;
            }
        }

        $tokens = (array) data_get($identity, 'DkimAttributes.Tokens', []);
        $dns = collect($tokens)->map(fn (string $token): array => [
            'type' => 'CNAME',
            'host' => $token.'._domainkey.'.$domain,
            'value' => $token.'.dkim.amazonses.com',
            'purpose' => 'DKIM',
        ])->values()->all();

        return TenantMessagingAccount::query()->forAllTenants()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'channel' => 'email'],
            [
                'provider' => 'ses_tenant',
                'mode' => 'platform_managed',
                'status' => 'pending_verification',
                'provider_account_id' => $tenantName,
                'authenticated_domain' => $domain,
                'provider_config' => ['tenant_name' => $tenantName, 'region' => config('services.ses.region')],
                'dns_records' => $dns,
                'diagnostics' => ['provisioned_at' => now()->toIso8601String()],
            ],
        );
    }

    protected function provisionSendGridSubuser(Tenant $tenant, string $domain): TenantMessagingAccount
    {
        $apiKey = trim((string) config('services.sendgrid.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('SendGrid parent API key is not configured.');
        }

        $username = 'everbranch_tenant_'.$tenant->id;
        $password = Str::password(32, symbols: true);
        $ips = array_values(array_filter(array_map('trim', explode(',', (string) config('services.sendgrid.subuser_ips')))));
        $payload = array_filter([
            'username' => $username,
            'email' => 'postmaster@'.$domain,
            'password' => $password,
            'ips' => $ips,
            'region' => config('services.sendgrid.subuser_region'),
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

        $created = $this->sendGridRequest($apiKey)->post('https://api.sendgrid.com/v3/subusers', $payload);
        if ($created->failed() && $created->status() !== 409) {
            throw new RuntimeException('SendGrid subuser provisioning failed: '.$this->providerError($created->json()));
        }

        $domainResponse = $this->sendGridRequest($apiKey)->post('https://api.sendgrid.com/v3/whitelabel/domains', [
            'domain' => $domain,
            'subdomain' => 'email',
            'username' => $username,
            'default' => true,
            'automatic_security' => true,
            'region' => config('services.sendgrid.subuser_region', 'global'),
        ]);
        if ($domainResponse->failed()) {
            throw new RuntimeException('SendGrid domain authentication setup failed: '.$this->providerError($domainResponse->json()));
        }
        $domainAuthentication = (array) $domainResponse->json();

        $keyResponse = $this->sendGridRequest($apiKey)
            ->withHeaders(['On-Behalf-Of' => $username])
            ->post('https://api.sendgrid.com/v3/api_keys', [
                'name' => 'Everbranch tenant '.$tenant->id,
                'scopes' => ['mail.send'],
            ]);
        if ($keyResponse->failed()) {
            throw new RuntimeException('SendGrid tenant API key provisioning failed: '.$this->providerError($keyResponse->json()));
        }

        return TenantMessagingAccount::query()->forAllTenants()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'channel' => 'email'],
            [
                'provider' => 'sendgrid_subuser',
                'mode' => 'platform_managed',
                'status' => 'pending_verification',
                'provider_account_id' => $username,
                'provider_resource_id' => (string) ($domainAuthentication['id'] ?? ''),
                'authenticated_domain' => $domain,
                'credentials' => ['api_key' => data_get($keyResponse->json(), 'api_key')],
                'provider_config' => ['username' => $username, 'domain_authentication_id' => $domainAuthentication['id'] ?? null],
                'dns_records' => $this->sendGridDnsRecords((array) ($domainAuthentication['dns'] ?? [])),
                'diagnostics' => ['provisioned_at' => now()->toIso8601String()],
            ],
        );
    }

    protected function refreshSendGridDomainVerification(TenantMessagingAccount $account): bool
    {
        $apiKey = trim((string) config('services.sendgrid.api_key'));
        $domainId = trim((string) $account->provider_resource_id);
        if ($apiKey === '' || $domainId === '') {
            throw new RuntimeException('SendGrid domain verification is missing its parent API key or domain ID.');
        }

        $response = $this->sendGridRequest($apiKey)
            ->post("https://api.sendgrid.com/v3/whitelabel/domains/{$domainId}/validate");
        if ($response->failed()) {
            throw new RuntimeException('SendGrid domain verification failed: '.$this->providerError($response->json()));
        }

        return (bool) data_get($response->json(), 'valid', false);
    }

    protected function refreshSesDomainVerification(TenantMessagingAccount $account): bool
    {
        $identity = $this->sesClient()->getEmailIdentity([
            'EmailIdentity' => (string) $account->authenticated_domain,
        ])->toArray();

        return (bool) ($identity['VerifiedForSendingStatus'] ?? false)
            && strtoupper((string) data_get($identity, 'DkimAttributes.Status')) === 'SUCCESS';
    }

    protected function upsertManagedSenderProfile(
        Tenant $tenant,
        TenantMessagingAccount $account,
        string $replyToEmail,
    ): TenantMessagingSenderProfile {
        $domain = strtolower(trim((string) $account->authenticated_domain));
        $localPart = Str::slug((string) ($tenant->slug ?: $tenant->name));
        $localPart = $localPart !== '' ? Str::limit($localPart, 48, '') : 'tenant-'.$tenant->id;
        $fromEmail = $localPart.'@'.$domain;

        return DB::transaction(function () use ($tenant, $account, $replyToEmail, $domain, $fromEmail): TenantMessagingSenderProfile {
            TenantMessagingSenderProfile::query()->forAllTenants()
                ->where('tenant_id', $tenant->id)->where('channel', 'email')->update(['is_default' => false]);

            return TenantMessagingSenderProfile::query()->forAllTenants()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'store_key' => null, 'from_email' => $fromEmail],
                [
                    'tenant_messaging_account_id' => $account->id,
                    'channel' => 'email',
                    'label' => 'Company email',
                    'display_name' => $tenant->name,
                    'reply_to_email' => $replyToEmail,
                    'authenticated_domain' => $domain,
                    'reply_mode' => 'direct_inbox',
                    'verification_status' => 'verified',
                    'verified_at' => now(),
                    'is_default' => true,
                    'metadata' => ['managed_by' => 'everbranch', 'identity_mode' => 'managed_platform'],
                ],
            );
        });
    }

    protected function recordProvisioningFailure(TenantMessagingAccount $account, string $code, mixed $payload): void
    {
        $account->update([
            'status' => 'provisioning_failed',
            'last_error_at' => now(),
            'last_error_code' => $code,
            'last_error_message' => $this->providerError($payload),
        ]);
    }

    /** @param array<string,mixed> $records */
    protected function sendGridDnsRecords(array $records): array
    {
        return collect($records)->map(function (mixed $record, string $purpose): array {
            $record = is_array($record) ? $record : [];

            return [
                'type' => strtoupper((string) ($record['type'] ?? 'CNAME')),
                'host' => (string) ($record['host'] ?? ''),
                'value' => (string) ($record['data'] ?? ''),
                'purpose' => strtoupper($purpose),
            ];
        })->filter(fn (array $record): bool => $record['host'] !== '' && $record['value'] !== '')->values()->all();
    }

    protected function sesClient(): SesV2Client
    {
        $key = trim((string) config('services.ses.key'));
        $secret = trim((string) config('services.ses.secret'));
        if ($key === '' || $secret === '') {
            throw new RuntimeException('AWS SES credentials are not configured.');
        }

        return new SesV2Client([
            'version' => 'latest',
            'region' => (string) config('services.ses.region'),
            'credentials' => new Credentials($key, $secret),
        ]);
    }

    protected function sendGridRequest(string $apiKey): PendingRequest
    {
        return Http::acceptJson()->asJson()->withToken($apiKey)->timeout(30);
    }

    protected function twilioRequest(string $accountSid, string $authToken): PendingRequest
    {
        return Http::asForm()->acceptJson()->withBasicAuth($accountSid, $authToken)->timeout(30);
    }

    protected function assertTwilioSuccess($response, string $message): void
    {
        if ($response->failed()) {
            throw new RuntimeException($message.': '.$this->providerError($response->json()));
        }
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    protected function tollFreeVerificationPayload(array $profile, string $phoneNumberSid, int $tenantId): array
    {
        $payload = [
            'BusinessName' => $profile['business_name'] ?? null,
            'BusinessWebsite' => $profile['business_website'] ?? null,
            'NotificationEmail' => $profile['notification_email'] ?? null,
            'UseCaseCategories' => array_values((array) ($profile['use_case_categories'] ?? [])),
            'UseCaseSummary' => $profile['use_case_summary'] ?? null,
            'ProductionMessageSample' => $profile['production_message_sample'] ?? null,
            'OptInImageUrls' => array_values((array) ($profile['opt_in_image_urls'] ?? [])),
            'OptInType' => $profile['opt_in_type'] ?? null,
            'MessageVolume' => $profile['message_volume'] ?? null,
            'TollfreePhoneNumberSid' => $phoneNumberSid,
            'PrivacyPolicyUrl' => $profile['privacy_policy_url'] ?? null,
            'TermsAndConditionsUrl' => $profile['terms_and_conditions_url'] ?? null,
            'BusinessType' => $profile['business_type'] ?? null,
            'BusinessRegistrationNumber' => $profile['registration_id'] ?? null,
            'BusinessRegistrationAuthority' => $profile['registration_authority'] ?? null,
            'BusinessRegistrationCountry' => $profile['registration_country'] ?? null,
            'BusinessContactFirstName' => $profile['contact_first_name'] ?? null,
            'BusinessContactLastName' => $profile['contact_last_name'] ?? null,
            'BusinessContactEmail' => $profile['contact_email'] ?? null,
            'BusinessContactPhone' => $profile['contact_phone'] ?? null,
            'BusinessAddress' => $profile['business_address'] ?? null,
            'BusinessCity' => $profile['business_city'] ?? null,
            'BusinessStateProvinceRegion' => $profile['business_state'] ?? null,
            'BusinessPostalCode' => $profile['business_postal_code'] ?? null,
            'BusinessCountry' => $profile['business_country'] ?? null,
            'ExternalReferenceId' => 'everbranch-tenant-'.$tenantId,
            'CustomerProfileSid' => trim((string) config('services.twilio.trust_hub_primary_customer_profile_sid')),
        ];

        return array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    protected function assertProvisioningEnabled(): void
    {
        if (! (bool) config('features.tenant_messaging_provisioning')) {
            throw new RuntimeException('Tenant messaging provisioning is disabled.');
        }
    }

    protected function providerError(mixed $payload): string
    {
        return Str::limit((string) data_get($payload, 'message', data_get($payload, 'errors.0.message', 'Provider request failed.')), 500);
    }
}
