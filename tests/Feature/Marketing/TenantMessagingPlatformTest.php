<?php

use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantMessagingAccount;
use App\Models\TenantMessagingLedgerEntry;
use App\Models\TenantMessagingSenderProfile;
use App\Models\TenantMessagingUsagePeriod;
use App\Services\Billing\StripeWebhookIngestService;
use App\Services\Marketing\Messaging\TenantMessagingAccountResolver;
use App\Services\Marketing\Messaging\TenantMessagingGateway;
use App\Services\Marketing\Messaging\TenantMessagingProvisioningService;
use App\Services\Marketing\Messaging\TenantMessagingSenderProfileService;
use App\Services\Marketing\Messaging\TenantMessagingUsageService;
use App\Services\Marketing\MessagingEmailReplyAddressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('features.tenant_messaging_platform', true);
    config()->set('marketing.messaging.platform.legacy_tenant_ids', [1]);
    config()->set('marketing.messaging.responses.email_inbound_domain', 'replies.example.test');
});

test('new tenants fail closed while the explicit legacy tenant keeps its existing provider path', function () {
    $legacy = Tenant::query()->create(['name' => 'Legacy', 'slug' => 'legacy']);
    $newTenant = Tenant::query()->create(['name' => 'New company', 'slug' => 'new-company']);
    $resolver = app(TenantMessagingAccountResolver::class);

    expect($legacy->id)->toBe(1)
        ->and($resolver->resolve($legacy->id, 'email')['mode'])->toBe('legacy');

    $resolver->resolve($newTenant->id, 'email');
})->throws(RuntimeException::class, 'does not have a ready email messaging account');

test('provider accounts and callback lookup remain tenant isolated with encrypted credentials', function () {
    $first = Tenant::query()->create(['name' => 'First', 'slug' => 'first']);
    $second = Tenant::query()->create(['name' => 'Second', 'slug' => 'second']);
    $account = TenantMessagingAccount::query()->forAllTenants()->create([
        'tenant_id' => $second->id,
        'channel' => 'sms',
        'provider' => 'twilio_subaccount',
        'status' => 'ready',
        'provider_account_id' => 'AC'.str_repeat('1', 32),
        'provider_resource_id' => 'MG'.str_repeat('2', 32),
        'sender_identifier' => '+15555550100',
        'credentials' => ['auth_token' => 'second-tenant-secret'],
    ]);

    $resolved = app(TenantMessagingAccountResolver::class)->resolveTwilioCallback((string) $account->provider_account_id);
    $raw = DB::table('tenant_messaging_accounts')->where('id', $account->id)->value('credentials');

    expect($resolved->tenant_id)->toBe($second->id)
        ->and($resolved->tenant_id)->not->toBe($first->id)
        ->and((string) $raw)->not->toContain('second-tenant-secret');
});

test('sendgrid provisioning creates an isolated subuser and buyer ready domain verification records', function () {
    config()->set('features.tenant_messaging_provisioning', true);
    config()->set('services.sendgrid.api_key', 'parent-sendgrid-key');
    config()->set('services.sendgrid.subuser_region', 'global');
    $tenant = Tenant::query()->create(['name' => 'Domain Co', 'slug' => 'domain-co']);
    Http::fake([
        'https://api.sendgrid.com/v3/subusers' => Http::response(['username' => 'everbranch_tenant_'.$tenant->id], 201),
        'https://api.sendgrid.com/v3/whitelabel/domains' => Http::response([
            'id' => 90210,
            'valid' => false,
            'dns' => [
                'mail_cname' => ['type' => 'cname', 'host' => 'email.domain-co.test', 'data' => 'u1.wl.sendgrid.net'],
                'dkim1' => ['type' => 'cname', 'host' => 's1._domainkey.domain-co.test', 'data' => 's1.domainkey.u1.wl.sendgrid.net'],
            ],
        ], 201),
        'https://api.sendgrid.com/v3/api_keys' => Http::response(['api_key' => 'tenant-sendgrid-key'], 201),
        'https://api.sendgrid.com/v3/whitelabel/domains/90210/validate' => Http::response(['valid' => true], 200),
    ]);

    $service = app(TenantMessagingProvisioningService::class);
    $account = $service->provisionEmail($tenant, 'domain-co.test', 'sendgrid_subuser');
    TenantMessagingSenderProfile::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'tenant_messaging_account_id' => $account->id,
        'channel' => 'email',
        'label' => 'Support',
        'display_name' => 'Domain Co',
        'from_email' => 'support@domain-co.test',
        'authenticated_domain' => 'domain-co.test',
        'reply_mode' => 'everbranch_inbox',
        'verification_status' => 'pending',
        'is_default' => true,
    ]);
    $verified = $service->refreshEmailVerification($tenant->id);

    expect($account->provider_account_id)->toBe('everbranch_tenant_'.$tenant->id)
        ->and($account->provider_resource_id)->toBe('90210')
        ->and($account->dns_records)->toHaveCount(2)
        ->and($verified->isReady())->toBeTrue()
        ->and(TenantMessagingSenderProfile::query()->forAllTenants()->where('tenant_id', $tenant->id)->value('verification_status'))->toBe('verified');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.sendgrid.com/v3/whitelabel/domains'
        && $request['username'] === 'everbranch_tenant_'.$tenant->id
        && $request['domain'] === 'domain-co.test');
});

