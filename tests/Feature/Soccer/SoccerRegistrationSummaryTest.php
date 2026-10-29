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
        expect($message->getSubject())->toContain('Family application received', 'Example Parent');
        expect($message->getHtmlBody())->toContain('Avery', 'Riley', 'Blue Dragons', 'Green Bears', 'Season tally', '$0.00')->not->toContain('private medical value');
        expect($message->getTextBody())->toContain('Families: 1', 'Confirmed places: 0', 'Temporary reservations (payment unconfirmed): 2', 'Unpaid applications (temporary + saved): 2');
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
        expect($message->getTextBody())->toContain('Paid: 2', 'Confirmed places: 2', 'Temporary reservations (payment unconfirmed): 0', 'Net collected: $60.00');
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
    expect($messages->first(fn ($message) => $message->getTo()[0]->getAddress() === 'john@example.com')->getTextBody())->toContain('Confirmed places: 0', 'Waitlisted: 2');
    expect($this->store->query('deliveries', $this->tenant->id)->where('error', 'admin_recipient_removed')->count())->toBe(1);
    Http::assertNothingSent();
});

it('keeps expired saved applications out of occupied places and renders matching team totals', function () {
    $registration = app(SoccerRegistrationService::class);
    $registration->register($this->tenant->id, $this->family, $this->input);
    $event = $this->store->query('events', $this->tenant->id)->where('action', 'family_registration_submitted')->sole();
    $this->travel(31)->minutes();
    $registration->tick($this->tenant->id, $this->season);
    $summary = app(SoccerRegistrationSummaryService::class)->forEvent($this->tenant->id, $event);
    expect($summary['totals'])->toMatchArray(['children' => 0, 'confirmed' => 0, 'reserved' => 0, 'pending' => 2, 'unpaid' => 2, 'waiting' => 0, 'net_cents' => 0]);
    foreach (['confirmed', 'reserved', 'pending', 'paid', 'waived'] as $metric) {
        expect(array_sum(array_column($summary['groups'], $metric)))->toBe($summary['totals'][$metric]);
    }
    expect(array_column($summary['children'], 'status'))->toBe(array_fill(0, 2, 'Application saved - no spot reserved; payment unconfirmed'));
    foreach (['registration-summary', 'registration-summary-text'] as $view) {
        $body = view('emails.soccer.'.$view, ['summary' => $summary])->render();
        expect($body)->toContain('0', '2', 'no spot reserved; payment unconfirmed')->not->toContain('Kids with spots', 'Payment due');
    }
    Http::assertNothingSent();
});

it('renders current payment facts when payment precedes delivery of the signup notice', function () {
    app(SoccerRegistrationService::class)->register($this->tenant->id, $this->family, $this->input);
    $invoice = $this->store->query('invoices', $this->tenant->id)->sole();
    app(SoccerPaymentService::class)->paid($this->tenant->id, $invoice->id, 'stripe', 'intent:fictional-race', 6000, []);
    app(SoccerPaymentService::class)->paid($this->tenant->id, $invoice->id, 'stripe', 'intent:fictional-race', 6000, []);
    app(SoccerDeliveryService::class)->run($this->tenant->id);
    app(SoccerDeliveryService::class)->run($this->tenant->id);
    $messages = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage());
    expect($messages->filter(fn ($message) => $message->getTo()[0]->getAddress() === 'john@example.com'))->toHaveCount(2);
    $signup = $messages->first(fn ($message) => str_contains($message->getSubject(), 'Family application received'));
    expect($signup->getTextBody())->toContain('Confirmed places: 2', 'Temporary reservations (payment unconfirmed): 0', 'Confirmed - paid', 'Net collected: $60.00');
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(1);
});

