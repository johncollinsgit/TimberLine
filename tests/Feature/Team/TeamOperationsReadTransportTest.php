<?php

use App\Models\TeamProviderConnection;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Operations\OperationsProviders;
use App\Services\Team\Operations\OperationsReadTransport;
use App\Services\Team\Operations\OperationsSync;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

function readTransportConnection($test, string $token, string $provider = 'asana'): TeamProviderConnection
{
    return TeamProviderConnection::create(['id' => Str::uuid(), 'tenant_id' => $test->tenant->id, 'user_id' => $test->owner->id, 'area_id' => $test->area->id,
        'provider' => $provider, 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional source', 'status' => 'connected', 'generation' => 1,
        'expires_at' => now()->addHour(), 'credentials' => ['access_token' => $token, 'refresh_token' => 'fictional-refresh'],
        'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
}

function readTransportStatus(callable $read): int
{
    try {
        $read();

        return 200;
    } catch (HttpException $error) {
        return $error->getStatusCode();
    }
}

beforeEach(function () {
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    Http::preventStrayRequests();
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    $this->connection = readTransportConnection($this, 'fictional-first');
});

test('read transport is shared only inside the container request or job scope', function () {
    $first = app(OperationsReadTransport::class);
    expect(app(OperationsReadTransport::class))->toBe($first);
    app()->forgetScopedInstances();
    expect(app(OperationsReadTransport::class))->not->toBe($first);
});

test('Laravel fakes and request middleware still intercept the shared low level handler', function () {
    $networkCalls = 0;
    app()->instance(OperationsReadTransport::class, function () use (&$networkCalls) {
        $networkCalls++;
        throw new RuntimeException('The real handler must not run under Http::fake.');
    });
    $middlewareCalls = 0;
    Http::globalRequestMiddleware(function ($request) use (&$middlewareCalls) {
        $middlewareCalls++;

        return $request->withHeader('X-Fictional-Middleware', 'present');
    });
    Http::fake(function ($request, $options) {
        expect($request->header('X-Fictional-Middleware'))->toBe(['present'])
            ->and($options['cookies'])->toBeFalse()->and($options['connect_timeout'])->toBe(5)->and($options['timeout'])->toBe(25);

        return Http::response(['data' => ['value' => 'fixture']]);
    });
    expect(app(OperationsProviders::class)->asana($this->connection, 'projects/200')['data']['value'])->toBe('fixture');
    expect($networkCalls)->toBe(0)->and($middlewareCalls)->toBe(1);
    Http::assertSentCount(1);
});

test('independent accounts and providers always send their own current token on the same transport', function () {
    $second = readTransportConnection($this, 'fictional-second');
    $google = readTransportConnection($this, 'fictional-google', 'sheets');
    $seen = [];
    Http::fake(function ($request) use (&$seen) {
        $seen[] = [$request->header('Authorization'), $request->header('Cookie')];

        return Http::response(['data' => []], 200, ['Set-Cookie' => 'fixture=must-not-persist']);
    });
    $service = app(OperationsProviders::class);
    $shared = app(OperationsReadTransport::class);
    $service->asana($this->connection, 'projects/200');
    $service->asana($second, 'projects/200');
    $service->http($google, 'GET', 'https://sheets.googleapis.com/v4/spreadsheets/fixture');
    $this->connection->fresh()->update(['credentials' => ['access_token' => 'fictional-rotated', 'refresh_token' => 'fictional-refresh']]);
    $service->asana($this->connection, 'projects/200');
    expect(app(OperationsReadTransport::class))->toBe($shared)->and($seen)->toBe([
        [['Bearer fictional-first'], []], [['Bearer fictional-second'], []], [['Bearer fictional-google'], []], [['Bearer fictional-rotated'], []],
    ]);
    Http::assertSentCount(4);
});

test('renewal and late connection revocation remain fresh with a reused transport', function () {
    config(['team_work.asana.client_id' => 'fixture-client', 'team_work.asana.client_secret' => 'fixture-secret']);
    $this->connection->update(['expires_at' => now()->subMinute()]);
    Http::fake([
        'https://app.asana.com/-/oauth_token' => Http::response(['access_token' => 'fictional-renewed', 'refresh_token' => 'fictional-next-refresh', 'expires_in' => 3600]),
        'https://app.asana.com/api/1.0/*' => Http::response(['data' => []]),
    ]);
    $service = app(OperationsProviders::class);
    $service->asana($this->connection, 'projects/200');
    Http::assertSent(fn ($request) => $request->url() === 'https://app.asana.com/api/1.0/projects/200' && $request->hasHeader('Authorization', 'Bearer fictional-renewed'));
    Http::fake(function () {
        $this->connection->fresh()->update(['generation' => 2]);

        return Http::response(['data' => ['private' => 'must-be-discarded']]);
    });
    expect(readTransportStatus(fn () => $service->asana($this->connection, 'projects/200')))->toBe(409)
        ->and(readTransportStatus(fn () => $service->asana($this->connection, 'projects/200')))->toBe(409);
    Http::assertSentCount(1);
});

test('cooldowns host checks and retryable provider failures cannot be bypassed by a warm transport', function () {
    $service = app(OperationsProviders::class);
    Http::fakeSequence()->push(['data' => []])->push([], 429, ['Retry-After' => '60'])->push([], 503)->push(['data' => ['recovered' => true]]);
    $service->asana($this->connection, 'projects/200');
    expect(readTransportStatus(fn () => $service->asana($this->connection, 'projects/200')))->toBe(429)
        ->and(readTransportStatus(fn () => $service->asana($this->connection, 'projects/200')))->toBe(429)
        ->and(readTransportStatus(fn () => $service->http($this->connection, 'GET', 'https://untrusted.invalid/source')))->toBe(422);
    Http::assertSentCount(2);
    $this->connection->update(['retry_at' => null]);
    expect(readTransportStatus(fn () => $service->asana($this->connection, 'projects/200')))->toBe(502)
        ->and($service->asana($this->connection, 'projects/200')['data']['recovered'])->toBeTrue();
});

test('warm source reads still require the current actor and exact tenant connection', function () {
    Http::fake(['*' => Http::response(['data' => ['gid' => '300', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]]])]);
    $record = app(OperationsSync::class)->upsert($this->connection, 'event', '300', 'Fictional event', 'source', ['source_id' => '300', 'source_project' => '200', 'source_workspace' => '100']);
    $access = app(OperationsAccess::class);
    $access->record($this->tenant, $this->owner, $record);
    [$foreign, $foreignOwner] = teamWorkspace();
    expect(fn () => $access->connection($foreign, $foreignOwner, $this->connection->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $this->tenant->users()->updateExistingPivot($this->owner->id, ['membership_active' => false]);
    expect(readTransportStatus(fn () => $access->record($this->tenant, $this->owner, $record)))->toBe(403);
    Http::assertSentCount(1);
});
