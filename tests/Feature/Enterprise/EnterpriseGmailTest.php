<?php

use App\Enterprise\EmailDrafts;
use App\Enterprise\GmailConnections;
use App\Enterprise\GmailMailboxGateway;
use App\Enterprise\MailboxGateway;
use App\Models\TeamOperationRecord;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true, 'enterprise.writes_enabled' => true,
        'enterprise.ask_enabled' => true, 'enterprise.email_drafts_enabled' => true, 'app.url' => 'https://enterprise.example.invalid']);
    Route::middleware('web')->prefix('enterprise-gmail-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
    Http::preventStrayRequests();
});

function gmailFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    $owner->forceFill(['email' => 'synthetic-staff@campus.example.invalid'])->save();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id], 'enterprise.gmail' => ['connection_enabled' => true, 'send_enabled' => false,
        'client_id' => 'synthetic-client.apps.googleusercontent.com', 'client_secret' => 'synthetic-test-only-secret',
        'redirect_uri' => 'https://enterprise.example.invalid/enterprise/gmail/callback', 'tenant_allowlist' => [(string) $tenant->id]]]);
    $area = test()->actingAs($owner)->postJson(route('enterprise.commands', ['tenant' => $tenant->slug]), [
        'action' => 'workspace.create', 'title' => 'Synthetic Gmail department', 'visibility' => 'department_only',
        'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
    ])->assertOk()->json('id');

    return [$tenant, $owner, $area];
}

function gmailRequest($user, ?Store $session = null, array $query = []): Request
{
    if (! $session) {
        $session = new Store('enterprise-gmail-test', new ArraySessionHandler(120));
        $session->start();
    }
    $request = Request::create('https://enterprise.example.invalid/enterprise/gmail/callback', 'GET', $query);
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function gmailStart($tenant, $user, string $area): array
{
    $request = gmailRequest($user);
    $result = app(GmailConnections::class)->connect($tenant, $user, $area, $request);
    parse_str(parse_url($result['authorization_url'], PHP_URL_QUERY), $query);

    return [$request->session(), $query, $result];
}

function gmailTokens(array $override = []): array
{
    return array_replace(['access_token' => 'synthetic-access-token', 'refresh_token' => 'synthetic-refresh-token', 'token_type' => 'Bearer',
        'expires_in' => 3600, 'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/gmail.send'], $override);
}

function gmailFake($user, array $tokens = [], array $identity = []): void
{
    gmailHttp([GmailConnections::TOKEN_URL => Http::response(gmailTokens($tokens)),
        GmailConnections::USERINFO_URL => Http::response(array_replace(['sub' => 'synthetic-google-subject', 'email' => $user->email, 'email_verified' => true], $identity)),
        GmailConnections::REVOKE_URL => Http::response([], 200), GmailMailboxGateway::SEND_URL => Http::response(['id' => 'syntheticGoogleMessage'])]);
}

function gmailHttp(array|callable $handlers): void
{
    // Laravel appends fake handlers; each protocol phase gets a fresh bounded fake factory.
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake($handlers);
}

function gmailConnected($tenant, $user, string $area): array
{
    [$session, $query] = gmailStart($tenant, $user, $area);
    gmailFake($user);
    $result = app(GmailConnections::class)->callback(gmailRequest($user, $session, ['state' => $query['state'], 'code' => 'synthetic-code']));

    return [$result['connection_id'], $session, $query];
}

test('Gmail authorization freezes browser and PKCE context requests only send and identity scopes and creates no grants', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    [$session, $query] = gmailStart($tenant, $user, $area);
    expect($query['scope'])->toBe('openid email https://www.googleapis.com/auth/gmail.send')->and($query['include_granted_scopes'])->toBe('false')
        ->and($query['access_type'])->toBe('offline')->and($query['code_challenge_method'])->toBe('S256');
    $state = TeamOperationRecord::query()->where('kind', GmailConnections::STATE)->sole();
    expect($query['code_challenge'])->toBe(GmailConnections::base64url(hash('sha256', $state->data['verifier'], true)));
    expect(strlen($state->data['verifier']))->toBe(86)->and($session->get('enterprise.gmail_browser'))->toBeString();
    expect(DB::table('team_operation_records')->where('id', $state->id)->value('data'))->not->toContain($state->data['verifier']);
    expect(DB::table('tenant_user')->count())->toBe(1)->and(DB::table('team_area_members')->count())->toBe(0)->and(DB::table('tenant_mailbox_users')->count())->toBe(0);
    Http::assertNothingSent();
});

