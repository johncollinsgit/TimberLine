<?php

use App\Models\Tenant;
use App\Services\Soccer\SoccerPaymentService;
use App\Services\Soccer\SoccerRegistrationService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config()->set('soccer.enabled', true);
    $this->t = Tenant::create(['name' => 'Wren', 'slug' => 'wren-family-soccer']);
    $this->store = app(SoccerStore::class);
    $this->svc = app(SoccerRegistrationService::class);
    $this->season = $this->store->insert('seasons', $this->t->id, ['season_key' => 'spring-2027', 'name' => 'Spring 2027', 'starts_on' => '2027-03-01', 'registration_open' => true, 'teams' => json_encode(config('soccer.teams'))]);
});
function soccerFamily($test, array $birthdays): array
{
    $f = $test->store->insert('families', $test->t->id, ['email' => \Illuminate\Support\Str::uuid().'@example.com', 'profile' => $test->store->encrypt(['parentName' => 'Parent', 'phone' => '8645550100', 'emergencyContact' => 'Emergency Contact', 'emergencyPhone' => '8645550101']), 'verified_at' => now(), 'sms_consent_at' => now()]);
    $children = [];
    foreach ($birthdays as $n => $dob) {
        $p = $test->store->insert('players', $test->t->id, ['family_id' => $f, 'name' => 'Player '.$n, 'birthday' => $dob, 'profile' => $test->store->encrypt([])]);
        $test->store->insert('releases', $test->t->id, ['family_id' => $f, 'player_id' => $p, 'season_key' => 'spring-2027', 'source_key' => \Illuminate\Support\Str::uuid(), 'form_version' => 'test', 'answers' => $test->store->encrypt([]), 'sha256' => str_repeat('a', 64), 'signed_at' => now()]);
        $children[] = ['player_id' => $p, 'needs_jersey' => false];
    }

    return [$f, ['season_id' => $test->season, 'volunteer' => false, 'children' => $children]];
}
function soccerCap($test, array $caps): void
{
    $ts = config('soccer.teams');
    foreach ($caps as $k => $v) {
        $ts[$k] = [...$ts[$k], ...$v];
    }$test->store->query('seasons', $test->t->id)->where('id', $test->season)->update(['teams' => json_encode($ts)]);
}
it('uses birthday on season start at each age boundary', function () {
    foreach (['2024-03-01' => 'green-bears', '2023-03-01' => 'green-bears', '2022-03-01' => 'blue-dragons', '2020-03-01' => 'blue-dragons', '2019-03-01' => 'red-falcons', '2005-03-01' => 'red-falcons'] as $dob => $team) {
        expect($this->svc->teamFor($dob, '2027-03-01'))->toBe($team);
    }expect(fn () => $this->svc->teamFor('2024-03-02', '2027-03-01'))->toThrow(ValidationException::class);
});
it('uses reserves for all siblings only after a strict public majority', function () {
    soccerCap($this, ['red-falcons' => ['public' => 0, 'reserved' => 1]]);
    [$f,$in] = soccerFamily($this, ['2023-01-01', '2021-01-01', '2018-01-01']);
    $out = $this->svc->register($this->t->id, $f, $in);
    expect($out['placed'])->toHaveCount(3)->and($out['waitlisted'])->toHaveCount(0);
    expect($this->store->query('registrations', $this->t->id)->where('seat_kind', 'reserved')->count())->toBe(1);
});
it('does not grant reserves for a tied family', function () {
    soccerCap($this, ['red-falcons' => ['public' => 0, 'reserved' => 1]]);
    [$f,$in] = soccerFamily($this, ['2023-01-01', '2018-01-01']);
    $out = $this->svc->register($this->t->id, $f, $in);
    expect($out['placed'])->toHaveCount(1)->and($out['waitlisted'])->toHaveCount(1);
});
it('keeps remaining siblings together if reserves cannot fit them all', function () {
    soccerCap($this, ['red-falcons' => ['public' => 0, 'reserved' => 1]]);
    [$f,$in] = soccerFamily($this, ['2023-01-01', '2023-02-01', '2021-01-01', '2018-01-01', '2017-01-01']);
    $out = $this->svc->register($this->t->id, $f, $in);
    expect($out['placed'])->toHaveCount(3)->and($out['waitlisted'])->toHaveCount(2);
    expect($this->store->query('registrations', $this->t->id)->where('seat_kind', 'reserved')->count())->toBe(0);
});
it('charges 30 or 50 and waives two children including jerseys', function () {
    [$f,$in] = soccerFamily($this, ['2023-01-01', '2021-01-01', '2018-01-01']);
    $in['volunteer'] = true;
    $in['coach_jersey_size'] = 'Adult L';
    $in['waived_player_ids'] = array_column(array_slice($in['children'], 0, 2), 'player_id');
    foreach ($in['children'] as &$c) {
        $c['needs_jersey'] = true;
        $c['jersey_size'] = 'Youth M';
    }unset($c);
    $this->svc->register($this->t->id, $f, $in);
    $r = $this->store->query('registrations', $this->t->id)->get();
    expect($r->where('waived', true))->toHaveCount(2)->and($r->sum('amount_cents'))->toBe(5000);
});
it('replays registration without creating duplicate children seats or invoices', function () {
    [$f,$in] = soccerFamily($this, ['2023-01-01']);
    $this->svc->register($this->t->id, $f, $in);
    $this->svc->register($this->t->id, $f, $in);
    expect($this->store->query('registrations', $this->t->id)->count())->toBe(1)->and($this->store->query('invoices', $this->t->id)->count())->toBe(1);
});
it('does not invoice waitlisted players', function () {
    soccerCap($this, ['green-bears' => ['public' => 0, 'reserved' => 0]]);
    [$f,$in] = soccerFamily($this, ['2023-01-01']);
    $this->svc->register($this->t->id, $f, $in);
    expect($this->store->query('invoices', $this->t->id)->count())->toBe(0);
});
it('blocks queue bypass and promotes an entire waiting family atomically', function () {
    soccerCap($this, ['green-bears' => ['public' => 0, 'reserved' => 0], 'blue-dragons' => ['public' => 0, 'reserved' => 0]]);
    [$f,$in] = soccerFamily($this, ['2023-01-01', '2021-01-01']);
    $this->svc->register($this->t->id, $f, $in);
    soccerCap($this, ['green-bears' => ['public' => 1, 'reserved' => 0], 'blue-dragons' => ['public' => 0, 'reserved' => 0]]);
    [$later,$laterIn] = soccerFamily($this, ['2023-01-01']);
    expect($this->svc->register($this->t->id, $later, $laterIn)['waitlisted'])->toHaveCount(1);
    expect($this->svc->promote($this->t->id, $this->season))->toBe(0);
    soccerCap($this, ['green-bears' => ['public' => 1, 'reserved' => 0], 'blue-dragons' => ['public' => 1, 'reserved' => 0]]);
    expect($this->svc->promote($this->t->id, $this->season))->toBe(2);
    expect($this->store->query('registrations', $this->t->id)->where('family_id', $later)->value('status'))->toBe('waitlisted');
});
it('expires offers without changing original priority or immediately reoffering', function () {
    [$f,$in] = soccerFamily($this, ['2023-01-01']);
    $this->svc->register($this->t->id, $f, $in);
    $before = $this->store->query('registrations', $this->t->id)->first()->queued_at;
    $this->travel(49)->hours();
    $this->svc->tick($this->t->id, $this->season);
    $r = $this->store->query('registrations', $this->t->id)->first();
    expect($r->status)->toBe('waitlisted')->and((bool) $r->auto_eligible)->toBeFalse()->and($r->queued_at)->toBe($before);
});
it('deduplicates reminders and Sarah alerts', function () {
    [$f,$in] = soccerFamily($this, ['2023-01-01']);
    $this->svc->register($this->t->id, $f, $in);
    $this->travel(25)->hours();
    $this->svc->tick($this->t->id, $this->season);
    $n = $this->store->query('deliveries', $this->t->id)->count();
    $this->svc->tick($this->t->id, $this->season);
    expect($this->store->query('deliveries', $this->t->id)->count())->toBe($n);
});
it('confirms verified Venmo once without trusting parent declarations', function () {
    [$f,$in] = soccerFamily($this, ['2023-01-01']);
    $this->svc->register($this->t->id, $f, $in);
    $i = $this->store->query('invoices', $this->t->id)->first();
    $p = app(SoccerPaymentService::class);
    $p->paid($this->t->id, $i->id, 'venmo', 'verified-transfer', 3000, ['verified' => true]);
    $p->paid($this->t->id, $i->id, 'venmo', 'verified-transfer', 3000, ['verified' => true]);
    expect($this->store->query('registrations', $this->t->id)->value('status'))->toBe('registered')->and($this->store->query('payments', $this->t->id)->count())->toBe(1);
});
it('flags late payment without taking an expired seat', function () {
    [$f,$in] = soccerFamily($this, ['2023-01-01']);
    $this->svc->register($this->t->id, $f, $in);
    $i = $this->store->query('invoices', $this->t->id)->first();
    $this->travel(49)->hours();
    $this->svc->tick($this->t->id, $this->season);
    app(SoccerPaymentService::class)->paid($this->t->id, $i->id, 'stripe', 'evt-late', 3000, ['payment_intent' => 'pi_late']);
    expect($this->store->query('registrations', $this->t->id)->value('status'))->toBe('waitlisted')->and($this->store->query('events', $this->t->id)->where('action', 'payment_needs_review')->count())->toBe(1);
});
it('keeps player registered after a full manual refund and rejects excess refunds', function () {
    [$f,$in] = soccerFamily($this, ['2023-01-01']);
    $this->svc->register($this->t->id, $f, $in);
    $i = $this->store->query('invoices', $this->t->id)->first();
    $p = app(SoccerPaymentService::class);
    $p->paid($this->t->id, $i->id, 'venmo', 'ref', 3000, []);
    $p->refund($this->t->id, $i->id, 3000, 'Refund requested', 1, true);
    expect($this->store->query('registrations', $this->t->id)->value('status'))->toBe('registered');
    expect(fn () => $p->refund($this->t->id, $i->id, 1, 'Excess', 1, true))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
it('confirms waived siblings immediately and holds only the unpaid child', function () {
    [$family,$input] = soccerFamily($this, ['2023-01-01', '2021-01-01', '2018-01-01']);
    $input['volunteer'] = true;
    $input['coach_jersey_size'] = 'Adult L';
    $input['waived_player_ids'] = array_column(array_slice($input['children'], 0, 2), 'player_id');
    $this->svc->register($this->t->id, $family, $input);
    expect($this->store->query('registrations', $this->t->id)->where('status', 'registered')->count())->toBe(2);
    $this->travel(49)->hours();
    $this->svc->tick($this->t->id, $this->season);
    expect($this->store->query('registrations', $this->t->id)->where('status', 'registered')->count())->toBe(2)->and($this->store->query('registrations', $this->t->id)->where('status', 'waitlisted')->count())->toBe(1);
});
it('withdraws an unpaid offer without cancelling children or losing priority', function () {
    [$family,$input] = soccerFamily($this, ['2023-01-01']);
    $this->svc->register($this->t->id, $family, $input);
    $invoice = $this->store->query('invoices', $this->t->id)->first();
    $before = $this->store->query('registrations', $this->t->id)->value('queue_sequence');
    $this->svc->withdrawOffer($this->t->id, $invoice->id, 1, 'Family requested more time');
    $r = $this->store->query('registrations', $this->t->id)->first();
    expect($r->status)->toBe('waitlisted')->and((bool) $r->auto_eligible)->toBeFalse()->and($r->queue_sequence)->toBe($before);
});
it('refunds a recorded late payment without enrolling its waitlisted child', function () {
    [$family,$input] = soccerFamily($this, ['2023-01-01']);
    $this->svc->register($this->t->id, $family, $input);
    $invoice = $this->store->query('invoices', $this->t->id)->first();
    $this->travel(49)->hours();
    $this->svc->tick($this->t->id, $this->season);
    $payments = app(SoccerPaymentService::class);
    $payments->paid($this->t->id, $invoice->id, 'venmo', 'late', 3000, []);
    $payment = $this->store->query('payments', $this->t->id)->first();
    $payments->refund($this->t->id, $invoice->id, 3000, 'Late transfer refunded', 1, true, $payment->id);
    expect($this->store->query('registrations', $this->t->id)->value('status'))->toBe('waitlisted');
});
it('authenticates Stripe callbacks and deduplicates by the underlying payment intent', function () {
    config()->set('soccer.stripe_webhook_secret', 'whsec_fictional_test_secret');
    [$family,$input] = soccerFamily($this, ['2023-01-01']);
    $this->svc->register($this->t->id, $family, $input);
    $invoice = $this->store->query('invoices', $this->t->id)->first();
    $this->store->query('invoices', $this->t->id)->where('id', $invoice->id)->update(['checkout_id' => 'cs_fixture']);
    $event = ['id' => 'evt_fixture_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_fixture', 'payment_status' => 'paid', 'currency' => 'usd', 'amount_total' => 3000, 'payment_intent' => 'pi_fixture', 'metadata' => ['soccer_invoice_id' => $invoice->id]]]];
    $json = json_encode($event);
    $this->call('POST', '/api/soccer/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json)->assertStatus(400);
    foreach (['evt_fixture_1', 'evt_fixture_2'] as $id) {
        $event['id'] = $id;
        $json = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$json, 'whsec_fictional_test_secret');
        $this->call('POST', '/api/soccer/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature], $json)->assertOk();
    }
    expect($this->store->query('payments', $this->t->id)->count())->toBe(1)->and($this->store->query('registrations', $this->t->id)->value('status'))->toBe('registered');
});
