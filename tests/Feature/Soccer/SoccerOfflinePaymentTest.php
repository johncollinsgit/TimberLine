<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\Soccer\SoccerPaymentService;
use App\Services\Soccer\SoccerRegistrationSummaryService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config()->set(['soccer.enabled' => true, 'soccer.notification_mode' => 'email', 'soccer.groupme_notifications_enabled' => false, 'soccer.admin_notification_emails' => ['organizer@example.com'], 'services.stripe.secret' => 'sk_test_offline_fixture_only']);
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Wren', 'slug' => 'wren-family-soccer']);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct', 'metadata' => ['workspace_focus' => 'soccer']]);
    TenantModuleState::create(['tenant_id' => $this->tenant->id, 'module_key' => 'soccer', 'enabled_override' => true]);
    $this->actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->store = app(SoccerStore::class);
    $this->payments = app(SoccerPaymentService::class);
    $this->season = $this->store->insert('seasons', $this->tenant->id, ['season_key' => 'spring-2027', 'name' => 'Spring 2027', 'teams' => json_encode(config('soccer.teams'))]);
    $this->family = $this->store->insert('families', $this->tenant->id, ['email' => 'parent@example.com', 'verified_at' => now(), 'profile' => $this->store->encrypt(['parentName' => 'Example Parent'])]);
    $this->group = (string) Str::uuid();
    $this->registrations = [];
    foreach (['Paying one', 'Paying two', 'Waived one', 'Waived two'] as $number => $name) {
        $player = $this->store->insert('players', $this->tenant->id, ['family_id' => $this->family, 'name' => $name, 'birthday' => '2020-01-01', 'profile' => $this->store->encrypt([])]);
        $waived = $number >= 2;
        $this->registrations[] = $this->store->insert('registrations', $this->tenant->id, ['season_id' => $this->season, 'family_id' => $this->family, 'player_id' => $player, 'team_key' => 'blue-dragons', 'status' => $waived ? 'registered' : 'offered', 'seat_kind' => 'public', 'offer_group' => $this->group, 'waived' => $waived, 'amount_cents' => $waived ? 0 : 3000, 'payment_status' => $waived ? 'waived' : 'unpaid', 'needs_jersey' => false, 'queued_at' => now(), 'offer_expires_at' => $waived ? null : now()->addDay()]);
    }
    $this->unregistered = $this->store->insert('players', $this->tenant->id, ['family_id' => $this->family, 'name' => 'Not enrolled', 'birthday' => '2020-01-01', 'profile' => $this->store->encrypt([])]);
    $this->invoice = $this->store->insert('invoices', $this->tenant->id, ['season_id' => $this->season, 'family_id' => $this->family, 'offer_group' => $this->group, 'registration_ids' => json_encode($this->registrations), 'amount_cents' => 6000, 'status' => 'unpaid', 'provider' => 'stripe', 'checkout_id' => 'cs_offline_fixture', 'expires_at' => now()->addDay()]);
    $this->session = ['id' => 'cs_offline_fixture', 'status' => 'open', 'payment_status' => 'unpaid', 'currency' => 'usd', 'amount_total' => 6000, 'metadata' => ['soccer_invoice_id' => $this->invoice, 'tenant_id' => (string) $this->tenant->id]];
});

function fakeOfflineCardExpiration($test): void
{
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::sequence()->push($test->session)->push([...$test->session, 'status' => 'expired'])->push([...$test->session, 'status' => 'expired'])]);
}

function existingOfflineVenmoReceipt($test): object
{
    $test->payments->paid($test->tenant->id, $test->invoice, 'venmo', 'fictional-legacy-transfer', 6000, ['verified_by' => 25]);

    return $test->store->query('payments', $test->tenant->id)->sole();
}

