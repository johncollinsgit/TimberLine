<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantMailMessage;
use App\Models\TenantModuleAccessRequest;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Mailbox\CloudflareDnsSetupService;
use App\Services\Mailbox\TenantMailboxService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->withoutVite();
});

test('mail domains are exclusive to one tenant and a new mailbox is granted to its creator', function () {
    $first = Tenant::query()->create(['name' => 'Wren', 'slug' => 'wren-mail-test']);
    $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-mail-test']);
    $admin = User::factory()->create(['role' => 'admin']);
    $first->users()->attach($admin->id, ['role' => 'admin', 'membership_active' => true]);
    $service = app(TenantMailboxService::class);
    $domain = $service->createDomain($first, 'easleyfamilysoccer.com', 'sendgrid');
    $box = $service->createMailbox($domain, 'info', 'Wren Family Soccer', $admin);
    expect($box->address)->toBe('info@easleyfamilysoccer.com')
        ->and($box->status)->toBe('pending_domain')
        ->and($box->users()->whereKey($admin->id)->exists())->toBeTrue();
    expect(fn () => $service->createDomain($other, 'easleyfamilysoccer.com', 'direct'))->toThrow(ValidationException::class);
});

test('workspace admin can sign up for a pending address before the email module is enabled', function () {
    $tenant = Tenant::query()->create(['name' => 'Wren', 'slug' => 'wren-mail-signup-test']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct', 'source' => 'test']);
    $admin = User::factory()->create(['role' => 'admin']);
    $member = User::factory()->create(['role' => 'member']);
    $tenant->users()->attach($admin->id, ['role' => 'admin', 'membership_active' => true]);
    $tenant->users()->attach($member->id, ['role' => 'member', 'membership_active' => true]);

    $this->actingAs($member)->get(route('mail.setup', ['tenant' => $tenant->slug]))->assertForbidden();
    $this->actingAs($admin)->get(route('mail.setup', ['tenant' => $tenant->slug]))->assertOk();
    $this->actingAs($admin)->post(route('mail.setup.store', ['tenant' => $tenant->slug]), [
        'address' => 'info@easleyfamilysoccer.com', 'display_name' => 'Wren Family Soccer', 'transport' => 'direct',
    ])->assertRedirect();

    $domain = \App\Models\TenantMailDomain::query()->forTenantId($tenant->id)->firstOrFail();
    expect($domain->status)->toBe('pending_dns')
        ->and(collect($domain->dns_records)->firstWhere('type', 'TXT')['host'])->toBe('_everbranch-mail.easleyfamilysoccer.com')
        ->and($domain->mailboxes()->firstOrFail()->address)->toBe('info@easleyfamilysoccer.com')
        ->and(TenantModuleAccessRequest::query()->where('tenant_id', $tenant->id)->where('module_key', 'email')->exists())->toBeTrue();
});

test('Cloudflare quick setup adds ownership proof without changing MX before transport is ready', function () {
    $tenant = Tenant::query()->create(['name' => 'Wren', 'slug' => 'wren-cloudflare-test']);
    $admin = User::factory()->create(['role' => 'admin']);
    $tenant->users()->attach($admin->id, ['role' => 'admin', 'membership_active' => true]);
    $domain = app(TenantMailboxService::class)->createDomain($tenant, 'easleyfamilysoccer.com', 'direct');
    Http::fakeSequence()
        ->push(['success' => true, 'result' => [['id' => str_repeat('a', 32), 'name' => $domain->domain, 'status' => 'active']]])
        ->push(['success' => true, 'result' => []])
        ->push(['success' => true, 'result' => ['id' => 'proof-record']]);

    $message = app(CloudflareDnsSetupService::class)->apply($domain, 'scoped-token');
    expect($message)->toContain('ownership record added');
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['type'] === 'TXT'
        && $request['name'] === '_everbranch-mail.easleyfamilysoccer.com');
});