it('does not confirm late duplicate payments against a released reservation', function () {
    app(SoccerRegistrationService::class)->register($this->tenant->id, $this->family, $this->input);
    $invoice = $this->store->query('invoices', $this->tenant->id)->sole();
    $this->travel(31)->minutes();
    app(SoccerRegistrationService::class)->tick($this->tenant->id, $this->season);
    app(SoccerPaymentService::class)->paid($this->tenant->id, $invoice->id, 'stripe', 'intent:fictional-late', 6000, []);
    app(SoccerPaymentService::class)->paid($this->tenant->id, $invoice->id, 'stripe', 'intent:fictional-late', 6000, []);
    $event = $this->store->query('events', $this->tenant->id)->where('action', 'family_registration_submitted')->sole();
    $summary = app(SoccerRegistrationSummaryService::class)->forEvent($this->tenant->id, $event);
    expect($summary['totals'])->toMatchArray(['confirmed' => 0, 'pending' => 2, 'paid' => 0, 'net_cents' => 0]);
    expect($this->store->query('events', $this->tenant->id)->where('action', 'payment_confirmed')->exists())->toBeFalse();
    expect($this->store->query('events', $this->tenant->id)->where('action', 'payment_needs_review')->count())->toBe(1);
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(1);
});

it('previews and sends one correction to only the accepted original organizer recipient', function () {
    app(SoccerRegistrationService::class)->register($this->tenant->id, $this->family, $this->input);
    app(SoccerDeliveryService::class)->run($this->tenant->id);
    $original = $this->store->query('deliveries', $this->tenant->id)->get()->first(fn ($row) => ($this->store->decrypt($row->payload)['email'] ?? null) === 'john@example.com');
    $before = $this->store->query('deliveries', $this->tenant->id)->count();
    $this->artisan('soccer:correct-registration-summary', ['delivery' => $original->id, '--recipient' => 'john@example.com'])->assertSuccessful();
    expect($this->store->query('deliveries', $this->tenant->id)->count())->toBe($before);
    // Leave an unrelated new notification pending; correction must not drain it.
    $unrelated = app(\App\Services\Soccer\SoccerEvents::class)->record($this->tenant->id, null, $this->family, 'test_unrelated', [], familyText: 'Unrelated pending notice');
    $args = ['delivery' => $original->id, '--recipient' => 'john@example.com', '--apply' => true];
    $this->artisan('soccer:correct-registration-summary', $args)->assertSuccessful();
    $this->artisan('soccer:correct-registration-summary', $args)->expectsOutputToContain('No new send attempted')->assertSuccessful();
    $messages = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage());
    $corrections = $messages->filter(fn ($message) => str_contains($message->getSubject(), 'Corrected registration summary'));
    expect($corrections)->toHaveCount(1);
    $message = $corrections->first();
    expect(array_map(fn ($address) => $address->getAddress(), $message->getTo()))->toBe(['john@example.com']);
    expect($message->getCc())->toBe([])->and($message->getBcc())->toBe([]);
    expect($message->getTextBody())->toContain('Correction to the original', 'Confirmed places: 0', 'Temporary reservations (payment unconfirmed): 2');
    expect($this->store->query('deliveries', $this->tenant->id)->where('event_id', $unrelated)->sole()->status)->toBe('pending');
});

it('refuses a correction to the parent or an unaccepted original delivery', function () {
    app(SoccerRegistrationService::class)->register($this->tenant->id, $this->family, $this->input);
    $original = $this->store->query('deliveries', $this->tenant->id)->get()->first(fn ($row) => ($this->store->decrypt($row->payload)['email'] ?? null) === 'john@example.com');
    $this->artisan('soccer:correct-registration-summary', ['delivery' => $original->id, '--recipient' => 'john@example.com', '--apply' => true])->assertFailed();
    app(SoccerDeliveryService::class)->run($this->tenant->id);
    $this->artisan('soccer:correct-registration-summary', ['delivery' => $original->id, '--recipient' => 'parent@example.com', '--apply' => true])->assertFailed();
    config()->set('soccer.admin_notification_emails', ['sarah@example.com']);
    $this->artisan('soccer:correct-registration-summary', ['delivery' => $original->id, '--recipient' => 'john@example.com', '--apply' => true])->assertFailed();
    expect($this->store->query('events', $this->tenant->id)->where('action', 'registration_summary_corrected')->exists())->toBeFalse();
});

