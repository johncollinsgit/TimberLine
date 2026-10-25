<?php

use App\Models\Tenant;
use App\Services\Soccer\SoccerApplicationService;
use App\Services\Soccer\SoccerRegistrationService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config()->set(['soccer.enabled' => true, 'soccer.notification_mode' => 'email', 'services.stripe.secret' => 'sk_test_amend_fictional']);
    Http::preventStrayRequests();
    $this->t = Tenant::create(['name' => 'Wren', 'slug' => 'wren-family-soccer']);
    \App\Models\TenantAccessProfile::create(['tenant_id' => $this->t->id, 'plan_key' => 'starter', 'operating_mode' => 'direct', 'metadata' => ['workspace_focus' => 'soccer']]);
    \App\Models\TenantModuleState::create(['tenant_id' => $this->t->id, 'module_key' => 'soccer', 'enabled_override' => true]);
    $this->store = app(SoccerStore::class);
    $this->registration = app(SoccerRegistrationService::class);
    $this->applications = app(SoccerApplicationService::class);
    $this->season = $this->store->insert('seasons', $this->t->id, ['season_key' => 'spring-2027', 'name' => 'Spring 2027', 'starts_on' => '2027-03-15', 'registration_open' => true, 'teams' => json_encode(config('soccer.teams'))]);
});

function amendFamily($test, int $count = 3): array
{
    $family = $test->store->insert('families', $test->t->id, ['email' => Str::uuid().'@example.com', 'verified_at' => now(),
        'profile' => $test->store->encrypt(['parentName' => 'Fictional Parent', 'phone' => '8645550100', 'emergencyContact' => 'Emergency', 'emergencyPhone' => '8645550101'])]);
    $children = [];
    for ($n = 0; $n < $count; $n++) {
        $player = $test->store->insert('players', $test->t->id, ['family_id' => $family, 'name' => 'Fictional Player '.$n, 'birthday' => '2021-01-01', 'profile' => $test->store->encrypt([])]);
        $test->store->insert('releases', $test->t->id, ['family_id' => $family, 'player_id' => $player, 'season_key' => 'spring-2027', 'source_key' => Str::uuid(),
            'form_version' => 'test', 'answers' => $test->store->encrypt(['child1Birthday' => '2021-01-01']), 'sha256' => str_repeat('a', 64), 'signed_at' => now()]);
        $children[] = ['player_id' => $player, 'needs_jersey' => false, 'jersey_size' => ''];
    }
    $input = ['season_id' => $test->season, 'volunteer' => false, 'children' => $children];
    $test->registration->register($test->t->id, $family, $input);

    return [$family, $input, $test->store->query('invoices', $test->t->id)->where('family_id', $family)->sole()];
}

function amendCoaching(array $input): array
{
    return [...$input, 'volunteer' => true, 'coach_parent' => 'mother', 'coach_name' => 'Fictional Coach',
        'coach_teams' => ['blue-dragons'], 'coach_available_8_of_10' => true, 'coach_jersey_size' => 'Adult M'];
}

function amendReviewed($test, string $family, array $input): array
{
    $quote = $test->applications->quote($test->t->id, $family, $input);

    return [...$input, 'expected_application_revision' => $quote['application_revision'], 'expected_due_today_cents' => $quote['due_today_cents'],
        'expected_available_player_ids' => collect($quote['children'])->where('status', 'available')->pluck('player_id')->all()];
}

function amendSession($test, object $invoice, string $status, string $payment = 'unpaid'): array
{
    return ['id' => 'cs_amend_test', 'currency' => 'usd', 'status' => $status, 'payment_status' => $payment,
        'payment_intent' => 'pi_amend_test', 'amount_total' => (int) $invoice->amount_cents,
        'metadata' => ['soccer_invoice_id' => $invoice->id, 'tenant_id' => (string) $test->t->id]];
}

it('amends a Gerber-style expired application with two coaching waivers without duplicate players or releases', function () {
    [$family, $input, $original] = amendFamily($this);
    $ids = $this->store->query('registrations', $this->t->id)->pluck('id')->all();
    $sequence = $this->store->query('registrations', $this->t->id)->pluck('queue_sequence', 'id')->all();
    $this->travel(31)->minutes();
    $this->registration->tick($this->t->id, $this->season);
    $input = amendReviewed($this, $family, amendCoaching($input));
    expect($input['expected_due_today_cents'])->toBe(3000);
    $result = $this->applications->amend($this->t->id, $family, $input);
    expect($result)->toMatchArray(['offered' => 3, 'waiting' => 0])
        ->and($this->store->row('invoices', $this->t->id, $result['invoice_id'])->amount_cents)->toBe(3000)
        ->and($this->store->row('invoices', $this->t->id, $original->id)->amount_cents)->toBe(9000)
        ->and($this->store->query('registrations', $this->t->id)->pluck('id')->all())->toBe($ids)
        ->and($this->store->query('registrations', $this->t->id)->pluck('queue_sequence', 'id')->all())->toBe($sequence)
        ->and($this->store->query('registrations', $this->t->id)->where('waived', true)->count())->toBe(2)
        ->and($this->store->query('releases', $this->t->id)->count())->toBe(3)
        ->and($this->store->query('payments', $this->t->id)->count())->toBe(0);
    expect($this->applications->amend($this->t->id, $family, $input))->toBe($result)
        ->and($this->store->query('invoices', $this->t->id)->count())->toBe(2)
        ->and($this->store->decrypt($this->store->row('families', $this->t->id, $family)->profile)['coaching_by_season']['spring-2027']['volunteer'])->toBeTrue();
});