it('waives the remaining family fees once without inventing payments or enrolling an unregistered sibling', function () {
    fakeOfflineCardExpiration($this);
    $this->payments->waive($this->tenant->id, $this->invoice, 'Organizer family fee waiver', $this->actor->id, 6000);
    $this->payments->waive($this->tenant->id, $this->invoice, 'Replay', $this->actor->id, 6000);
    $invoice = $this->store->row('invoices', $this->tenant->id, $this->invoice);
    expect($invoice->status)->toBe('waived')->and((int) $invoice->amount_cents)->toBe(0)->and($invoice->paid_at)->toBeNull();
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(0)
        ->and($this->store->query('registrations', $this->tenant->id)->where('status', 'registered')->where('waived', true)->where('payment_status', 'waived')->count())->toBe(4)
        ->and($this->store->query('registrations', $this->tenant->id)->where('player_id', $this->unregistered)->count())->toBe(0);
    $event = $this->store->query('events', $this->tenant->id)->where('action', 'organizer_fee_waived')->sole();
    $evidence = $this->store->decrypt($event->data);
    expect($evidence['original_amount_cents'])->toBe(6000)->and($evidence['original_registrations'])->toHaveCount(4)
        ->and(collect($evidence['original_registrations'])->where('waived', true))->toHaveCount(2)->and($event->actor_id)->toBe($this->actor->id);
    $summary = app(SoccerRegistrationSummaryService::class)->forEvent($this->tenant->id, $event);
    expect($summary['headline'])->toBe('Registration fees waived')->and($summary['totals'])->toMatchArray(['paid' => 0, 'unpaid' => 0, 'waived' => 4, 'received_cents' => 0, 'net_cents' => 0]);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/expire'));
    Http::assertSentCount(3);
    expect($this->store->query('deliveries', $this->tenant->id)->count())->toBe(2);
});

it('records organizer attestation as such and deduplicates receipt and notifications', function () {
    fakeOfflineCardExpiration($this);
    expect($this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, null, 'Owner confirmed this family paid via Venmo', $this->actor->id))->toBe('paid');
    expect($this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, null, 'Replay', $this->actor->id))->toBe('paid');
    $receipt = $this->store->query('payments', $this->tenant->id)->sole();
    $evidence = $this->store->decrypt($receipt->evidence);
    expect($receipt->provider_key)->toBe('owner-attestation:'.$this->invoice)->and($evidence['transfer_reference'])->toBeNull()->and($evidence['verification_source'])->toBe('organizer_attestation');
    expect($this->store->query('registrations', $this->tenant->id)->where('payment_status', 'paid')->count())->toBe(2)
        ->and($this->store->query('registrations', $this->tenant->id)->where('payment_status', 'waived')->count())->toBe(2)
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'payment_confirmed')->count())->toBe(1);
    Http::assertSentCount(3);
});

it('attests an existing Venmo receipt after retiring its open card checkout without duplicating money places or notices', function () {
    $receipt = existingOfflineVenmoReceipt($this);
    $originalInvoice = (array) $this->store->row('invoices', $this->tenant->id, $this->invoice);
    $originalRegistrations = $this->store->query('registrations', $this->tenant->id)->orderBy('id')->get()->toJson();
    $deliveries = $this->store->query('deliveries', $this->tenant->id)->count();
    fakeOfflineCardExpiration($this);
    expect($this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, null, 'Owner verified the previously recorded receipt', $this->actor->id))->toBe('paid');
    expect($this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, null, 'Replay should preserve the original attestation', $this->actor->id))->toBe('paid');
    $saved = $this->store->row('payments', $this->tenant->id, $receipt->id);
    $evidence = $this->store->decrypt($saved->evidence);
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(1)
        ->and((int) $this->store->query('payments', $this->tenant->id)->sum('amount_cents'))->toBe(6000)
        ->and($saved->provider_key)->toBe($receipt->provider_key)->and($saved->created_at)->toBe($receipt->created_at)
        ->and($evidence['verified_by'])->toBe(25)->and($evidence['organizer_attestations'])->toHaveCount(1)
        ->and($evidence['organizer_attestations'][0])->toMatchArray(['verified_by' => $this->actor->id,
            'verification_source' => 'organizer_attestation', 'reason' => 'Owner verified the previously recorded receipt'])
        ->and((array) $this->store->row('invoices', $this->tenant->id, $this->invoice))->toBe($originalInvoice)
        ->and($this->store->query('registrations', $this->tenant->id)->orderBy('id')->get()->toJson())->toBe($originalRegistrations)
        ->and($this->store->query('deliveries', $this->tenant->id)->count())->toBe($deliveries)
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'payment_confirmed')->count())->toBe(1);
    $event = $this->store->query('events', $this->tenant->id)->where('action', 'venmo_existing_receipt_attested')->sole();
    expect($event->actor_id)->toBe($this->actor->id)
        ->and($this->store->decrypt($event->data))->toMatchArray(['payment_id' => $receipt->id, 'invoice_id' => $this->invoice,
            'retired_checkout_id' => 'cs_offline_fixture', 'amount_cents' => 6000]);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/expire'));
});

