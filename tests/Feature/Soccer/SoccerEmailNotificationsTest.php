<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Soccer\SoccerDeliveryService;
use App\Services\Soccer\SoccerEvents;
use App\Services\Soccer\SoccerLaunchReadiness;
use App\Services\Soccer\SoccerStore;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config()->set(['soccer.notification_mode' => 'email', 'soccer.sms_enabled' => false, 'mail.default' => 'array', 'soccer.bridge_secret' => str_repeat('b', 32), 'soccer.stripe_webhook_secret' => str_repeat('w', 32)]);
    Mail::purge('array');
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Wren', 'slug' => 'wren-family-soccer']);
    app(\App\Support\Tenancy\TenantContext::class)->set($this->tenant->id);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    $this->store = app(SoccerStore::class);
    $this->groups = ['soccer' => '101', 'head_coaches' => '102', 'all_coaches' => '103'];
    $this->store->saveSettings($this->tenant->id, ['groupme_groups' => $this->groups, 'verified_group_ids' => $this->groups, 'verified_group_user_id' => $this->admin->id, 'launch_verified' => true, 'launch_verified_mode' => 'email']);
    $this->store->insert('groupme_connections', $this->tenant->id, ['user_id' => $this->admin->id, 'provider_user_id' => '999', 'token' => Crypt::encryptString('fictional-groupme-token')]);
    $this->family = $this->store->insert('families', $this->tenant->id, ['email' => 'parent@example.com', 'verified_at' => now(), 'profile' => $this->store->encrypt(['phone' => '2025550101', 'parentName' => 'Private Parent'])]);
    $this->events = app(SoccerEvents::class);
    $this->delivery = app(SoccerDeliveryService::class);
});

it('allows verified email launch without SMS while preserving all other readiness gates', function () {
    config()->set(['mail.default' => 'smtp', 'marketing.sms.enabled' => false]);
    $payments = $this->mock(\App\Services\Billing\TenantPaymentsReadinessService::class);
    $payments->shouldReceive('readinessFor')->andReturn(['blockers' => []]);
    expect(app(SoccerLaunchReadiness::class)->blockers($this->tenant))->toBe([]);
    $settings = $this->store->settings($this->tenant->id);
    unset($settings['launch_verified_mode']);
    $this->store->saveSettings($this->tenant->id, $settings);
    expect(app(SoccerLaunchReadiness::class)->blockers($this->tenant))->toContain('Admin delivery and payment smoke verification is incomplete.');
    config()->set('mail.default', 'array');
    expect(app(SoccerLaunchReadiness::class)->blockers($this->tenant))->toContain('Sign-in email delivery must use a verified live mail transport.');
    config()->set('soccer.notification_mode', 'unexpected');
    expect(app(SoccerLaunchReadiness::class)->blockers($this->tenant))->toContain('Select a supported soccer notification mode.');
    config()->set('soccer.notification_mode', 'sms');
    expect(app(SoccerLaunchReadiness::class)->blockers($this->tenant))->toContain('Wren SMS sender is not ready.');
});

it('does not bypass payment or GroupMe readiness in email mode', function () {
    config()->set('mail.default', 'smtp');
    $this->mock(\App\Services\Billing\TenantPaymentsReadinessService::class)->shouldReceive('readinessFor')->andReturn(['blockers' => ['Card verification required.']]);
    $this->store->query('groupme_connections', $this->tenant->id)->delete();
    expect(app(SoccerLaunchReadiness::class)->blockers($this->tenant))->toContain('Card verification required.', 'An admin must connect GroupMe and verify membership in all three mapped groups.');
});

it('emails verified parents without SMS consent, records Messages, and does not resend', function () {
    $this->events->record($this->tenant->id, null, $this->family, 'initial_registration', [], key: 'family-submit', familyText: 'Your places are reserved.');
    $this->events->record($this->tenant->id, null, $this->family, 'initial_registration', [], key: 'family-submit', familyText: 'Your places are reserved.');
    $this->delivery->run($this->tenant->id);
    $this->delivery->run($this->tenant->id);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
    $delivery = $this->store->query('deliveries', $this->tenant->id)->sole();
    expect($delivery->status)->toBe('accepted')->and($delivery->attempts)->toBe(1)->and($delivery->provider_message_id)->not->toBeNull();
    expect(\App\Models\MessagingConversation::query()->forTenantId($this->tenant->id)->where('channel', 'email')->sole()->messages()->count())->toBe(1);
    Http::assertNothingSent();
});

