<?php

use App\Models\Tenant;
use App\Models\TenantMessagingAccount;
use App\Services\Marketing\Messaging\TenantMessagingProvisioningService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Evergrove Software LLC', 'slug' => 'evergrove-software']);
    $this->parent = 'AC'.str_repeat('1', 32);
    $this->child = 'AC'.str_repeat('2', 32);
    config()->set('features.tenant_messaging_provisioning', false);
    config()->set('features.tenant_sms_provisioning_tenant_ids', [$this->tenant->id]);
    config()->set('services.twilio.account_sid', $this->parent);
    config()->set('services.twilio.auth_token', 'synthetic-parent-secret');
});

test('provisioning creates a verified child through the list endpoint and reuses its encrypted mapping', function () {
    Http::fake(['https://api.twilio.com/2010-04-01/Accounts.json' => Http::response(['sid' => $this->child, 'owner_account_sid' => $this->parent, 'auth_token' => 'synthetic-child-secret'], 201)]);
    $service = app(TenantMessagingProvisioningService::class);
    $account = $service->provisionSms($this->tenant);
    expect($account->provider_account_id)->toBe($this->child)->and($account->status)->toBe('awaiting_business_profile');
    expect($service->provisionSms($this->tenant)->id)->toBe($account->id);
    expect(DB::table('tenant_messaging_accounts')->where('id', $account->id)->value('credentials'))->not->toContain('synthetic-child-secret');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://api.twilio.com/2010-04-01/Accounts.json');
    Http::assertSentCount(1);
});

test('parent or foreign account responses stay blocked and cannot be retried automatically', function () {
    foreach ([$this->parent, 'AC'.str_repeat('3', 32)] as $index => $sid) {
        $tenant = Tenant::create(['name' => 'Example '.$index, 'slug' => 'example-'.$index]);
        config()->set('features.tenant_sms_provisioning_tenant_ids', [$tenant->id]);
        Http::fake(['*' => Http::response(['sid' => $sid, 'owner_account_sid' => 'AC'.str_repeat('9', 32), 'auth_token' => 'synthetic'], 200)]);
        expect(fn () => app(TenantMessagingProvisioningService::class)->provisionSms($tenant))->toThrow(RuntimeException::class, 'needs reconciliation');
        expect(TenantMessagingAccount::forAllTenants()->where('tenant_id', $tenant->id)->first()->provider_account_id)->toBeNull();
        expect(fn () => app(TenantMessagingProvisioningService::class)->provisionSms($tenant))->toThrow(RuntimeException::class, 'previous Twilio provisioning');
        Http::assertSentCount(1);
    }
});

test('a historical parent mapping cannot be treated as a tenant subaccount', function () {
    TenantMessagingAccount::create(['tenant_id' => $this->tenant->id, 'channel' => 'sms', 'provider' => 'twilio_subaccount', 'status' => 'awaiting_business_profile', 'provider_account_id' => $this->parent]);
    expect(fn () => app(TenantMessagingProvisioningService::class)->provisionSms($this->tenant))->toThrow(RuntimeException::class, 'references the Twilio parent');
    Http::assertNothingSent();
});

test('provisioning remains disabled outside the explicit tenant rollout', function () {
    config()->set('features.tenant_sms_provisioning_tenant_ids', []);
    expect(fn () => app(TenantMessagingProvisioningService::class)->provisionSms($this->tenant))->toThrow(RuntimeException::class, 'provisioning is disabled');
    Http::assertNothingSent();
});
