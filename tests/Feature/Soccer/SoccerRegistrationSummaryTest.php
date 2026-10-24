<?php

use App\Models\Tenant;
use App\Services\Soccer\SoccerDeliveryService;
use App\Services\Soccer\SoccerPaymentService;
use App\Services\Soccer\SoccerRegistrationService;
use App\Services\Soccer\SoccerRegistrationSummaryService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set(['soccer.notification_mode' => 'email', 'soccer.groupme_notifications_enabled' => false, 'soccer.admin_notification_emails' => ['john@example.com', 'sarah@example.com'], 'mail.default' => 'array']);
    Mail::purge('array');
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Wren', 'slug' => 'wren-family-soccer']);
    $this->store = app(SoccerStore::class);
    $this->season = $this->store->insert('seasons', $this->tenant->id, ['season_key' => 'spring-2027', 'name' => 'Spring 2027', 'starts_on' => '2027-03-15', 'registration_open' => true, 'teams' => json_encode(config('soccer.teams'))]);
    $this->family = $this->store->insert('families', $this->tenant->id, ['email' => 'parent@example.com', 'verified_at' => now(), 'profile' => $this->store->encrypt(['parentName' => 'Example Parent', 'phone' => '8645550100', 'emergencyContact' => 'Example Contact', 'emergencyPhone' => '8645550101'])]);
    $this->children = [];
    foreach (['Avery' => '2023-01-01', 'Riley' => '2020-01-01'] as $name => $birthday) {
        $player = $this->store->insert('players', $this->tenant->id, ['family_id' => $this->family, 'name' => $name, 'birthday' => $birthday, 'profile' => $this->store->encrypt(['allergies' => 'private medical value'])]);
        $this->store->insert('releases', $this->tenant->id, ['family_id' => $this->family, 'player_id' => $player, 'season_key' => 'spring-2027', 'source_key' => Str::uuid(), 'form_version' => 'test', 'answers' => $this->store->encrypt([]), 'sha256' => str_repeat('a', 64), 'signed_at' => now()]);
        $this->children[] = ['player_id' => $player, 'needs_jersey' => false];
    }
    $this->input = ['season_id' => $this->season, 'volunteer' => false, 'children' => $this->children];
});

it('emails both organizers once per signup and confirmed payment with cumulative totals', function () {
    $registration = app(SoccerRegistrationService::class);
    $registration->register($this->tenant->id, $this->family, $this->input);
    $registration->register($this->tenant->id, $this->family, $this->input);
    $delivery = app(SoccerDeliveryService::class);
    $delivery->run($this->tenant->id);
    $delivery->run($this->tenant->id);
    $messages = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage());
    expect($messages)->toHaveCount(3);
    foreach (['john@example.com', 'sarah@example.com'] as $recipient) {
        $message = $messages->first(fn ($message) => $message->getTo()[0]->getAddress() === $recipient);
        expect($message->getSubject())->toContain('New family registration', 'Example Parent');
        expect($message->getHtmlBody())->toContain('Avery', 'Riley', 'Blue Dragons', 'Green Bears', 'Season tally', '$0.00')->not->toContain('private medical value');
        expect($message->getTextBody())->toContain('Families: 1', 'Kids with spots: 2', 'Not paid: 2');
    }
    $invoice = $this->store->query('invoices', $this->tenant->id)->sole();
    $payments = app(SoccerPaymentService::class);
    $payments->paid($this->tenant->id, $invoice->id, 'venmo', 'verified-transfer', 6000, []);
    $payments->paid($this->tenant->id, $invoice->id, 'venmo', 'verified-transfer', 6000, []);
    $delivery->run($this->tenant->id);
    $messages = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage());
    expect($messages)->toHaveCount(6);
    $summaries = $messages->filter(fn ($message) => str_contains($message->getSubject(), 'Payment received'));
    expect($summaries)->toHaveCount(2);
    foreach ($summaries as $message) {
        expect($message->getTextBody())->toContain('Paid: 2', 'Not paid: 0', 'Net collected: $60.00');
    }
    Http::assertNothingSent();
});