it('preserves a Collins-style confirmed sibling waiver while editing the remaining unpaid player', function () {
    [$family, $input, $invoice] = amendFamily($this, 3);
    $rows = $this->store->query('registrations', $this->t->id)->orderBy('queue_sequence')->get();
    $confirmed = $rows->take(2);
    foreach ($confirmed as $row) {
        $this->store->query('registrations', $this->t->id)->where('id', $row->id)->update(['status' => 'registered', 'waived' => true, 'payment_status' => 'waived', 'amount_cents' => 0]);
    }
    $this->store->query('invoices', $this->t->id)->where('id', $invoice->id)->update(['amount_cents' => 3000]);
    $before = $this->store->query('registrations', $this->t->id)->whereIn('id', $confirmed->pluck('id'))->get()->all();
    $input['children'] = [$input['children'][2]];
    $input['children'][0] = [...$input['children'][0], 'needs_jersey' => true, 'jersey_size' => 'Youth M'];
    $input = amendReviewed($this, $family, amendCoaching($input));
    expect($input['expected_due_today_cents'])->toBe(5000);
    $result = $this->applications->amend($this->t->id, $family, $input);
    expect($this->store->query('registrations', $this->t->id)->whereIn('id', $confirmed->pluck('id'))->get()->all())->toEqual($before)
        ->and($this->store->row('invoices', $this->t->id, $result['invoice_id'])->amount_cents)->toBe(5000)
        ->and($this->store->query('registrations', $this->t->id)->count())->toBe(3);
});

it('retires the old open Stripe session before issuing the revised invoice and keeps the held seat', function () {
    [$family, $input, $invoice] = amendFamily($this, 1);
    $this->store->query('invoices', $this->t->id)->where('id', $invoice->id)->update(['checkout_id' => 'cs_amend_test']);
    Http::fake(['api.stripe.com/*' => Http::sequence()->push(amendSession($this, $invoice, 'open'))->push([])->push(amendSession($this, $invoice, 'expired'))]);
    $input['children'][0] = [...$input['children'][0], 'needs_jersey' => true, 'jersey_size' => 'Youth M'];
    $result = $this->applications->amend($this->t->id, $family, amendReviewed($this, $family, $input));
    expect($this->store->row('invoices', $this->t->id, $invoice->id)->status)->toBe('expired')
        ->and($this->store->row('invoices', $this->t->id, $result['invoice_id'])->amount_cents)->toBe(5000)
        ->and($this->store->query('registrations', $this->t->id)->sole()->seat_kind)->toBe('public');
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/cs_amend_test/expire'));
});

it('commits a racing real card payment while blocking coaching repricing', function () {
    [$family, $input, $invoice] = amendFamily($this, 1);
    $this->store->query('invoices', $this->t->id)->where('id', $invoice->id)->update(['checkout_id' => 'cs_amend_test']);
    Http::fake(['api.stripe.com/*' => Http::response(amendSession($this, $invoice, 'complete', 'paid'))]);
    $input = amendReviewed($this, $family, amendCoaching($input));
    expect(fn () => $this->applications->amend($this->t->id, $family, $input))->toThrow(HttpException::class, 'payment was preserved');
    expect($this->store->row('invoices', $this->t->id, $invoice->id)->status)->toBe('paid')
        ->and($this->store->query('payments', $this->t->id)->count())->toBe(1)
        ->and($this->store->query('registrations', $this->t->id)->sole()->payment_status)->toBe('paid')
        ->and($this->store->query('invoices', $this->t->id)->count())->toBe(1)
        ->and($this->store->decrypt($this->store->row('families', $this->t->id, $family)->profile)['coaching_by_season']['spring-2027']['volunteer'])->toBeFalse();
});

it('keeps the original obligation and capacity when Stripe is uncertain', function () {
    [$family, $input, $invoice] = amendFamily($this, 1);
    $this->store->query('invoices', $this->t->id)->where('id', $invoice->id)->update(['checkout_id' => 'cs_amend_test']);
    Http::fake(['api.stripe.com/*' => Http::response([], 503)]);
    $input = amendReviewed($this, $family, amendCoaching($input));
    expect(fn () => $this->applications->amend($this->t->id, $family, $input))->toThrow(HttpException::class, 'could not be verified');
    expect($this->store->row('invoices', $this->t->id, $invoice->id)->status)->toBe('unpaid')
        ->and($this->store->query('registrations', $this->t->id)->sole()->status)->toBe('offered')
        ->and($this->store->query('invoices', $this->t->id)->count())->toBe(1);
});