it('requires review for ambiguous existing Venmo receipts or refunds without manufacturing an attestation', function (string $case) {
    $receipt = existingOfflineVenmoReceipt($this);
    $amount = 6000;
    if ($case === 'invoice amount mismatch') {
        $amount = 5999;
    } elseif ($case === 'receipt amount mismatch') {
        $this->store->query('payments', $this->tenant->id)->where('id', $receipt->id)->update(['amount_cents' => 5999]);
    } elseif ($case === 'competing receipt') {
        $this->store->insert('payments', $this->tenant->id, ['invoice_id' => $this->invoice, 'provider' => 'stripe',
            'provider_key' => 'fictional-competing-receipt', 'amount_cents' => 6000, 'evidence' => $this->store->encrypt([])]);
    } elseif ($case === 'refund record') {
        $this->store->insert('refunds', $this->tenant->id, ['invoice_id' => $this->invoice, 'amount_cents' => 1,
            'status' => 'pending', 'evidence' => $this->store->encrypt(['payment_id' => $receipt->id])]);
    } else {
        $this->store->query('invoices', $this->tenant->id)->where('id', $this->invoice)->update(['refunded_cents' => 1]);
    }
    $payments = $this->store->query('payments', $this->tenant->id)->orderBy('id')->get()->toJson();
    $deliveries = $this->store->query('deliveries', $this->tenant->id)->count();
    expect($this->payments->confirmVenmo($this->tenant->id, $this->invoice, $amount, null, 'Owner confirmation requiring review', $this->actor->id))->toBe('review');
    expect($this->store->query('payments', $this->tenant->id)->orderBy('id')->get()->toJson())->toBe($payments)
        ->and($this->store->decrypt($this->store->row('payments', $this->tenant->id, $receipt->id)->evidence))->toBe(['verified_by' => 25])
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'venmo_existing_receipt_attested')->count())->toBe(0)
        ->and($this->store->query('deliveries', $this->tenant->id)->count())->toBe($deliveries);
    Http::assertNothingSent();
})->with(['invoice amount mismatch', 'receipt amount mismatch', 'competing receipt', 'refund record', 'refunded invoice']);

it('keeps the existing Venmo receipt unchanged when card checkout cannot be verified', function () {
    $receipt = existingOfflineVenmoReceipt($this);
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => fn () => throw new ConnectionException('Fictional uncertain card checkout')]);
    expect(fn () => $this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, null, 'Owner confirmed receipt', $this->actor->id))->toThrow(HttpException::class);
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(1)
        ->and($this->store->row('payments', $this->tenant->id, $receipt->id)->evidence)->toBe($receipt->evidence)
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'venmo_existing_receipt_attested')->count())->toBe(0);
});

it('commits a real competing Stripe receipt for review rather than attesting an already paid Venmo invoice', function () {
    $receipt = existingOfflineVenmoReceipt($this);
    $registrations = $this->store->query('registrations', $this->tenant->id)->orderBy('id')->get()->toJson();
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response([...$this->session, 'status' => 'complete',
        'payment_status' => 'paid', 'payment_intent' => 'pi_competing_existing_venmo'])]);
    expect($this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, null, 'Owner confirmed existing Venmo', $this->actor->id))->toBe('review');
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(2)
        ->and($this->store->row('payments', $this->tenant->id, $receipt->id)->evidence)->toBe($receipt->evidence)
        ->and($this->store->row('invoices', $this->tenant->id, $this->invoice)->provider)->toBe('venmo')
        ->and($this->store->query('registrations', $this->tenant->id)->orderBy('id')->get()->toJson())->toBe($registrations)
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'payment_confirmed')->count())->toBe(1)
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'payment_needs_review')->count())->toBe(1)
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'venmo_existing_receipt_attested')->count())->toBe(0);
});