it('separates waitlisted and waived children and counts refunds without leaking another tenant or season', function () {
    app(SoccerRegistrationService::class)->register($this->tenant->id, $this->family, $this->input);
    $rows = $this->store->query('registrations', $this->tenant->id)->get();
    $this->store->query('registrations', $this->tenant->id)->where('id', $rows[0]->id)->update(['status' => 'registered', 'payment_status' => 'waived', 'waived' => true]);
    $this->store->query('registrations', $this->tenant->id)->where('id', $rows[1]->id)->update(['status' => 'waitlisted', 'seat_kind' => null]);
    $this->store->query('invoices', $this->tenant->id)->update(['status' => 'paid', 'amount_cents' => 3000, 'refunded_cents' => 1000]);
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
    $this->store->insert('invoices', $other->id, ['season_id' => $this->season, 'family_id' => $this->family, 'offer_group' => Str::uuid(), 'registration_ids' => '[]', 'amount_cents' => 99999, 'status' => 'paid', 'expires_at' => now()->addDay()]);
    $this->store->insert('invoices', $this->tenant->id, ['season_id' => Str::uuid(), 'family_id' => $this->family, 'offer_group' => Str::uuid(), 'registration_ids' => '[]', 'amount_cents' => 99999, 'status' => 'paid', 'expires_at' => now()->addDay()]);
    $event = $this->store->query('events', $this->tenant->id)->where('action', 'family_registration_submitted')->sole();
    $summary = app(SoccerRegistrationSummaryService::class)->forEvent($this->tenant->id, $event);
    expect($summary['totals'])->toMatchArray(['families' => 1, 'children' => 1, 'paid' => 0, 'unpaid' => 0, 'waived' => 1, 'waiting' => 1, 'received_cents' => 3000, 'refunded_cents' => 1000, 'net_cents' => 2000]);
});

it('emails a waitlist-only signup and suppresses recipients removed before delivery', function () {
    $teams = config('soccer.teams');
    foreach ($teams as &$team) {
        $team['public'] = 0;
        $team['reserved'] = 0;
    }
    $this->store->query('seasons', $this->tenant->id)->update(['teams' => json_encode($teams)]);
    app(SoccerRegistrationService::class)->register($this->tenant->id, $this->family, $this->input);
    config()->set('soccer.admin_notification_emails', ['john@example.com']);
    app(SoccerDeliveryService::class)->run($this->tenant->id);
    $messages = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage());
    expect($messages)->toHaveCount(2);
    expect($messages->first(fn ($message) => $message->getTo()[0]->getAddress() === 'john@example.com')->getTextBody())->toContain('Kids with spots: 0', 'Waitlisted: 2');
    expect($this->store->query('deliveries', $this->tenant->id)->where('error', 'admin_recipient_removed')->count())->toBe(1);
    Http::assertNothingSent();
});

it('sends one platform signup alert to each organizer while parents keep email', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-23 12:00:00', 'America/New_York'));
    config()->set(['soccer.admin_sms_enabled' => true, 'soccer.admin_sms_recipients' => ['8645550111', '+18645550112', '(864) 555-0111', 'invalid'], 'soccer.admin_sms_sender_key' => 'platform_alerts', 'soccer.sms_enabled' => false, 'marketing.sms.enabled' => true, 'marketing.twilio.enabled' => true, 'marketing.sms.dry_run' => false]);
    $this->mock(\App\Services\Marketing\Messaging\TenantMessagingGateway::class)->shouldNotReceive('sendSms');
    $this->mock(\App\Services\Marketing\TwilioProviderClient::class)->shouldReceive('sendSms')->twice()
        ->withArgs(fn ($phone, $body, $options) => in_array($phone, ['+18645550111', '+18645550112'], true)
            && str_contains($body, 'Everbranch: Wren Soccer signup.') && str_contains($body, '2 awaiting payment, 0 waitlisted')
            && ! str_contains($body, 'private medical') && $options === ['sender_key' => 'platform_alerts'])
        ->andReturnUsing(fn ($phone) => ['success' => true, 'provider_message_id' => 'SMfictional'.$phone, 'dry_run' => false]);
    $registration = app(SoccerRegistrationService::class);
    $registration->register($this->tenant->id, $this->family, $this->input);
    $registration->register($this->tenant->id, $this->family, $this->input);
    $delivery = app(SoccerDeliveryService::class);
    $delivery->run($this->tenant->id);
    $delivery->run($this->tenant->id);
    expect($this->store->query('deliveries', $this->tenant->id)->where('status', 'accepted')->count())->toBe(5);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(3);
    Http::assertNothingSent();
});