test('Gmail callback verifies own identity persists encrypted tokens consumes state once and never sends email', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    [$id, $session, $query] = gmailConnected($tenant, $user, $area);
    $connection = TeamOperationRecord::query()->findOrFail($id);
    expect($connection->status)->toBe('connected')->and($connection->data['subject'])->toBe('synthetic-google-subject');
    expect(DB::table('team_operation_records')->where('id', $id)->value('data'))->not->toContain('synthetic-access-token', 'synthetic-refresh-token');
    expect(TeamOperationRecord::query()->where('kind', GmailConnections::STATE)->sole()->data)->not->toHaveKey('verifier');
    expect(fn () => app(GmailConnections::class)->callback(gmailRequest($user, $session, ['state' => $query['state'], 'code' => 'synthetic-code'])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $status = app(GmailConnections::class)->status($tenant, $user, $area);
    expect($status['connected'])->toBeTrue()->and($status['send_enabled'])->toBeFalse()->and($status['address'])->toBe($user->email);
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => $request->url() === GmailMailboxGateway::SEND_URL);
});

test('OAuth state denies wrong user browser tenant department or origin before any Google request', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    [$session, $query] = gmailStart($tenant, $user, $area);
    $other = teamMember($tenant, 'admin');
    foreach ([gmailRequest($other, $session, ['state' => $query['state'], 'code' => 'synthetic']),
        gmailRequest($user, null, ['state' => $query['state'], 'code' => 'synthetic']),
        gmailRequest($user, $session, ['state' => $query['state'], 'code' => 'synthetic', 'tenant' => 'foreign-tenant']),
        gmailRequest($user, $session, ['state' => $query['state'], 'code' => 'synthetic', 'area_id' => (string) Str::uuid()]),
    ] as $request) {
        expect(fn () => app(GmailConnections::class)->callback($request))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }
    $wrongOrigin = gmailRequest($user, $session, ['state' => $query['state'], 'code' => 'synthetic']);
    $wrongOrigin->headers->set('Host', 'other.example.invalid');
    expect(fn () => app(GmailConnections::class)->callback($wrongOrigin))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(TeamOperationRecord::query()->where('kind', GmailConnections::STATE)->sole()->status)->toBe('pending');
    Http::assertNothingSent();
});

