<?php

use App\Models\Tenant;
use App\Services\Soccer\SoccerPaymentService;
use App\Services\Soccer\SoccerRecoveryService;
use App\Services\Soccer\SoccerRegistrationService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config()->set(['soccer.enabled' => true, 'soccer.payment_reminders_enabled' => true, 'soccer.notification_mode' => 'email', 'services.stripe.secret' => 'sk_test_recovery_fictional']);
    Http::preventStrayRequests();
    $this->t = Tenant::create(['name' => 'Wren', 'slug' => 'wren-family-soccer']);
    \App\Models\TenantAccessProfile::create(['tenant_id' => $this->t->id, 'plan_key' => 'starter', 'operating_mode' => 'direct', 'metadata' => ['workspace_focus' => 'soccer']]);
    \App\Models\TenantModuleState::create(['tenant_id' => $this->t->id, 'module_key' => 'soccer', 'enabled_override' => true]);
    $this->store = app(SoccerStore::class);
    $this->svc = app(SoccerRegistrationService::class);
    $this->recovery = app(SoccerRecoveryService::class);
    $this->season = $this->store->insert('seasons', $this->t->id, ['season_key' => 'spring-2027', 'name' => 'Spring 2027', 'starts_on' => '2027-03-15', 'registration_open' => true, 'teams' => json_encode(config('soccer.teams'))]);
});

function recoveryFamily($test, array $birthdays = ['2023-01-01']): array
{
    $family = $test->store->insert('families', $test->t->id, ['email' => Str::uuid().'@example.com', 'verified_at' => now(), 'profile' => $test->store->encrypt(['parentName' => 'Fictional Parent', 'phone' => '8645550100', 'emergencyContact' => 'Emergency', 'emergencyPhone' => '8645550101', 'registration_draft' => ['selected' => ['saved-draft']]])]);
    $children = [];
    foreach ($birthdays as $birthday) {
        $player = $test->store->insert('players', $test->t->id, ['family_id' => $family, 'name' => 'Fictional Player', 'birthday' => $birthday, 'profile' => $test->store->encrypt([])]);
        $test->store->insert('releases', $test->t->id, ['family_id' => $family, 'player_id' => $player, 'season_key' => 'spring-2027', 'source_key' => Str::uuid(), 'form_version' => 'test', 'answers' => $test->store->encrypt(['child1Birthday' => $birthday]), 'sha256' => str_repeat('a', 64), 'signed_at' => now()]);
        $children[] = ['player_id' => $player, 'needs_jersey' => false];
    }
    $test->svc->register($test->t->id, $family, ['season_id' => $test->season, 'volunteer' => false, 'children' => $children]);

    return [$family, $test->store->query('invoices', $test->t->id)->where('family_id', $family)->first()];
}

function recoveryCapacity($test, array $changes): void
{
    $teams = config('soccer.teams');
    foreach ($changes as $key => $count) {
        $teams[$key]['public'] = $count;
        $teams[$key]['reserved'] = 0;
    }
    $test->store->query('seasons', $test->t->id)->where('id', $test->season)->update(['teams' => json_encode($teams)]);
}

it('resumes an expired application without reentering details duplicating players or signing again', function () {
    [$family, $original] = recoveryFamily($this);
    $profile = $this->store->row('families', $this->t->id, $family)->profile;
    $this->travel(31)->minutes();
    $this->svc->tick($this->t->id, $this->season);
    expect($this->store->query('registrations', $this->t->id)->sole()->status)->toBe('payment_pending');
    $result = $this->recovery->resume($this->t->id, $this->season, $family);
    expect($result['offered'])->toBe(1)->and($result['invoice_id'])->not->toBe($original->id)
        ->and($this->store->query('registrations', $this->t->id)->count())->toBe(1)
        ->and($this->store->query('releases', $this->t->id)->count())->toBe(1)
        ->and($this->store->row('families', $this->t->id, $family)->profile)->toBe($profile);
    expect($this->recovery->resume($this->t->id, $this->season, $family)['invoice_id'])->toBe($result['invoice_id'])
        ->and($this->store->query('invoices', $this->t->id)->count())->toBe(2);
});