it('rechecks ambiguity before replaying an earlier existing receipt attestation', function () {
    $receipt = existingOfflineVenmoReceipt($this);
    fakeOfflineCardExpiration($this);
    $this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, null, 'Owner confirmation', $this->actor->id);
    $evidence = $this->store->row('payments', $this->tenant->id, $receipt->id)->evidence;
    $this->store->insert('refunds', $this->tenant->id, ['invoice_id' => $this->invoice, 'amount_cents' => 1,
        'status' => 'succeeded', 'evidence' => $this->store->encrypt(['payment_id' => $receipt->id])]);
    expect($this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, null, 'Replay after refund', $this->actor->id))->toBe('review');
    expect($this->store->row('payments', $this->tenant->id, $receipt->id)->evidence)->toBe($evidence)
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'venmo_existing_receipt_attested')->count())->toBe(1);
    Http::assertSentCount(3);
});

it('preserves a competing paid card receipt and actual Venmo receipt for review without double confirming', function () {
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response([...$this->session, 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_competing'])]);
    expect($this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, 'genuine-reference', 'Verified in Venmo', $this->actor->id))->toBe('review');
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(2)
        ->and($this->store->row('invoices', $this->tenant->id, $this->invoice)->provider)->toBe('stripe')
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'payment_confirmed')->count())->toBe(1)
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'payment_needs_review')->count())->toBe(1);
});

it('commits card reconciliation when a competing card payment prevents a waiver', function () {
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response([...$this->session, 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_competing'])]);
    expect(fn () => $this->payments->waive($this->tenant->id, $this->invoice, 'Organizer waiver', $this->actor->id))->toThrow(HttpException::class, 'already received');
    expect($this->store->row('invoices', $this->tenant->id, $this->invoice)->status)->toBe('paid')
        ->and($this->store->query('payments', $this->tenant->id)->count())->toBe(1)
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'organizer_fee_waived')->count())->toBe(0);
});

it('fails closed on unknown card outcomes or a session belonging to another invoice', function (string $failure) {
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => function () use ($failure) {
        if ($failure === 'timeout') {
            throw new ConnectionException('Fictional timeout');
        }

        return Http::response([...$this->session, 'metadata' => ['soccer_invoice_id' => Str::uuid(), 'tenant_id' => (string) $this->tenant->id]]);
    }]);
    expect(fn () => $this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, null, 'Verified receipt', $this->actor->id))->toThrow(HttpException::class);
    expect($this->store->row('invoices', $this->tenant->id, $this->invoice)->status)->toBe('unpaid')->and($this->store->query('payments', $this->tenant->id)->count())->toBe(0);
})->with(['timeout', 'wrong identity']);

it('records late Venmo receipts for review without taking an expired place', function () {
    $this->store->query('invoices', $this->tenant->id)->where('id', $this->invoice)->update(['expires_at' => now()->subDay()]);
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response([...$this->session, 'status' => 'expired'])]);
    expect($this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, 'late-transfer', 'Actual late receipt verified', $this->actor->id))->toBe('review');
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(1)->and($this->store->query('registrations', $this->tenant->id)->where('status', 'offered')->count())->toBe(2);
});

it('requires a verified organizer from the same tenant for direct manual service calls', function () {
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    expect(fn () => $this->payments->waive($this->tenant->id, $this->invoice, 'No access', $stranger->id))->toThrow(HttpException::class);
    expect(fn () => $this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, null, 'No access', $stranger->id))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

it('restores an expired paid family only against one exact verified receipt without duplicating revenue', function () {
    $this->store->query('invoices', $this->tenant->id)->where('id', $this->invoice)->update(['status' => 'expired', 'expires_at' => now()->subDay(), 'checkout_id' => null]);
    $this->store->query('registrations', $this->tenant->id)->where('status', 'offered')->update(['status' => 'payment_pending', 'seat_kind' => null]);
    expect($this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, 'verified-transfer-id', 'Live Venmo transfer matched to this family', $this->actor->id))->toBe('review');
    expect($this->payments->restoreVenmoPlaces($this->tenant->id, $this->invoice, 6000, 'verified-transfer-id', 'Restore verified paid family', $this->actor->id))->toBe('paid');
    expect($this->payments->restoreVenmoPlaces($this->tenant->id, $this->invoice, 6000, 'verified-transfer-id', 'Replay', $this->actor->id))->toBe('paid');
    expect($this->store->query('payments', $this->tenant->id)->count())->toBe(1)
        ->and((int) $this->store->query('payments', $this->tenant->id)->sum('amount_cents'))->toBe(6000)
        ->and($this->store->query('registrations', $this->tenant->id)->where('status', 'registered')->count())->toBe(4)
        ->and($this->store->query('events', $this->tenant->id)->where('action', 'payment_confirmed')->count())->toBe(1);
    Http::assertNothingSent();
});