it('sends generic Head Coaches alerts and private parent updates with replay-safe GroupMe requests', function () {
    Http::fake([
        'api.groupme.com/v3/groups/102' => Http::response(['response' => ['members' => [['user_id' => '999']]]]),
        'api.groupme.com/v3/groups/102/messages' => Http::sequence()->push([], 500)->push(['response' => ['message' => ['id' => '456']]], 201),
    ]);
    $this->events->record($this->tenant->id, null, $this->family, 'family_waitlisted', ['summary' => 'Private medical information and manual reason'], waitlist: true);
    $this->delivery->run($this->tenant->id);
    $this->store->query('deliveries', $this->tenant->id)->where('status', 'failed')->update(['retry_at' => now()->subMinute()]);
    $this->delivery->run($this->tenant->id);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
    $posts = Http::recorded(fn ($request) => $request->method() === 'POST')->values();
    expect($posts)->toHaveCount(2)->and($posts[0][0]['message']['source_guid'])->toBe($posts[1][0]['message']['source_guid']);
    expect($posts[0][0]['message']['text'])->toContain('/soccer/messages')->not->toContain('Private', 'medical', 'parent@example.com', '?pay=');
    expect($this->store->query('deliveries', $this->tenant->id)->where('status', 'accepted')->count())->toBe(2);
});

it('checks current admin role and provider group membership before automatic alerts', function () {
    Http::fake(['api.groupme.com/v3/groups/102' => Http::response(['response' => ['members' => []]])]);
    $this->events->record($this->tenant->id, null, null, 'waitlist_reactivated', [], waitlist: true);
    $this->delivery->run($this->tenant->id);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    $this->admin->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $this->store->query('deliveries', $this->tenant->id)->update(['retry_at' => now()->subMinute()]);
    $this->delivery->run($this->tenant->id);
    Http::assertSentCount(1);
    expect($this->store->query('deliveries', $this->tenant->id)->sole()->status)->toBe('failed');
});

it('suppresses stale invoice emails and changed family addresses before SMTP', function () {
    $season = $this->store->insert('seasons', $this->tenant->id, ['season_key' => 'spring-2027', 'name' => 'Spring', 'teams' => '{}']);
    $invoice = $this->store->insert('invoices', $this->tenant->id, ['season_id' => $season, 'family_id' => $this->family, 'offer_group' => (string) \Illuminate\Support\Str::uuid(), 'registration_ids' => '[]', 'amount_cents' => 3000, 'expires_at' => now()->addHour()]);
    $this->events->record($this->tenant->id, $season, $this->family, 'payment_reminder', ['invoice_id' => $invoice], familyText: 'Pay your invoice.');
    $this->store->query('invoices', $this->tenant->id)->where('id', $invoice)->update(['status' => 'paid']);
    $this->events->record($this->tenant->id, null, $this->family, 'payment_confirmed', [], familyText: 'Payment received.');
    $this->store->query('families', $this->tenant->id)->where('id', $this->family)->update(['email' => 'changed@example.com']);
    $this->delivery->run($this->tenant->id);
    expect($this->store->query('deliveries', $this->tenant->id)->where('status', 'suppressed')->count())->toBe(2)->and(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('does not send to an unverified family or automatically retry ambiguous SMTP failures', function () {
    $this->store->query('families', $this->tenant->id)->where('id', $this->family)->update(['verified_at' => null]);
    $this->events->record($this->tenant->id, null, $this->family, 'initial_registration', [], familyText: 'Reserved.');
    expect($this->store->query('deliveries', $this->tenant->id)->count())->toBe(0);
    $this->store->query('families', $this->tenant->id)->where('id', $this->family)->update(['verified_at' => now()]);
    $this->events->record($this->tenant->id, null, $this->family, 'initial_registration', [], familyText: 'Reserved.');
    Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Ambiguous SMTP timeout'));
    $this->delivery->run($this->tenant->id);
    $this->delivery->run($this->tenant->id);
    expect($this->store->query('deliveries', $this->tenant->id)->sole()->status)->toBe('uncertain');
});
