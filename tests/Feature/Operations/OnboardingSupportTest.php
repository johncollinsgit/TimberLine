<?php

use App\Jobs\SendSupportTicketAlert;
use App\Models\OperatorAlertLog;
use App\Models\Tenant;
use App\Models\TenantSupportTicket;
use App\Models\User;
use App\Services\Bud\TenantBudService;
use App\Services\Marketing\TwilioProviderClient;
use App\Services\Mobile\TenantMobileSupportService;
use App\Services\Operations\OperatorAlertService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

test('unresolved guidance and explicit human requests create one tenant scoped ticket', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Northline', 'slug' => 'northline']);
    $user = User::factory()->create();
    $service = app(TenantBudService::class);
    $first = $service->respond($tenant, $user, 'I want a human to help with my setup.', [], 'domains');
    $second = $service->respond($tenant, $user, 'I want a human to help with my setup.', [], 'domains');

    expect($first['ticket_id'])->toBe($second['ticket_id'])
        ->and(TenantSupportTicket::withoutGlobalScopes()->count())->toBe(1)
        ->and(TenantSupportTicket::withoutGlobalScopes()->first()->metadata['transcript'][0]['text'])->toBe('Setup step: domains');
    $other = Tenant::query()->create(['name' => 'Westline', 'slug' => 'westline']);
    $third = $service->respond($other, $user, 'I want a human to help with my setup.');
    expect($third['ticket_id'])->not->toBe($first['ticket_id']);
    expect(fn () => app(TenantMobileSupportService::class)->show($other->id, $first['ticket_id']))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('the unavailable guide falls back to a durable ticket', function (): void {
    config()->set('bud.core_enabled', false);
    $tenant = Tenant::query()->create(['name' => 'Northline', 'slug' => 'northline']);
    $answer = app(TenantBudService::class)->respond($tenant, User::factory()->create(), 'How do I get started?');
    expect($answer['reply'])->toContain('unavailable')
        ->and($answer['ticket_id'])->not->toBeNull();
});

test('support notifications keep free text private and retry only explicit provider rejections', function (): void {
    config()->set('everbranch.operator_alert_sms_enabled', true);
    config()->set('everbranch.operator_alert_phone', '+18646165468');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    $tenant = Tenant::query()->create(['name' => 'Northline', 'slug' => 'northline']);
    $ticket = app(TenantMobileSupportService::class)->create($tenant, User::factory()->create(), [
        'subject' => 'secret-account-password', 'category' => 'help', 'priority' => 'normal', 'body' => 'Private diagnostic details',
    ]);
    $sms = \Mockery::mock(TwilioProviderClient::class);
    $sms->shouldReceive('sendSms')->twice()->with('18646165468', \Mockery::on(fn (string $message): bool => str_contains($message, 'https://app.theeverbranch.com/landlord/tickets/') && ! str_contains($message, 'secret-account-password')), \Mockery::type('array'))
        ->andReturn(['success' => false, 'error_code' => '429'], ['success' => true, 'provider' => 'twilio']);
    app()->instance(TwilioProviderClient::class, $sms);
    $job = new SendSupportTicketAlert($ticket['ticket']['id']);
    expect(fn () => $job->handle(app(OperatorAlertService::class)))->toThrow(\RuntimeException::class);
    expect(TenantSupportTicket::withoutGlobalScopes()->count())->toBe(1);
    $job->handle(app(OperatorAlertService::class));
    $job->handle(app(OperatorAlertService::class));
    expect(OperatorAlertLog::query()->sole()->status)->toBe('sent');
});

test('operator support alerts use the platform provider with tenant messaging enabled', function (): void {
    config()->set('features.tenant_messaging_platform', true);
    config()->set('everbranch.operator_alert_sms_enabled', true);
    config()->set('everbranch.operator_alert_phone', '+18646165468');
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.sms.dry_run', false);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.twilio.account_sid', 'AC-platform-fixture');
    config()->set('marketing.twilio.auth_token', 'platform-fixture-token');
    config()->set('marketing.twilio.from_number', '+15550100101');
    config()->set('marketing.twilio.messaging_service_sid', null);
    config()->set('marketing.twilio.senders', []);
    config()->set('everbranch.operator_alert_sender_key', 'legacy_default');
    \Illuminate\Support\Facades\Http::fake(['api.twilio.com/*' => \Illuminate\Support\Facades\Http::response(['sid' => 'SM-fixture', 'status' => 'queued'], 201)]);
    $gateway = \Mockery::mock(\App\Services\Marketing\Messaging\TenantMessagingGateway::class);
    $gateway->shouldNotReceive('sendSms');
    app()->instance(\App\Services\Marketing\Messaging\TenantMessagingGateway::class, $gateway);
    $tenant = Tenant::query()->create(['name' => 'Northline', 'slug' => 'northline']);
    $ticket = app(TenantMobileSupportService::class)->create($tenant, User::factory()->create(), [
        'subject' => 'Setup help', 'category' => 'help', 'priority' => 'normal', 'body' => 'Need help',
    ]);
    // A fresh worker has no customer context; an HTTP request may have one.
    app(\App\Support\Tenancy\TenantContext::class)->forget();
    (new SendSupportTicketAlert($ticket['ticket']['id']))->handle(app(OperatorAlertService::class));
    \Illuminate\Support\Facades\Http::assertSent(fn ($request): bool => str_contains($request->url(), '/Accounts/AC-platform-fixture/Messages.json') && $request['To'] === '18646165468' && $request['From'] === '+15550100101');
    expect(OperatorAlertLog::query()->sole()->status)->toBe('sent');
});

test('resolved assistance can be requested again while open requests stay deduplicated', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Northline', 'slug' => 'northline']);
    $user = User::factory()->create();
    $input = ['subject' => 'Domain help', 'category' => 'help', 'priority' => 'normal', 'body' => 'Connect my domain', 'dedupe_key' => 'website-domain:'.$tenant->id];
    $service = app(TenantMobileSupportService::class);
    $original = $service->create($tenant, $user, $input);
    $originalId = $original['ticket']['id'];
    TenantSupportTicket::withoutGlobalScopes()->findOrFail($originalId)->update(['status' => 'resolved']);
    $new = $service->create($tenant, $user, $input);
    expect($new['ticket']['id'])->not->toBe($originalId)
        ->and($service->create($tenant, $user, $input)['ticket']['id'])->toBe($new['ticket']['id'])
        ->and(TenantSupportTicket::withoutGlobalScopes()->findOrFail($originalId)->metadata['closed_dedupe_key'])->toBe($input['dedupe_key']);
});