it('retains verified money for review if there is no capacity or another receipt', function (string $failure) {
    $this->store->query('invoices', $this->tenant->id)->where('id', $this->invoice)->update(['status' => 'expired', 'expires_at' => now()->subDay(), 'checkout_id' => null]);
    $this->store->query('registrations', $this->tenant->id)->where('status', 'offered')->update(['status' => 'payment_pending', 'seat_kind' => null]);
    $this->payments->confirmVenmo($this->tenant->id, $this->invoice, 6000, 'verified-transfer-id', 'Verified receipt', $this->actor->id);
    if ($failure === 'capacity') {
        $teams = config('soccer.teams');
        $teams['blue-dragons']['public'] = 0;
        $teams['blue-dragons']['reserved'] = 0;
        $this->store->query('seasons', $this->tenant->id)->where('id', $this->season)->update(['teams' => json_encode($teams)]);
    } else {
        $this->store->insert('payments', $this->tenant->id, ['invoice_id' => $this->invoice, 'provider' => 'stripe', 'provider_key' => 'competing', 'amount_cents' => 6000, 'evidence' => $this->store->encrypt([])]);
    }
    expect($this->payments->restoreVenmoPlaces($this->tenant->id, $this->invoice, 6000, 'verified-transfer-id', 'Review', $this->actor->id))->toBe('review')
        ->and($this->store->row('invoices', $this->tenant->id, $this->invoice)->status)->toBe('expired')
        ->and($this->store->query('registrations', $this->tenant->id)->where('status', 'payment_pending')->count())->toBe(2);
})->with(['capacity', 'competing receipt']);

it('previews the operator command without provider calls or record changes', function () {
    $this->artisan('soccer:reconcile-offline', ['invoice' => $this->invoice, '--actor' => $this->actor->id, '--action' => 'waive', '--amount-cents' => 6000, '--reason' => 'Owner-approved waiver', '--expected-family' => $this->family])->expectsOutput('Preview only. No provider calls, records, or messages were changed.')->assertSuccessful();
    expect($this->store->row('invoices', $this->tenant->id, $this->invoice)->status)->toBe('unpaid')->and($this->store->query('events', $this->tenant->id)->count())->toBe(0);
    Http::assertNothingSent();
});

it('audits only an own-family Venmo link click once without confirming payment or notifying anyone', function () {
    config()->set('soccer.bridge_secret', str_repeat('k', 40));
    $token = Str::random(48);
    $this->store->insert('sessions', $this->tenant->id, ['family_id' => $this->family, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
    $request = function (string $invoice) use ($token) {
        $path = '/api/soccer/portal/venmo-click';
        $body = json_encode(['invoice_id' => $invoice]);
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $signature = hash_hmac('sha256', "POST\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body), config('soccer.bridge_secret'));

        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WREN_SESSION' => $token, 'HTTP_X_WREN_TIME' => $timestamp, 'HTTP_X_WREN_NONCE' => $nonce, 'HTTP_X_WREN_SIGNATURE' => $signature], $body);
    };
    $request($this->invoice)->assertOk()->assertJsonPath('url', 'https://venmo.com/u/WrenFamilySoccer');
    $request($this->invoice)->assertOk();
    expect($this->store->query('events', $this->tenant->id)->where('action', 'venmo_payment_link_opened')->count())->toBe(1)
        ->and($this->store->query('payments', $this->tenant->id)->count())->toBe(0)
        ->and($this->store->query('deliveries', $this->tenant->id)->count())->toBe(0)
        ->and($this->store->row('invoices', $this->tenant->id, $this->invoice)->status)->toBe('unpaid');
    $other = $this->store->insert('invoices', $this->tenant->id, ['season_id' => $this->season, 'family_id' => Str::uuid(), 'offer_group' => Str::uuid(), 'registration_ids' => '[]', 'amount_cents' => 3000, 'status' => 'unpaid', 'expires_at' => now()->addDay()]);
    $request($other)->assertForbidden();
    $this->store->query('invoices', $this->tenant->id)->where('id', $this->invoice)->update(['status' => 'paid']);
    $request($this->invoice)->assertUnprocessable();
    Http::assertNothingSent();
});
