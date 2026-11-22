<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\Soccer\SoccerStore;
use App\Services\Watershed\WatershedAuth;
use App\Services\Watershed\WatershedPlacement;
use App\Services\Watershed\WatershedRegistration;
use App\Services\Watershed\WatershedStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    config(['watershed.enabled' => true, 'soccer.enabled' => true, 'watershed.bridge_secret' => str_repeat('k', 40), 'logging.default' => 'null']);
    $this->tenant = Tenant::create(['name' => 'Fictional Wren', 'slug' => 'wren-family-soccer']);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct', 'metadata' => ['workspace_focus' => 'soccer']]);
    TenantModuleState::create(['tenant_id' => $this->tenant->id, 'module_key' => 'soccer', 'enabled_override' => true]);
    $this->s = app(WatershedStore::class);
    $this->shared = app(SoccerStore::class);
    $this->svc = app(WatershedRegistration::class);
    $this->term = $this->s->insert('terms', $this->tenant->id, ['term_key' => 'spring-2027', 'name' => 'Spring 2027', 'school_year' => 2026, 'registration_open' => true]);
    $this->fid = $this->shared->insert('families', $this->tenant->id, ['email' => 'fictional@example.com', 'profile' => $this->shared->encrypt(['parentName' => 'Example Parent', 'phone' => '+18645550123', 'emergencyContact' => 'Example Contact', 'emergencyPhone' => '+18645550124'])]);
    $this->family = $this->shared->row('families', $this->tenant->id, $this->fid);
    $this->pid = $this->shared->insert('players', $this->tenant->id, ['family_id' => $this->fid, 'name' => 'Example Child', 'birthday' => '2022-09-01', 'profile' => $this->shared->encrypt([])]);
    $this->submission = ['term_id' => $this->term, 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'teaching' => true, 'medicalReviewed' => true, 'guardianAccepted' => true, 'releaseAccepted' => true, 'guardianSignature' => 'Example Parent', 'releaseSignature' => 'Example Parent', 'children' => [['player_id' => $this->pid, 'grade' => -1, 'class' => 'kinder', 'reason' => '', 'medical' => ['allergies' => 'no', 'epipen' => 'no']]]];
    Mail::fake();
    Http::preventStrayRequests();
});
it('uses inclusive September 1 entry boundaries and grows the oldest class annually', function () {
    $p = app(WatershedPlacement::class);
    foreach (['2023-09-02' => [-2, 'nursery'], '2023-09-01' => [-1, 'kinder'], '2021-09-02' => [-1, 'kinder'], '2021-09-01' => [0, 'kinder'], '2020-09-01' => [1, 'lower'], '2017-09-01' => [4, 'upper'], '2015-09-01' => [6, 'upper']] as $dob => $expected) {
        $a = $p->assess($dob, 2026);
        expect([$a['grade'], $a['class']])->toBe($expected);
    }
    expect($p->assess('2014-09-01', 2026)['eligible'])->toBeFalse()->and($p->assess('2014-09-01', 2027)['eligible'])->toBeFalse()->and($p->assess('2015-09-01', 2027)['grade'])->toBe(7)->and($p->maximum(2027))->toBe(7);
});
it('holds new families without an invoice and preserves immutable semester releases', function () {
    $id = $this->svc->submit($this->tenant->id, $this->family, $this->submission);
    expect($this->s->row('applications', $this->tenant->id, $id)->status)->toBe('pending')->and($this->s->query('invoices', $this->tenant->id)->count())->toBe(0);
    $release = $this->s->query('releases', $this->tenant->id)->sole();
    expect($this->s->decrypt($release->answers)['guardianSignature'])->toBe('Example Parent')->and($this->shared->query('releases', $this->tenant->id)->count())->toBe(0);
    expect($this->svc->submit($this->tenant->id, $this->family, $this->submission))->toBe($id)->and($this->s->query('releases', $this->tenant->id)->count())->toBe(1);
});
it('automatically accepts complete preapproved families and prices Kinder at 75 dollars', function () {
    $this->svc->preapprove($this->tenant->id, $this->fid, true, 1);
    $id = $this->svc->submit($this->tenant->id, $this->family, $this->submission);
    expect($this->s->row('applications', $this->tenant->id, $id)->status)->toBe('accepted')->and($this->s->query('invoices', $this->tenant->id)->sole()->amount_cents)->toBe(7500)->and($this->svc->profile($this->tenant->id, $this->family)['applications']->first()['releases'])->toHaveCount(1);
});
it('holds a younger child requested into Kinder until leadership resolves the placement and charges by class', function () {
    $this->shared->query('players', $this->tenant->id)->where('id', $this->pid)->update(['birthday' => '2024-01-01']);
    $this->svc->preapprove($this->tenant->id, $this->fid, true, 1);
    $this->submission['children'][0]['reason'] = 'Ready to participate with older siblings.';
    $id = $this->svc->submit($this->tenant->id, $this->family, $this->submission);
    expect($this->s->row('applications', $this->tenant->id, $id)->status)->toBe('pending');
    $this->svc->decision($this->tenant->id, $id, 1, 'approve-placement', $this->pid, 'Reviewed with parent.', 1);
    $this->svc->decision($this->tenant->id, $id, 2, 'accept', null, null, 1);
    expect($this->s->row('applications', $this->tenant->id, $id)->amount_cents)->toBe(7500)->and($this->s->query('adjustments', $this->tenant->id)->count())->toBe(1);
});
it('requires a reason and prevents edited grade fields from bypassing the maximum', function () {
    $this->submission['children'][0]['grade'] = 1;
    $this->submission['children'][0]['class'] = 'lower';
    expect(fn () => $this->svc->submit($this->tenant->id, $this->family, $this->submission))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->shared->query('players', $this->tenant->id)->where('id', $this->pid)->update(['birthday' => '2014-09-01']);
    $this->submission['children'][0]['reason'] = 'Please place lower';
    expect(fn () => $this->svc->submit($this->tenant->id, $this->family, $this->submission))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
it('completes free Nursery registrations without a payment attempt', function () {
    $this->shared->query('players', $this->tenant->id)->where('id', $this->pid)->update(['birthday' => '2024-01-01']);
    $this->submission['children'][0]['grade'] = -2;
    $this->submission['children'][0]['class'] = 'nursery';
    $this->svc->preapprove($this->tenant->id, $this->fid, true, 1);
    $id = $this->svc->submit($this->tenant->id, $this->family, $this->submission);
    expect($this->s->row('applications', $this->tenant->id, $id)->status)->toBe('registered')->and($this->s->query('invoices', $this->tenant->id)->count())->toBe(0);
});
it('requires parent review after a placement is declined and rejects stale decisions', function () {
    $this->submission['children'][0]['grade'] = 1;
    $this->submission['children'][0]['class'] = 'lower';
    $this->submission['children'][0]['reason'] = 'Special request';
    $id = $this->svc->submit($this->tenant->id, $this->family, $this->submission);
    $this->svc->decision($this->tenant->id, $id, 1, 'decline-placement', $this->pid, 'Please review the suggested class.', 1);
    expect($this->s->row('applications', $this->tenant->id, $id)->status)->toBe('needs_parent_review')->and($this->s->query('invoices', $this->tenant->id)->count())->toBe(0);
    expect(fn () => $this->svc->decision($this->tenant->id, $id, 1, 'accept', null, null, 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
it('reuses approvals within the year but signs fresh releases each semester', function () {
    $this->submission['children'][0]['grade'] = 1;
    $this->submission['children'][0]['class'] = 'lower';
    $this->submission['children'][0]['reason'] = 'Request';
    $id = $this->svc->submit($this->tenant->id, $this->family, $this->submission);
    $this->svc->decision($this->tenant->id, $id, 1, 'approve-placement', $this->pid, null, 1);
    $this->svc->preapprove($this->tenant->id, $this->fid, true, 1);
    $term = $this->s->insert('terms', $this->tenant->id, ['term_key' => 'second-test', 'name' => 'Second Semester', 'school_year' => 2026, 'registration_open' => true]);
    $this->submission['term_id'] = $term;
    $this->submission['request_id'] = (string) Str::uuid();
    $next = $this->svc->submit($this->tenant->id, $this->family, $this->submission);
    expect($this->s->row('applications', $this->tenant->id, $next)->status)->toBe('accepted')->and($this->s->query('releases', $this->tenant->id)->count())->toBe(2);
});
it('does not change Wren SMS consent while saving shared contacts', function () {
    $this->shared->query('families', $this->tenant->id)->where('id', $this->fid)->update(['sms_consent_at' => '2026-01-01 00:00:00']);
    $this->svc->contact($this->tenant->id, $this->family, ['parentName' => 'Parent', 'phone' => '+18645550123', 'emergencyContact' => 'Contact', 'emergencyPhone' => '+18645550124']);
    expect($this->shared->row('families', $this->tenant->id, $this->fid)->sms_consent_at)->not->toBeNull();
});
it('reuses verified family identity but isolates Watershed challenges and sessions', function () {
    $auth = app(WatershedAuth::class);
    $auth->code($this->tenant->id, 'fictional@example.com');
    Mail::assertSent(\App\Mail\WatershedMail::class, function ($mail) use ($auth) {
        $token = $auth->verify($this->tenant->id, 'fictional@example.com', $mail->code);
        expect($auth->family($this->tenant->id, $token)->id)->toBe($this->fid);

        return true;
    });
    expect($this->shared->query('families', $this->tenant->id)->count())->toBe(1)->and($this->shared->query('sessions', $this->tenant->id)->count())->toBe(0);
});
it('blocks unsigned bridge traffic and requires authenticated household ownership', function () {
    $this->getJson('/api/watershed/portal/profile')->assertUnauthorized();
    $other = $this->shared->insert('families', $this->tenant->id, ['email' => 'another@example.com', 'profile' => $this->shared->encrypt([])]);
    expect(fn () => $this->svc->child($this->tenant->id, $this->shared->row('families', $this->tenant->id, $other), ['id' => $this->pid, 'name' => 'Changed', 'birthday' => '2022-09-01']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
it('allows only the named active tenant leaders to administer Watershed', function () {
    $leader = User::factory()->create(['email' => config('watershed.leaders')[0], 'is_active' => true, 'email_verified_at' => now(), 'approved_at' => now(), 'role' => 'admin']);
    $leader->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    $this->actingAs($leader)->get('/watershed?tenant=wren-family-soccer')->assertOk()->assertSeeText('Family preapproval');
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now(), 'role' => 'admin']);
    $other->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    $this->actingAs($other)->get('/watershed?tenant=wren-family-soccer')->assertForbidden();
});
it('exports the immutable signed snapshot for the church renderer without losing long notes', function () {
    $notes = str_repeat('Detailed care instructions. ', 65);
    $this->submission['children'][0]['medical']['comments'] = $notes;
    $this->svc->submit($this->tenant->id, $this->family, $this->submission);
    $release = $this->s->query('releases', $this->tenant->id)->sole();
    $this->shared->query('players', $this->tenant->id)->where('id', $this->pid)->update(['name' => 'Later name']);
    $response = app(\App\Services\Watershed\WatershedReleaseExport::class)->response($release);
    $data = $response->getData(true);
    expect($data['playerName'])->toBe('Example Child')->and($data['answers']['comments'])->toBe($notes)
        ->and($data['answers']['guardianSignature'])->toBe('Example Parent')->and($data['season_key'])->toBe('spring-2027')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('prefills medical answers from both Wren seasons while keeping canonical edits and pickup phones', function () {
    foreach (['fall-2026' => '2026-08-01', 'spring-2027' => '2026-11-01'] as $season => $date) {
        $this->shared->insert('releases', $this->tenant->id, ['family_id' => $this->fid, 'player_id' => $this->pid, 'season_key' => $season, 'source_key' => (string) Str::uuid(), 'form_version' => 'fixture', 'answers' => $this->shared->encrypt(['allergies' => 'yes', 'allergenList' => $season]), 'sha256' => hash('sha256', $season), 'signed_at' => $date]);
    }
    $this->shared->query('players', $this->tenant->id)->where('id', $this->pid)->update(['profile' => $this->shared->encrypt(['allergenList' => 'Parent reviewed details'])]);
    $this->svc->contact($this->tenant->id, $this->family, [...$this->shared->decrypt($this->family->profile), 'pickupPhone' => '8645550125']);
    $profile = $this->svc->profile($this->tenant->id, $this->shared->row('families', $this->tenant->id, $this->fid));
    expect($profile['children']->first()['medical'])->toBe(['allergies' => 'yes', 'allergenList' => 'Parent reviewed details'])
        ->and($profile['family']['profile']['pickupPhone'])->toBe('8645550125');
});

it('requires an unexpired signed export link and rechecks current leadership access', function () {
    $this->svc->submit($this->tenant->id, $this->family, $this->submission);
    $id = $this->s->query('releases', $this->tenant->id)->sole()->id;
    $leader = User::factory()->create(['email' => config('watershed.leaders')[0], 'is_active' => true, 'email_verified_at' => now()]);
    $leader->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('watershed.release-data', now()->addMinutes(5), ['id' => $id, 'actor' => $leader->id], absolute: false);
    $this->getJson('/api/watershed/release-data/'.$id.'?actor='.$leader->id)->assertForbidden();
    $this->getJson($url)->assertOk()->assertJsonPath('playerName', 'Example Child');
    $this->getJson($url.'&tenant=unrelated')->assertForbidden();
    $leader->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $this->getJson($url)->assertForbidden();
    $leader->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => true]);
    $this->travel(6)->minutes();
    $this->getJson($url)->assertForbidden();
});

it('keeps parent release exports within the authenticated household', function () {
    $this->svc->submit($this->tenant->id, $this->family, $this->submission);
    $id = $this->s->query('releases', $this->tenant->id)->sole()->id;
    $token = 'fictional-export-session';
    $session = $this->s->insert('sessions', $this->tenant->id, ['family_id' => $this->fid, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);
    $request = \Illuminate\Http\Request::create('/api/watershed/portal/release', 'POST', ['release_id' => $id]);
    $request->headers->set('X-Watershed-Session', $token);
    $request->attributes->set('watershed_tenant', $this->tenant);
    $controller = app(\App\Http\Controllers\Watershed\WatershedPortalController::class);
    expect($controller->handle($request, 'release')->getData(true)['playerName'])->toBe('Example Child');
    $other = $this->shared->insert('families', $this->tenant->id, ['email' => 'other@example.test', 'profile' => $this->shared->encrypt([])]);
    $this->s->query('sessions', $this->tenant->id)->where('id', $session)->update(['family_id' => $other]);
    expect(fn () => $controller->handle($request, 'release'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('rejects checkout before acceptance and across households', function () {
    $id = $this->svc->submit($this->tenant->id, $this->family, $this->submission);
    $invoice = $this->s->insert('invoices', $this->tenant->id, ['application_id' => $id, 'amount_cents' => 7500]);
    expect(fn () => app(\App\Services\Watershed\WatershedPayment::class)->checkout($this->tenant->id, 'another-family', $invoice))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

it('recovers uncertain checkout with the same frozen provider request and confirms payment only once', function () {
    config(['services.stripe.secret' => 'test-key']);
    $this->svc->preapprove($this->tenant->id, $this->fid, true, 1);
    $id = $this->svc->submit($this->tenant->id, $this->family, $this->submission);
    $invoice = $this->s->query('invoices', $this->tenant->id)->sole();
    $ready = Mockery::mock(\App\Services\Billing\TenantPaymentsReadinessService::class);
    $ready->shouldReceive('readinessFor')->andReturn(['ready' => true, 'account' => (object) ['provider_account_id' => 'acct_fixture']]);
    app()->instance(\App\Services\Billing\TenantPaymentsReadinessService::class, $ready);
    $attempts = [];
    Http::fake(function ($request) use (&$attempts, $invoice) {
        if ($request->method() === 'POST') {
            $attempts[] = ['key' => $request->header('Idempotency-Key'), 'body' => $request->data()];
            if (count($attempts) === 1) {
                throw new \Illuminate\Http\Client\ConnectionException('Synthetic timeout');
            }

            return Http::response(['id' => 'cs_fixture', 'url' => 'https://checkout.stripe.com/fixture']);
        }

        return Http::response(['id' => 'cs_fixture', 'payment_status' => 'paid', 'currency' => 'usd', 'amount_total' => 7500, 'metadata' => ['watershed_invoice_id' => $invoice->id], 'payment_intent' => ['id' => 'pi_fixture', 'status' => 'succeeded', 'on_behalf_of' => 'acct_fixture', 'transfer_data' => ['destination' => 'acct_fixture']]]);
    });
    $payments = app(\App\Services\Watershed\WatershedPayment::class);
    expect(fn () => $payments->checkout($this->tenant->id, $this->fid, $invoice->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->s->row('invoices', $this->tenant->id, $invoice->id)->status)->toBe('unknown');
    expect($payments->checkout($this->tenant->id, $this->fid, $invoice->id))->toBe('https://checkout.stripe.com/fixture')->and($attempts[0])->toBe($attempts[1]);
    $payments->reconcile($this->tenant->id, $invoice->id);
    $payments->reconcile($this->tenant->id, $invoice->id);
    expect($this->s->row('applications', $this->tenant->id, $id)->status)->toBe('registered')->and($this->s->query('events', $this->tenant->id)->where('action', 'payment_confirmed')->count())->toBe(1);
});

it('keeps mismatched payment evidence in review instead of confirming enrollment', function () {
    config(['services.stripe.secret' => 'test-key']);
    $this->svc->preapprove($this->tenant->id, $this->fid, true, 1);
    $id = $this->svc->submit($this->tenant->id, $this->family, $this->submission);
    $invoice = $this->s->query('invoices', $this->tenant->id)->sole();
    $this->s->query('invoices', $this->tenant->id)->where('id', $invoice->id)->update(['checkout_id' => 'cs_wrong', 'account_id' => 'acct_expected']);
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_wrong', 'payment_status' => 'paid', 'currency' => 'usd', 'amount_total' => 1, 'metadata' => ['watershed_invoice_id' => $invoice->id], 'payment_intent' => ['id' => 'pi_wrong', 'status' => 'succeeded', 'on_behalf_of' => 'acct_other', 'transfer_data' => ['destination' => 'acct_other']]])]);
    app(\App\Services\Watershed\WatershedPayment::class)->reconcile($this->tenant->id, $invoice->id);
    expect($this->s->row('invoices', $this->tenant->id, $invoice->id)->status)->toBe('review')->and($this->s->row('applications', $this->tenant->id, $id)->status)->toBe('accepted');
});
