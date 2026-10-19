<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantMessagingAccount;
use App\Services\Marketing\Messaging\TenantMessagingAccountResolver;
use App\Services\Marketing\Messaging\TenantMessagingProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['features.tenant_messaging_provisioning' => true, 'features.tenant_messaging_platform' => true, 'services.twilio.account_sid' => 'AC'.str_repeat('1', 32), 'services.twilio.auth_token' => 'fictional-parent-token']);
    $this->tenant = Tenant::create(['name' => 'SMS fixture', 'slug' => 'sms-fixture']);
});

it('rejects a parent account response without storing its credentials as a tenant connection', function () {
    Http::fake(['https://api.twilio.com/2010-04-01/Accounts.json' => Http::response(['sid' => config('services.twilio.account_sid'), 'owner_account_sid' => config('services.twilio.account_sid'), 'auth_token' => 'fictional-parent-token'], 201)]);
    expect(fn () => app(TenantMessagingProvisioningService::class)->provisionSms($this->tenant))->toThrow(RuntimeException::class, 'isolated tenant account');
    expect(TenantMessagingAccount::query()->forAllTenants()->count())->toBe(0)
        ->and(IntegrationConnection::query()->forAllTenants()->sole()->last_error_code)->toBe('invalid_provider_identity');
});

it('requires the created child to belong to the configured platform', function () {
    Http::fake(['https://api.twilio.com/2010-04-01/Accounts.json' => Http::response(['sid' => 'AC'.str_repeat('2', 32), 'owner_account_sid' => 'AC'.str_repeat('3', 32), 'auth_token' => 'fictional-child-token'], 201)]);
    expect(fn () => app(TenantMessagingProvisioningService::class)->provisionSms($this->tenant))->toThrow(RuntimeException::class, 'isolated tenant account');
    expect(TenantMessagingAccount::query()->forAllTenants()->count())->toBe(0);
});

it('preserves creation intent after a timeout and prevents an automatic duplicate request', function () {
    $requests = 0;
    Http::fake(function () use (&$requests) {
        $requests++;
        throw new ConnectionException('Fictional unknown provider result.');
    });
    $service = app(TenantMessagingProvisioningService::class);
    expect(fn () => $service->provisionSms($this->tenant))->toThrow(ConnectionException::class);
    expect(IntegrationConnection::query()->forAllTenants()->sole()->status)->toBe('pending');
    expect(fn () => $service->provisionSms($this->tenant))->toThrow(RuntimeException::class, 'operator reconciliation');
    expect($requests)->toBe(1)->and(TenantMessagingAccount::query()->forAllTenants()->count())->toBe(0);
});

it('blocks a saved platform binding from reuse, sends, callbacks and registration completion', function () {
    Http::preventStrayRequests();
    $account = TenantMessagingAccount::create(['tenant_id' => $this->tenant->id, 'channel' => 'sms', 'provider' => 'twilio_subaccount', 'status' => 'ready', 'provider_account_id' => config('services.twilio.account_sid')]);
    $resolver = app(TenantMessagingAccountResolver::class);
    $service = app(TenantMessagingProvisioningService::class);
    expect(fn () => $service->provisionSms($this->tenant))->toThrow(RuntimeException::class, 'platform or shared account');
    expect(fn () => $resolver->resolve($this->tenant->id, 'sms'))->toThrow(RuntimeException::class, 'platform or shared account');
    expect(fn () => $resolver->resolveTwilioCallback($account->provider_account_id))->toThrow(RuntimeException::class, 'platform or shared account');
    expect(fn () => $service->completeTwilioRegistration($this->tenant->id, array_fill_keys(['secondary_customer_profile_sid', 'brand_sid', 'campaign_sid', 'messaging_service_sid', 'phone_number'], 'fictional')))->toThrow(RuntimeException::class, 'platform or shared account');
});

it('rejects ambiguous callback ownership when two tenant records share an account', function () {
    $other = Tenant::create(['name' => 'Other SMS fixture', 'slug' => 'other-sms-fixture']);
    foreach ([$this->tenant, $other] as $tenant) {
        TenantMessagingAccount::create(['tenant_id' => $tenant->id, 'channel' => 'sms', 'provider' => 'twilio_subaccount', 'status' => 'ready', 'provider_account_id' => 'AC'.str_repeat('2', 32)]);
    }
    expect(fn () => app(TenantMessagingAccountResolver::class)->resolveTwilioCallback('AC'.str_repeat('2', 32)))->toThrow(RuntimeException::class, 'platform or shared account');
});