test('verified sender profiles enforce authenticated domains and both reply modes', function () {
    $tenant = Tenant::query()->create(['name' => 'Sender Co', 'slug' => 'sender-co']);
    $account = TenantMessagingAccount::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'channel' => 'email',
        'provider' => 'sendgrid_subuser',
        'status' => 'ready',
        'authenticated_domain' => 'sender.test',
    ]);
    $direct = TenantMessagingSenderProfile::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'tenant_messaging_account_id' => $account->id,
        'channel' => 'email',
        'label' => 'Support',
        'display_name' => 'Sender Support',
        'from_email' => 'support@sender.test',
        'reply_to_email' => 'team@sender.test',
        'authenticated_domain' => 'sender.test',
        'reply_mode' => 'direct_inbox',
        'verification_status' => 'verified',
        'is_default' => true,
    ]);
    $service = app(TenantMessagingSenderProfileService::class);

    expect($service->resolveEmailSender($account, $direct->id)['reply_to_email'])->toBe('team@sender.test');

    $direct->update(['reply_mode' => 'everbranch_inbox']);
    $inbox = $service->resolveEmailSender($account, $direct->id, deliveryId: 42);
    expect($inbox['reply_to_email'])->toMatch('/reply\+t'.$tenant->id.'d42s[a-f0-9]{20}@replies\.example\.test/');

    $direct->update(['from_email' => 'spoof@another.test']);
    $service->resolveEmailSender($account, $direct->id, deliveryId: 42);
})->throws(RuntimeException::class, 'authenticated domain');

test('reply aliases reject tampering and unsigned aliases outside the legacy allowlist', function () {
    $service = app(MessagingEmailReplyAddressService::class);
    $address = $service->replyAddressForDelivery(2, 99);

    expect($service->parseReplyAddress($address))->toBe(['tenant_id' => 2, 'delivery_id' => 99])
        ->and($service->parseReplyAddress(str_replace('d99s', 'd98s', (string) $address)))->toBeNull()
        ->and($service->parseReplyAddress('reply+t2d99@replies.example.test'))->toBeNull()
        ->and($service->parseReplyAddress('reply+t1d99@replies.example.test'))->toBe(['tenant_id' => 1, 'delivery_id' => 99]);
});

test('usage reservations use the largest package allowance and settle once without negative credit', function () {
    $tenant = Tenant::query()->create(['name' => 'Usage Co', 'slug' => 'usage-co']);
    foreach (['messaging', 'bulk_email_marketing'] as $addon) {
        TenantAccessAddon::query()->create(['tenant_id' => $tenant->id, 'addon_key' => $addon, 'enabled' => true]);
    }
    $usage = app(TenantMessagingUsageService::class);
    $reservation = $usage->reserve($tenant->id, 'email', 1, 'delivery-1', 'sendgrid_subuser');
    $first = $usage->settle($tenant->id, 'delivery-1');
    $second = $usage->settle($tenant->id, 'delivery-1');
    $period = TenantMessagingUsagePeriod::query()->forAllTenants()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($reservation['amount_micros'])->toBe(0)
        ->and($period->included_units)->toBe(50000)
        ->and($period->used_units)->toBe(1)
        ->and($period->reserved_units)->toBe(0)
        ->and($first->id)->toBe($second->id);
});

test('overage requires prepaid credit and failed reservations can be refunded exactly once', function () {
    $tenant = Tenant::query()->create(['name' => 'Credit Co', 'slug' => 'credit-co']);
    $usage = app(TenantMessagingUsageService::class);
    $usage->fund($tenant->id, 25000000, 'stripe-event-1');
    $usage->fund($tenant->id, 25000000, 'stripe-event-1');
    $reservation = $usage->reserve($tenant->id, 'sms', 2, 'sms-1', 'twilio_subaccount');
    $refund = $usage->refund($tenant->id, 'sms-1', 'provider_failed');
    $sameRefund = $usage->refund($tenant->id, 'sms-1', 'provider_failed');
    $summary = $usage->summary($tenant->id, 'sms');

    expect($reservation['amount_micros'])->toBe(50000)
        ->and($refund->id)->toBe($sameRefund->id)
        ->and($summary['credit_balance_micros'])->toBe(25000000)
        ->and($summary['credit_available_micros'])->toBe(25000000)
        ->and(DB::table('tenant_messaging_ledger_entries')->where('entry_type', 'credit_funding')->count())->toBe(1);
});

