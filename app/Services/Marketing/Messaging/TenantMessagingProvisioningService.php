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
                    'secondary_customer_profile' => 'required',
                    'brand' => 'required',
                    'campaign' => 'required',
                    'messaging_service' => 'required',
                    'phone_number' => 'required',
                ],
                'diagnostics' => ['provisioned_at' => now()->toIso8601String()],
            ],
        );
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