it('queues waitlist-only signup alerts, observes quiet hours and suppresses removed organizers', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-23 20:00:00', 'America/New_York'));
    config()->set(['soccer.admin_sms_enabled' => true, 'soccer.admin_sms_recipients' => ['8645550111', '8645550112'], 'soccer.admin_sms_sender_key' => 'platform_alerts', 'marketing.sms.enabled' => true, 'marketing.twilio.enabled' => true, 'marketing.sms.dry_run' => false]);
    $teams = config('soccer.teams');
    foreach ($teams as &$team) {
        $team['public'] = $team['reserved'] = 0;
    }
    $this->store->query('seasons', $this->tenant->id)->update(['teams' => json_encode($teams)]);
    app(SoccerRegistrationService::class)->register($this->tenant->id, $this->family, $this->input);
    $alerts = $this->store->query('deliveries', $this->tenant->id)->get()->filter(fn ($d) => ($this->store->decrypt($d->payload)['channel'] ?? '') === 'admin_sms');
    expect($alerts)->toHaveCount(2);
    expect($this->store->decrypt($alerts->first()->payload)['body'])->toContain('0 registered, 0 awaiting payment, 2 waitlisted');
    $provider = $this->mock(\App\Services\Marketing\TwilioProviderClient::class);
    $provider->shouldReceive('sendSms')->once()->withArgs(fn ($phone) => $phone === '+18645550111')->andReturn(['success' => true, 'provider_message_id' => 'SMfictional']);
    $delivery = app(SoccerDeliveryService::class);
    $delivery->run($this->tenant->id);
    foreach ($alerts as $alert) {
        expect($this->store->row('deliveries', $this->tenant->id, $alert->id)->attempts)->toBe(0);
    }
    config()->set('soccer.admin_sms_recipients', ['8645550111']);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-24 08:00:00', 'America/New_York'));
    $delivery->run($this->tenant->id);
    expect($this->store->query('deliveries', $this->tenant->id)->where('error', 'admin_sms_recipient_removed')->count())->toBe(1);
});

it('does not notify Wren organizers for another tenant or retry uncertain platform texts', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-23 12:00:00', 'America/New_York'));
    config()->set(['soccer.admin_sms_enabled' => true, 'soccer.admin_sms_recipients' => ['8645550111'], 'soccer.admin_sms_sender_key' => 'platform_alerts', 'marketing.sms.enabled' => true, 'marketing.twilio.enabled' => true, 'marketing.sms.dry_run' => false]);
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other-soccer']);
    expect(app(\App\Services\Soccer\SoccerEvents::class)->adminSmsRecipients($other->id))->toBe([]);
    $this->mock(\App\Services\Marketing\TwilioProviderClient::class)->shouldReceive('sendSms')->once()->andReturn(['success' => false, 'error_code' => 'exception']);
    app(SoccerRegistrationService::class)->register($this->tenant->id, $this->family, $this->input);
    $delivery = app(SoccerDeliveryService::class);
    $delivery->run($this->tenant->id);
    $delivery->run($this->tenant->id);
    expect($this->store->query('deliveries', $this->tenant->id)->where('status', 'uncertain')->count())->toBe(1);
});