it('recovers a legacy inactive waitlist while preserving saved amounts and queue history', function () {
    [$family, $invoice] = recoveryFamily($this);
    $row = $this->store->query('registrations', $this->t->id)->sole();
    $this->store->query('invoices', $this->t->id)->where('id', $invoice->id)->update(['status' => 'expired']);
    $this->store->query('registrations', $this->t->id)->where('id', $row->id)->update(['status' => 'waitlisted', 'auto_eligible' => false, 'seat_kind' => null]);
    expect($this->recovery->summary($this->t->id, $this->season, $family))->toMatchArray(['pending_count' => 1, 'can_resume' => true, 'available_count' => 1]);
    $this->recovery->resume($this->t->id, $this->season, $family);
    $after = $this->store->row('registrations', $this->t->id, $row->id);
    expect($after->status)->toBe('offered')->and($after->queue_sequence)->toBe($row->queue_sequence)->and($after->amount_cents)->toBe($row->amount_cents);
});

it('shows an active invoice for a Sam Adams style family with another child still waiting', function () {
    recoveryCapacity($this, ['blue-dragons' => 0]);
    [$family, $invoice] = recoveryFamily($this, ['2018-01-01', '2021-01-01']);
    expect($this->store->query('registrations', $this->t->id)->where('status', 'offered')->count())->toBe(1)
        ->and($this->store->query('registrations', $this->t->id)->where('status', 'waitlisted')->count())->toBe(1);
    expect($this->recovery->resume($this->t->id, $this->season, $family)['invoice_id'])->toBe($invoice->id);
    $this->travel(31)->minutes();
    $result = $this->recovery->resume($this->t->id, $this->season, $family);
    expect($result['offered'])->toBe(1)->and($result['invoice_id'])->not->toBeNull()
        ->and($this->store->query('registrations', $this->t->id)->where('status', 'waitlisted')->count())->toBe(1);
});

it('waitlists only actual capacity shortages and never charges for unavailable places', function () {
    recoveryCapacity($this, ['green-bears' => 1]);
    [$first] = recoveryFamily($this);
    $this->travel(31)->minutes();
    $this->svc->tick($this->t->id, $this->season);
    [$paying] = recoveryFamily($this);
    $before = $this->store->query('invoices', $this->t->id)->count();
    $result = $this->recovery->resume($this->t->id, $this->season, $first);
    expect($result)->toMatchArray(['invoice_id' => null, 'offered' => 0, 'waiting' => 1])
        ->and($this->store->query('invoices', $this->t->id)->count())->toBe($before)
        ->and($this->store->query('registrations', $this->t->id)->where('status', 'offered')->count())->toBe(1);
});

it('suppresses new collection and reminders for a recorded receipt awaiting review', function () {
    [$family, $invoice] = recoveryFamily($this);
    $this->travel(31)->minutes();
    $this->svc->tick($this->t->id, $this->season);
    app(SoccerPaymentService::class)->paid($this->t->id, $invoice->id, 'venmo', 'verified-old-transfer', 3000, ['verified' => true]);
    expect($this->recovery->summary($this->t->id, $this->season, $family))->toMatchArray(['needs_review' => true, 'can_resume' => false]);
    expect(fn () => $this->recovery->resume($this->t->id, $this->season, $family))->toThrow(HttpException::class, 'needs review');
    $this->travel(2)->days();
    $this->svc->tick($this->t->id, $this->season);
    expect($this->store->query('events', $this->t->id)->where('action', 'registration_payment_reminder')->count())->toBe(0);
});