it('does not collect payment for a fully waived amendment', function () {
    [$family, $input] = amendFamily($this, 2);
    $result = $this->applications->amend($this->t->id, $family, amendReviewed($this, $family, amendCoaching($input)));
    expect($this->store->row('invoices', $this->t->id, $result['invoice_id'])->status)->toBe('waived')
        ->and($this->store->query('registrations', $this->t->id)->where('status', 'registered')->count())->toBe(2)
        ->and($this->store->query('payments', $this->t->id)->count())->toBe(0);
});

it('rejects an unreviewed new total and changes from another session', function () {
    [$family, $input] = amendFamily($this, 1);
    $reviewed = amendReviewed($this, $family, $input);
    expect(fn () => $this->applications->amend($this->t->id, $family, [...$reviewed, 'expected_due_today_cents' => 1]))->toThrow(HttpException::class, 'updated total');
    $this->store->query('registrations', $this->t->id)->where('family_id', $family)->update(['jersey_size' => 'Youth S']);
    expect(fn () => $this->applications->amend($this->t->id, $family, $reviewed))->toThrow(HttpException::class, 'another session');
    expect($this->store->query('invoices', $this->t->id)->count())->toBe(1);
});

it('rechecks capacity after an expired application and never overbooks', function () {
    $teams = config('soccer.teams');
    $teams['blue-dragons']['public'] = 1;
    $teams['blue-dragons']['reserved'] = 0;
    $this->store->query('seasons', $this->t->id)->where('id', $this->season)->update(['teams' => json_encode($teams)]);
    [$family, $input] = amendFamily($this, 1);
    $this->travel(31)->minutes();
    $this->registration->tick($this->t->id, $this->season);
    $reviewed = amendReviewed($this, $family, $input);
    amendFamily($this, 1);
    expect(fn () => $this->applications->amend($this->t->id, $family, $reviewed))->toThrow(HttpException::class, 'Spots or fees changed');
    $result = $this->applications->amend($this->t->id, $family, amendReviewed($this, $family, $input));
    expect($result)->toBe(['invoice_id' => null, 'offered' => 0, 'waiting' => 1])
        ->and($this->store->query('registrations', $this->t->id)->where('status', 'offered')->count())->toBe(1);
});

it('rejects incomplete selections foreign-family players and recorded receipts', function () {
    [$family, $input, $invoice] = amendFamily($this, 2);
    [$other, $otherInput] = amendFamily($this, 1);
    expect(fn () => $this->applications->quote($this->t->id, $family, [...$input, 'children' => [$input['children'][0]]]))->toThrow(HttpException::class, 'saved players changed');
    expect(fn () => $this->applications->quote($this->t->id, $family, $otherInput))->toThrow(HttpException::class, 'saved players changed');
    $this->store->insert('payments', $this->t->id, ['invoice_id' => $invoice->id, 'provider' => 'venmo', 'provider_key' => 'fictional-receipt', 'amount_cents' => 6000, 'evidence' => $this->store->encrypt([])]);
    expect(fn () => $this->applications->quote($this->t->id, $family, $input))->toThrow(HttpException::class, 'payment is recorded');
});

it('does not silently replace another device’s edits when refreshing the review quote', function () {
    [$family, $input] = amendFamily($this, 1);
    $reviewed = amendReviewed($this, $family, $input);
    $this->store->query('registrations', $this->t->id)->where('family_id', $family)->update(['jersey_size' => 'Youth L']);
    expect(fn () => $this->applications->quote($this->t->id, $family, $reviewed))->toThrow(HttpException::class, 'another session');
});

it('uses the verified family rather than a supplied family ID for amendment requests', function () {
    [$family, $input] = amendFamily($this, 1);
    [$other] = amendFamily($this, 1);
    $otherBefore = $this->store->query('registrations', $this->t->id)->where('family_id', $other)->sole();
    config()->set('soccer.bridge_secret', str_repeat('k', 40));
    $token = Str::random(48);
    $this->store->insert('sessions', $this->t->id, ['family_id' => $family, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
    $path = '/api/soccer/portal/amend';
    $body = json_encode([...amendReviewed($this, $family, amendCoaching($input)), 'family_id' => $other]);
    $timestamp = (string) time();
    $nonce = (string) Str::uuid();
    $signature = hash_hmac('sha256', "POST\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body), config('soccer.bridge_secret'));
    $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_WREN_SESSION' => $token,
        'HTTP_X_WREN_TIME' => $timestamp, 'HTTP_X_WREN_NONCE' => $nonce, 'HTTP_X_WREN_SIGNATURE' => $signature], $body)
        ->assertOk()->assertJsonPath('family.id', $family)->assertJsonPath('amendment.offered', 1)
        ->assertJsonPath('family.profile.coaching_by_season.spring-2027.volunteer', true);
    expect($this->store->query('registrations', $this->t->id)->where('family_id', $other)->sole())->toEqual($otherBefore);
});