test('expired denied superseded or changed-client OAuth state never exchanges a code', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    [$session, $query] = gmailStart($tenant, $user, $area);
    $this->travel(11)->minutes();
    expect(fn () => app(GmailConnections::class)->callback(gmailRequest($user, $session, ['state' => $query['state'], 'code' => 'synthetic'])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->travelBack();
    [$session2, $query2] = gmailStart($tenant, $user, $area);
    expect(fn () => app(GmailConnections::class)->callback(gmailRequest($user, $session, ['state' => $query['state'], 'code' => 'synthetic'])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    config(['enterprise.gmail.client_id' => 'changed-client.apps.googleusercontent.com']);
    expect(fn () => app(GmailConnections::class)->callback(gmailRequest($user, $session2, ['state' => $query2['state'], 'code' => 'synthetic'])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    config(['enterprise.gmail.client_id' => 'synthetic-client.apps.googleusercontent.com']);
    $result = app(GmailConnections::class)->callback(gmailRequest($user, $session2, ['state' => $query2['state'], 'error' => 'access_denied']));
    expect($result['status'])->toBe('cancelled');
    Http::assertNothingSent();
});

test('missing refresh token broad scopes or mismatched unverified Google identity never binds a mailbox', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    foreach ([['refresh_token' => null], ['scope' => 'openid email https://www.googleapis.com/auth/gmail.modify'],
        ['identity' => ['email' => 'someone-else@example.invalid']], ['identity' => ['email_verified' => false]],
    ] as $variant) {
        [$session, $query] = gmailStart($tenant, $user, $area);
        $identity = $variant['identity'] ?? [];
        unset($variant['identity']);
        gmailFake($user, $variant, $identity);
        expect(fn () => app(GmailConnections::class)->callback(gmailRequest($user, $session, ['state' => $query['state'], 'code' => 'synthetic'])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        expect(app(GmailConnections::class)->mailboxes($tenant, $user, $area))->toBe([]);
        expect(TeamOperationRecord::query()->where('kind', GmailConnections::CONNECTION)->sole()->data)->not->toHaveKey('access_token');
    }
    expect(DB::table('tenant_mailbox_users')->count())->toBe(0);
    Http::assertNotSent(fn ($request) => $request->url() === GmailMailboxGateway::SEND_URL);
});

test('token exchange claims before IO and timeout closes the state without automatic replay', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    [$session, $query] = gmailStart($tenant, $user, $area);
    gmailHttp(function ($request) {
        expect(TeamOperationRecord::query()->where('kind', GmailConnections::STATE)->sole()->status)->toBe('exchanging');
        throw new \RuntimeException('Synthetic token timeout');
    });
    $request = gmailRequest($user, $session, ['state' => $query['state'], 'code' => 'synthetic']);
    expect(fn () => app(GmailConnections::class)->callback($request))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(TeamOperationRecord::query()->where('kind', GmailConnections::STATE)->sole()->status)->toBe('uncertain');
    expect(fn () => app(GmailConnections::class)->callback($request))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(TeamOperationRecord::query()->where('kind', GmailConnections::STATE)->sole()->data)->not->toHaveKey('verifier');
});

test('refresh serializes before IO rechecks subject and preserves the reviewed consent binding', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    [$id] = gmailConnected($tenant, $user, $area);
    $gateway = app(GmailMailboxGateway::class);
    $binding = $gateway->authorize($tenant, $user, $area, $id);
    $this->travel(61)->minutes();
    $concurrentRefused = false;
    gmailHttp([GmailConnections::TOKEN_URL => function () use ($tenant, $user, $area, $id, &$concurrentRefused) {
        expect(TeamOperationRecord::query()->findOrFail($id)->data)->toHaveKey('refresh_claim');
        try {
            app(GmailConnections::class)->accessToken($tenant, $user, $area, $id);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $concurrentRefused = $error->getStatusCode() === 409;
        }

        return Http::response(gmailTokens(['access_token' => 'synthetic-refreshed-token']));
    }, GmailConnections::USERINFO_URL => Http::response(['sub' => 'synthetic-google-subject', 'email' => $user->email, 'email_verified' => true])]);
    expect(app(GmailConnections::class)->accessToken($tenant, $user, $area, $id))->toBe('synthetic-refreshed-token');
    expect($concurrentRefused)->toBeTrue()->and($gateway->authorize($tenant, $user, $area, $id))->toBe($binding);
    expect(TeamOperationRecord::query()->findOrFail($id)->data)->not->toHaveKey('refresh_claim');
    Http::assertSentCount(2);
    $this->travelBack();
});

test('invalid grant uncertain refresh or changed identity invalidate old previews and require reconnect', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    foreach (['invalid_grant', 'timeout', 'changed_subject'] as $mode) {
        [$id] = gmailConnected($tenant, $user, $area);
        $this->travel(61)->minutes();
        if ($mode === 'invalid_grant') {
            gmailHttp([GmailConnections::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        } elseif ($mode === 'timeout') {
            gmailHttp([GmailConnections::TOKEN_URL => fn () => throw new \RuntimeException('Synthetic timeout')]);
        } else {
            gmailFake($user, [], ['sub' => 'changed-subject']);
        }
        expect(fn () => app(GmailConnections::class)->accessToken($tenant, $user, $area, $id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        expect(TeamOperationRecord::query()->findOrFail($id)->status)->toBe('reconnect_required');
        expect(TeamOperationRecord::query()->findOrFail($id)->data)->not->toHaveKeys(['access_token', 'refresh_token']);
        expect(app(GmailConnections::class)->mailboxes($tenant, $user, $area))->toBe([]);
        $this->travelBack();
    }
});

test('disconnect is local first even while paused and provider uncertainty cannot restore access', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    [$id] = gmailConnected($tenant, $user, $area);
    config(['enterprise.gmail.connection_enabled' => false, 'enterprise.writes_enabled' => false]);
    expect(app(GmailConnections::class)->status($tenant, $user, $area)['connection_id'])->toBe($id);
    gmailHttp([GmailConnections::REVOKE_URL => function () use ($id) {
        expect(TeamOperationRecord::query()->findOrFail($id)->status)->toBe('disconnected');
        expect(TeamOperationRecord::query()->findOrFail($id)->data)->not->toHaveKey('refresh_token');
        throw new \RuntimeException('Synthetic revocation timeout');
    }]);
    expect(app(GmailConnections::class)->disconnect($tenant, $user, $area, $id))->toBe(['status' => 'disconnected', 'provider_revocation' => 'uncertain']);
    expect(app(GmailConnections::class)->status($tenant, $user, $area)['connected'])->toBeFalse();
});

test('a stale callback or refresh never revokes or overwrites a newer successful consent', function (string $phase): void {
    [$tenant, $user, $area] = gmailFixture();
    if ($phase === 'refresh') {
        [$id] = gmailConnected($tenant, $user, $area);
        $this->travel(61)->minutes();
    } else {
        [$session, $query] = gmailStart($tenant, $user, $area);
        $id = TeamOperationRecord::query()->where('kind', GmailConnections::CONNECTION)->sole()->id;
    }
    $newConsent = false;
    gmailHttp([GmailConnections::TOKEN_URL => function ($request) use ($tenant, $user, $area, &$newConsent) {
        if (! $newConsent) {
            $newConsent = true;
            [$nextSession, $nextQuery] = gmailStart($tenant, $user, $area);
            app(GmailConnections::class)->callback(gmailRequest($user, $nextSession, ['state' => $nextQuery['state'], 'code' => 'synthetic-new-code']));

            return Http::response(gmailTokens(['access_token' => 'synthetic-stale-token']));
        }

        return Http::response(gmailTokens(['access_token' => 'synthetic-new-consent-token']));
    }, GmailConnections::USERINFO_URL => Http::response(['sub' => 'synthetic-google-subject', 'email' => $user->email, 'email_verified' => true])]);
    if ($phase === 'refresh') {
        expect(fn () => app(GmailConnections::class)->accessToken($tenant, $user, $area, $id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    } else {
        expect(fn () => app(GmailConnections::class)->callback(gmailRequest($user, $session, ['state' => $query['state'], 'code' => 'synthetic-old-code'])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }
    $current = TeamOperationRecord::query()->findOrFail($id);
    expect($current->status)->toBe('connected')->and($current->data['access_token'])->toBe('synthetic-new-consent-token');
    Http::assertNotSent(fn ($request) => $request->url() === GmailConnections::REVOKE_URL);
    $this->travelBack();
})->with(['callback', 'refresh']);

test('disconnect blocks reconnect during provider revocation and unresolved revocation cannot be blindly repeated', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    [$id] = gmailConnected($tenant, $user, $area);
    $reconnectRefused = false;
    gmailHttp([GmailConnections::REVOKE_URL => function () use ($tenant, $user, $area, &$reconnectRefused) {
        try {
            gmailStart($tenant, $user, $area);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $reconnectRefused = $error->getStatusCode() === 409;
        }

        return Http::response([], 503);
    }]);
    expect(app(GmailConnections::class)->disconnect($tenant, $user, $area, $id)['provider_revocation'])->toBe('uncertain');
    expect($reconnectRefused)->toBeTrue()->and(app(GmailConnections::class)->status($tenant, $user, $area)['can_connect'])->toBeFalse();
    expect(fn () => gmailStart($tenant, $user, $area))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(app(GmailConnections::class)->disconnect($tenant, $user, $area, $id)['provider_revocation'])->toBe('uncertain');
    Http::assertSentCount(1);
});

test('guest OAuth callbacks return private403 without preserving codes in login intended URLs', function (): void {
    $response = $this->get(route('enterprise.gmail.callback', ['code' => 'synthetic-sensitive-code', 'state' => 'synthetic-state']))
        ->assertForbidden()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeaderMissing('Location');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    expect(session()->has('url.intended'))->toBeFalse();
    Http::assertNothingSent();
});

test('role revocation or a fresh Enterprise email change prevents callback before token exchange', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    [$session, $query] = gmailStart($tenant, $user, $area);
    $user->forceFill(['email' => 'changed@example.invalid'])->save();
    expect(fn () => app(GmailConnections::class)->callback(gmailRequest($user, $session, ['state' => $query['state'], 'code' => 'synthetic'])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $user->forceFill(['email' => 'synthetic-staff@campus.example.invalid'])->save();
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $user->id])->update(['membership_active' => false]);
    expect(fn () => app(GmailConnections::class)->callback(gmailRequest($user, $session, ['state' => $query['state'], 'code' => 'synthetic'])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('Gmail send requires exact native draft confirmation then sends MIME once and replays without another call', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    [$id] = gmailConnected($tenant, $user, $area);
    config(['enterprise.gmail.send_enabled' => true]);
    app()->instance(MailboxGateway::class, app(GmailMailboxGateway::class));
    $drafts = app(EmailDrafts::class);
    $draft = $drafts->create($tenant, $user, ['area_id' => $area, 'mailbox_id' => $id, 'request_id' => (string) Str::uuid(),
        'to' => 'synthetic-recipient@example.invalid', 'subject' => 'Reviewed subject', 'body' => 'Reviewed body', 'source_refs' => []]);
    expect(fn () => $drafts->confirm($tenant, $user, $draft['id'], str_repeat('0', 64)))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    gmailHttp([GmailMailboxGateway::SEND_URL => function ($request) use ($draft, $user) {
        expect(TeamOperationRecord::query()->findOrFail($draft['id'])->status)->toBe('dispatching');
        expect(TeamOperationRecord::query()->where('kind', 'ent_gmail_send')->sole()->status)->toBe('sending');
        $raw = base64_decode(strtr($request['raw'], '-_', '+/'));
        expect($raw)->toContain('From: '.$user->email, 'To: synthetic-recipient@example.invalid', 'Subject: Reviewed subject', 'Reviewed body', $draft['id'].'@campus.example.invalid');

        return Http::response(['id' => 'syntheticGoogleAccepted']);
    }]);
    $accepted = $drafts->confirm($tenant, $user, $draft['id'], $draft['digest']);
    $replay = $drafts->confirm($tenant, $user, $draft['id'], $draft['digest']);
    expect($accepted['status'])->toBe('accepted')->and($accepted['provider_receipt'])->toBe('gmail:syntheticGoogleAccepted')->and($replay['replayed'])->toBeTrue();
    Http::assertSentCount(1);
    expect(DB::table('tenant_mailbox_users')->count())->toBe(0);
});

test('Gmail send gates cannot be bypassed by direct dispatch other users or stale consent', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    [$id] = gmailConnected($tenant, $user, $area);
    $gateway = app(GmailMailboxGateway::class);
    $binding = $gateway->authorize($tenant, $user, $area, $id);
    $other = teamMember($tenant, 'admin');
    expect(fn () => $gateway->authorize($tenant, $other, $area, $id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($gateway->readyFor($binding))->toBeFalse();
    config(['enterprise.gmail.send_enabled' => true]);
    expect(fn () => $gateway->dispatch($tenant, $user, $binding, ['to' => 'recipient@example.invalid', 'subject' => 'Unconfirmed', 'body' => 'Unconfirmed'], (string) Str::uuid()))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    gmailStart($tenant, $user, $area);
    expect($gateway->readyFor($binding))->toBeFalse();
    Http::assertSentCount(2);
});

test('uncertain Gmail dispatch preserves claimed receipts and refuses automatic retry', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    [$id] = gmailConnected($tenant, $user, $area);
    config(['enterprise.gmail.send_enabled' => true]);
    app()->instance(MailboxGateway::class, app(GmailMailboxGateway::class));
    $drafts = app(EmailDrafts::class);
    $draft = $drafts->create($tenant, $user, ['area_id' => $area, 'mailbox_id' => $id, 'request_id' => (string) Str::uuid(),
        'to' => 'recipient@example.invalid', 'subject' => 'Synthetic uncertain', 'body' => 'Synthetic uncertain', 'source_refs' => []]);
    gmailHttp([GmailMailboxGateway::SEND_URL => fn () => throw new \RuntimeException('Synthetic send timeout')]);
    expect($drafts->confirm($tenant, $user, $draft['id'], $draft['digest'])['status'])->toBe('uncertain');
    expect(TeamOperationRecord::query()->where('kind', 'ent_gmail_send')->sole()->status)->toBe('uncertain');
    expect(fn () => $drafts->confirm($tenant, $user, $draft['id'], $draft['digest']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('Gmail configuration rejects alternate callback host query credentials or path and status stays read-only', function (): void {
    [$tenant, $user, $area] = gmailFixture();
    foreach (['https://other.example.invalid/enterprise/gmail/callback', 'https://enterprise.example.invalid/enterprise/gmail/callback?tenant=one',
        'https://someone:secret@enterprise.example.invalid/enterprise/gmail/callback', 'http://enterprise.example.invalid/enterprise/gmail/callback',
    ] as $redirect) {
        config(['enterprise.gmail.redirect_uri' => $redirect]);
        expect(app(GmailConnections::class)->configured())->toBeFalse();
    }
    config(['enterprise.gmail.redirect_uri' => 'https://enterprise.example.invalid/enterprise/gmail/callback']);
    $viewer = teamMember($tenant, 'viewer');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $viewer->id, 'role' => 'viewer']);
    $status = app(GmailConnections::class)->status($tenant, $viewer, $area);
    expect($status['can_connect'])->toBeFalse()->and($status['address'])->toBeNull();
    Http::assertNothingSent();
});