it('serializes accented names as UTF-8 in both MIME parts', function () {
    $this->store->query('players', $this->tenant->id)->where('id', $this->children[0]['player_id'])->update(['name' => 'Fictional Núñez']);
    app(SoccerRegistrationService::class)->register($this->tenant->id, $this->family, $this->input);
    app(SoccerDeliveryService::class)->run($this->tenant->id);
    $message = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage())->first(fn ($message) => $message->getTo()[0]->getAddress() === 'john@example.com');
    $mime = $message->toString();
    expect(substr_count(strtolower($mime), 'charset=utf-8'))->toBe(2);
    expect(substr_count($mime, 'Content-Transfer-Encoding: base64'))->toBe(2);
    $trackingHeaders = iterator_to_array($message->getHeaders()->all('X-SMTPAPI'), false);
    expect($trackingHeaders)->toHaveCount(1);
    expect(json_decode($trackingHeaders[0]->getBodyAsString(), true, 512, JSON_THROW_ON_ERROR))->toBe([
        'filters' => [
            'clicktrack' => ['settings' => ['enable' => 0, 'enable_text' => false]],
            'opentrack' => ['settings' => ['enable' => 0]],
        ],
    ]);
    foreach ($message->getBody()->getParts() as $part) {
        expect(base64_decode($part->bodyToString(), true))->toContain('Fictional Núñez');
    }
    expect($message->getTextBody())->toContain('Fictional Núñez')->not->toContain('NÃºñez');
    expect($message->getHtmlBody())->toContain('Fictional Núñez')->not->toContain('NÃºñez');
});

it('holds an uncertain correction without a second send on replay', function () {
    app(SoccerRegistrationService::class)->register($this->tenant->id, $this->family, $this->input);
    app(SoccerDeliveryService::class)->run($this->tenant->id);
    $original = $this->store->query('deliveries', $this->tenant->id)->get()->first(fn ($row) => ($this->store->decrypt($row->payload)['email'] ?? null) === 'john@example.com');
    Mail::partialMock()->shouldReceive('to')->with('john@example.com')->once()->andThrow(new RuntimeException('Unknown SMTP result'));
    $args = ['delivery' => $original->id, '--recipient' => 'john@example.com', '--apply' => true];
    $this->artisan('soccer:correct-registration-summary', $args)->expectsOutputToContain('uncertain')->assertFailed();
    $this->artisan('soccer:correct-registration-summary', $args)->expectsOutputToContain('No new send attempted')->assertFailed();
    $correction = $this->store->query('events', $this->tenant->id)->where('action', 'registration_summary_corrected')->sole();
    expect($this->store->query('deliveries', $this->tenant->id)->where('event_id', $correction->id)->sole()->status)->toBe('uncertain');
});

it('distinguishes confirmed coach waivers from an unpaid sibling reservation in both notices', function () {
    $player = $this->store->insert('players', $this->tenant->id, ['family_id' => $this->family, 'name' => 'Taylor', 'birthday' => '2018-01-01', 'profile' => $this->store->encrypt([])]);
    $this->store->insert('releases', $this->tenant->id, ['family_id' => $this->family, 'player_id' => $player, 'season_key' => 'spring-2027', 'source_key' => Str::uuid(), 'form_version' => 'test', 'answers' => $this->store->encrypt([]), 'sha256' => str_repeat('a', 64), 'signed_at' => now()]);
    $input = [...$this->input, 'volunteer' => true, 'coach_jersey_size' => 'Adult L', 'coach_parent' => 'mother', 'coach_name' => 'Example Parent', 'coach_teams' => ['green-bears'], 'coach_available_8_of_10' => true];
    $input['children'][] = ['player_id' => $player, 'needs_jersey' => false];
    app(SoccerRegistrationService::class)->register($this->tenant->id, $this->family, $input);
    app(SoccerDeliveryService::class)->run($this->tenant->id);
    $messages = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage());
    $admin = $messages->first(fn ($message) => $message->getTo()[0]->getAddress() === 'john@example.com');
    expect($admin->getTextBody())->toContain('Confirmed places: 2', 'Fee waived: 2', 'Paid: 0', 'Temporary reservations (payment unconfirmed): 1', 'Net collected: $0.00');
    $parent = $messages->first(fn ($message) => $message->getTo()[0]->getAddress() === 'parent@example.com');
    expect($parent->getTextBody())->toContain('waived fees have confirmed places', 'payment is not yet confirmed', 'Pay $30.00', 'application stays saved');
});