test('Cloudflare quick setup preserves an existing mail provider MX', function () {
    $tenant = Tenant::query()->create(['name' => 'Wren', 'slug' => 'wren-cloudflare-mx-test']);
    $admin = User::factory()->create(['role' => 'admin']);
    $tenant->users()->attach($admin->id, ['role' => 'admin', 'membership_active' => true]);
    $service = app(TenantMailboxService::class);
    $domain = $service->createDomain($tenant, 'easleyfamilysoccer.com', 'direct');
    $domain->update(['provider_domain_id' => 'server-domain']);
    $service->createMailbox($domain, 'info', 'Wren', $admin)->update(['provider_account_id' => 'server-account']);
    config()->set('mailbox.direct_enabled', true);
    Http::fakeSequence()
        ->push(['success' => true, 'result' => [['id' => str_repeat('a', 32), 'name' => $domain->domain, 'status' => 'active']]])
        ->push(['success' => true, 'result' => []])
        ->push(['success' => true, 'result' => ['id' => 'proof-record']])
        ->push(['success' => true, 'result' => [['name' => $domain->domain, 'content' => 'aspmx.l.google.com']]]);

    $message = app(CloudflareDnsSetupService::class)->apply($domain, 'scoped-token');
    expect($message)->toContain('routing was left unchanged');
    Http::assertSentCount(4);
});

test('Cloudflare quick setup rejects a token scoped to another domain', function () {
    $tenant = Tenant::query()->create(['name' => 'Wren', 'slug' => 'wren-cloudflare-zone-test']);
    $domain = app(TenantMailboxService::class)->createDomain($tenant, 'easleyfamilysoccer.com', 'direct');
    Http::fakeSequence()->push(['success' => true, 'result' => [['id' => str_repeat('a', 32), 'name' => 'another-domain.com', 'status' => 'active']]]);

    expect(fn () => app(CloudflareDnsSetupService::class)->apply($domain, 'wrong-zone-token'))
        ->toThrow(ValidationException::class);
    Http::assertSentCount(1);
});

test('signed inbound parse saves only the exact ready mailbox and deduplicates message ids', function () {
    $tenant = Tenant::query()->create(['name' => 'Wren', 'slug' => 'wren-inbound-test']);
    $user = User::factory()->create(['role' => 'admin']);
    $tenant->users()->attach($user->id, ['role' => 'admin', 'membership_active' => true]);
    $service = app(TenantMailboxService::class);
    $domain = $service->createDomain($tenant, 'easleyfamilysoccer.com', 'sendgrid');
    $box = $service->createMailbox($domain, 'info', 'Wren', $user);
    $domain->update(['status' => 'ready']);
    $box->update(['status' => 'ready']);
    config()->set('mailbox.inbound_token', 'test-inbound-secret');
    $payload = ['from' => 'Parent <parent@example.org>', 'to' => 'info@easleyfamilysoccer.com', 'subject' => 'Soccer', 'text' => 'Hello', 'headers' => "Message-ID: <mail-001@example.org>\n"];
    $this->post(route('mail.webhooks.sendgrid', ['token' => 'wrong']), $payload)->assertForbidden();
    $this->post(route('mail.webhooks.sendgrid', ['token' => 'test-inbound-secret']), $payload)->assertOk()->assertJsonPath('accepted', 1);
    $this->post(route('mail.webhooks.sendgrid', ['token' => 'test-inbound-secret']), $payload)->assertOk();
    expect(TenantMailMessage::query()->forTenantId($tenant->id)->count())->toBe(1)
        ->and(TenantMailMessage::query()->first()->from_address)->toBe('parent@example.org');
    $this->post(route('mail.webhooks.sendgrid', ['token' => 'test-inbound-secret']), [...$payload, 'to' => 'unknown@easleyfamilysoccer.com'])->assertJsonPath('accepted', 0);
    expect(TenantMailMessage::query()->count())->toBe(1);
});

test('mail page requires module access and shows only the member granted the mailbox', function () {
    $tenant = Tenant::query()->create(['name' => 'Wren', 'slug' => 'wren-mail-page-test']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct', 'source' => 'test']);
    $admin = User::factory()->create(['role' => 'admin']);
    $member = User::factory()->create(['role' => 'member']);
    $tenant->users()->attach($admin->id, ['role' => 'admin', 'membership_active' => true]);
    $tenant->users()->attach($member->id, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($admin)->get(route('mail.index', ['tenant' => $tenant->slug]))->assertForbidden();
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id, 'module_key' => 'email', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'included', 'currency' => 'USD',
        'entitlement_source' => 'test', 'price_source' => 'test',
    ]);
    $service = app(TenantMailboxService::class);
    $box = $service->createMailbox($service->createDomain($tenant, 'easleyfamilysoccer.com', 'sendgrid'), 'info', 'Wren', $admin);
    $this->actingAs($admin)->get(route('mail.index', ['tenant' => $tenant->slug]))->assertOk()->assertSeeText($box->address);
    $this->actingAs($member)->get(route('mail.index', ['tenant' => $tenant->slug]))->assertOk()->assertDontSeeText($box->address);
});