test('messaging ledger entries reject edits and deletes', function () {
    $tenant = Tenant::query()->create(['name' => 'Ledger Co', 'slug' => 'ledger-co']);
    $entry = app(TenantMessagingUsageService::class)->fund($tenant->id, 25000000, 'immutable-test');

    expect(fn () => $entry->update(['amount_micros' => 1]))
        ->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $entry->delete())
        ->toThrow(LogicException::class, 'immutable')
        ->and(TenantMessagingLedgerEntry::query()->forAllTenants()->whereKey($entry->id)->value('amount_micros'))
        ->toBe(25000000);
});

test('gateway sends through a tenant sendgrid subuser and writes immutable usage settlement', function () {
    $tenant = Tenant::query()->create(['name' => 'Mail Co', 'slug' => 'mail-co']);
    TenantAccessAddon::query()->create(['tenant_id' => $tenant->id, 'addon_key' => 'messaging', 'enabled' => true]);
    $account = TenantMessagingAccount::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'channel' => 'email',
        'provider' => 'sendgrid_subuser',
        'status' => 'ready',
        'authenticated_domain' => 'mail-co.test',
        'credentials' => ['api_key' => 'tenant-sendgrid-key'],
    ]);
    TenantMessagingSenderProfile::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'tenant_messaging_account_id' => $account->id,
        'channel' => 'email',
        'label' => 'Orders',
        'display_name' => 'Mail Co',
        'from_email' => 'orders@mail-co.test',
        'reply_to_email' => 'orders@mail-co.test',
        'authenticated_domain' => 'mail-co.test',
        'reply_mode' => 'direct_inbox',
        'verification_status' => 'verified',
        'is_default' => true,
    ]);
    Http::fake(['api.sendgrid.com/*' => Http::response('', 202, ['X-Message-Id' => 'sg-tenant-message'])]);

    $result = app(TenantMessagingGateway::class)->sendEmail($tenant->id, 'buyer@example.test', 'Order update', 'Ready', [
        'idempotency_key' => 'email-delivery-44',
        'source_type' => 'marketing_email_delivery',
        'source_id' => 44,
    ]);
    $replay = app(TenantMessagingGateway::class)->sendEmail($tenant->id, 'buyer@example.test', 'Order update', 'Ready', [
        'idempotency_key' => 'email-delivery-44',
        'source_type' => 'marketing_email_delivery',
        'source_id' => 44,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['provider'])->toBe('sendgrid')
        ->and($replay['success'])->toBeTrue()
        ->and($replay['idempotent_replay'])->toBeTrue()
        ->and(DB::table('tenant_messaging_ledger_entries')->where('tenant_id', $tenant->id)->where('entry_type', 'usage_settlement')->count())->toBe(1);
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer tenant-sendgrid-key')
        && data_get($request->data(), 'from.email') === 'orders@mail-co.test'
        && data_get($request->data(), 'reply_to.email') === 'orders@mail-co.test');
    Http::assertSentCount(1);
});

test('stripe credit checkout fulfillment is idempotent and rejects amount tampering', function () {
    $tenant = Tenant::query()->create(['name' => 'Stripe Credit Co', 'slug' => 'stripe-credit-co']);
    config()->set('services.stripe.webhook_secret', 'whsec_test_credit');
    $event = [
        'id' => 'evt_credit_1',
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'created' => time(),
        'data' => ['object' => [
            'id' => 'cs_credit_1',
            'object' => 'checkout.session',
            'amount_total' => 2500,
            'payment_status' => 'paid',
            'metadata' => [
                'purpose' => 'messaging_credit',
                'tenant_id' => (string) $tenant->id,
                'pack_cents' => '2500',
            ],
        ]],
    ];
    $payload = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test_credit');
    $service = app(StripeWebhookIngestService::class);

    expect($service->ingest($payload, $signature)['message'])->toBe('processed_credit')
        ->and($service->ingest($payload, $signature)['message'])->toBe('processed_credit')
        ->and(DB::table('tenant_messaging_credit_accounts')->where('tenant_id', $tenant->id)->value('balance_micros'))->toBe(25000000)
        ->and(DB::table('tenant_messaging_ledger_entries')->where('entry_type', 'credit_funding')->count())->toBe(1);

    $event['id'] = 'evt_credit_tampered';
    $event['data']['object']['amount_total'] = 2400;
    $tamperedPayload = json_encode($event, JSON_THROW_ON_ERROR);
    $tamperedSignature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$tamperedPayload, 'whsec_test_credit');
    expect($service->ingest($tamperedPayload, $tamperedSignature)['status_code'])->toBe(400);
});