it('rechecks reminders after payment withdrawal closure and unavailable capacity', function () {
    [$family, $invoice] = recoveryFamily($this);
    $this->travel(25)->hours();
    $this->svc->tick($this->t->id, $this->season);
    $this->svc->tick($this->t->id, $this->season);
    $event = $this->store->query('events', $this->t->id)->where('action', 'registration_payment_reminder')->sole();
    expect($this->recovery->reminderEligible($this->t->id, $event))->toBeTrue();
    $this->travel(25)->hours();
    $this->svc->tick($this->t->id, $this->season);
    expect($this->store->query('events', $this->t->id)->where('action', 'registration_payment_reminder')->count())->toBe(2);
    recoveryCapacity($this, ['green-bears' => 0]);
    expect($this->recovery->reminderEligible($this->t->id, $event))->toBeFalse();
    recoveryCapacity($this, ['green-bears' => 1]);
    $this->store->query('registrations', $this->t->id)->where('family_id', $family)->update(['status' => 'cancelled']);
    expect($this->recovery->reminderEligible($this->t->id, $event))->toBeFalse();
    $this->store->query('registrations', $this->t->id)->where('family_id', $family)->update(['status' => 'registered', 'payment_status' => 'paid']);
    expect($this->recovery->reminderEligible($this->t->id, $event))->toBeFalse();
    $this->store->query('seasons', $this->t->id)->where('id', $this->season)->update(['registration_open' => false]);
    expect(fn () => $this->recovery->resume($this->t->id, $this->season, $family))->toThrow(HttpException::class, 'closed');
});

it('retains the seat when Stripe cannot determine whether an old checkout was paid', function () {
    [$family, $invoice] = recoveryFamily($this);
    $this->store->query('invoices', $this->t->id)->where('id', $invoice->id)->update(['checkout_id' => 'cs_unknown']);
    Http::fake(['api.stripe.com/*' => Http::response([], 503)]);
    $this->travel(31)->minutes();
    $this->svc->tick($this->t->id, $this->season);
    expect($this->store->query('registrations', $this->t->id)->sole()->status)->toBe('offered')
        ->and($this->store->row('invoices', $this->t->id, $invoice->id)->status)->toBe('unpaid');
});

it('uses the authenticated family and rejects expired reminders at delivery time', function () {
    [$family] = recoveryFamily($this);
    [$other] = recoveryFamily($this);
    $this->travel(25)->hours();
    $this->svc->tick($this->t->id, $this->season);
    $event = $this->store->query('events', $this->t->id)->where('family_id', $family)->where('action', 'registration_payment_reminder')->sole();
    $this->store->query('seasons', $this->t->id)->where('id', $this->season)->update(['registration_open' => false]);
    \Illuminate\Support\Facades\Mail::fake();
    app(\App\Services\Soccer\SoccerDeliveryService::class)->run($this->t->id);
    expect($this->store->query('deliveries', $this->t->id)->where('event_id', $event->id)->sole()->status)->toBe('suppressed');

    $this->store->query('seasons', $this->t->id)->where('id', $this->season)->update(['registration_open' => true]);
    config()->set('soccer.bridge_secret', str_repeat('k', 40));
    $token = Str::random(48);
    $this->store->insert('sessions', $this->t->id, ['family_id' => $family, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
    $path = '/api/soccer/portal/resume';
    $body = json_encode(['season_id' => $this->season, 'family_id' => $other]);
    $timestamp = (string) time();
    $nonce = (string) Str::uuid();
    $signature = hash_hmac('sha256', "POST\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body), config('soccer.bridge_secret'));
    $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_WREN_SESSION' => $token, 'HTTP_X_WREN_TIME' => $timestamp, 'HTTP_X_WREN_NONCE' => $nonce, 'HTTP_X_WREN_SIGNATURE' => $signature], $body)
        ->assertOk()->assertJsonPath('family.id', $family)->assertJsonPath('recovery.offered', 1);
    expect($this->store->query('registrations', $this->t->id)->where('family_id', $other)->sole()->status)->toBe('payment_pending');
});
