<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Integrations\QuickBooks\QuickBooksConnector;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $tenant = Tenant::create(['name' => 'Fictional credential company', 'slug' => 'fictional-credential-company']);
    $this->connection = IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'quickbooks', 'external_account_id' => 'fictional-company-id',
        'external_account_secret' => 'fictional-company-realm', 'status' => 'connected', 'access_token' => 'original-access', 'refresh_token' => 'original-refresh', 'expires_at' => now()->subMinute()]);
    $this->tokens = ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600];
    $this->duringOAuth = null;
    Http::preventStrayRequests();
    Http::fake(['https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => function () {
        if ($this->duringOAuth) {
            ($this->duringOAuth)();
        }

        return Http::response($this->tokens);
    }]);
});

test('ordinary connector callers renew and retain rotated credentials without financial provider writes', function (): void {
    $saved = app(QuickBooksConnector::class)->refresh($this->connection);
    expect($saved->access_token)->toBe('new-access')->and($saved->refresh_token)->toBe('new-refresh')->and($saved->expires_at->isFuture())->toBeTrue()
        ->and($saved->external_account_secret)->toBe('fictional-company-realm')->and($saved->status)->toBe('connected');
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer'
        && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'original-refresh' && ! isset($request['scope']));
    Http::assertSentCount(1);
});

test('a disconnection reconnect or newer credential version wins over an in flight OAuth response', function (string $field, mixed $value): void {
    $this->duringOAuth = fn () => IntegrationConnection::whereKey($this->connection->id)->firstOrFail()->update([$field => $value]);
    expect(fn () => app(QuickBooksConnector::class)->refresh($this->connection))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $saved = $this->connection->fresh();
    expect($saved->access_token)->not->toBe('new-access')->and($saved->refresh_token)->not->toBe('new-refresh');
    if ($field === 'expires_at') {
        expect($saved->expires_at->equalTo($value))->toBeTrue();
    } else {
        expect($saved->$field)->toBe($value);
    }
})->with([
    ['status', 'disconnected'], ['external_account_id', 'new-company-id'], ['external_account_secret', 'new-company-realm'],
    ['metadata', ['realm_id' => 'reconnected-fallback-realm']],
    ['access_token', 'concurrent-access'], ['refresh_token', 'concurrent-refresh'], ['expires_at', \Carbon\CarbonImmutable::parse('2030-01-01')],
]);

test('caller admission runs after OAuth and can reject credential persistence', function (): void {
    expect(fn () => app(QuickBooksConnector::class)->client($this->connection, function (): void {
        abort(403, 'Financial access changed.');
    }))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Financial access changed.');
    expect($this->connection->fresh()->access_token)->toBe('original-access')->and($this->connection->fresh()->refresh_token)->toBe('original-refresh');
    Http::assertSentCount(1);
});

test('malformed renewed credentials never replace usable stored source credentials', function (array $tokens): void {
    $this->tokens = $tokens;
    expect(fn () => app(QuickBooksConnector::class)->refresh($this->connection))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->connection->fresh()->access_token)->toBe('original-access')->and($this->connection->fresh()->refresh_token)->toBe('original-refresh');
})->with([[['access_token' => '', 'expires_in' => 3600]], [['access_token' => 'new-access', 'refresh_token' => '', 'expires_in' => 3600]], [['access_token' => 'new-access', 'expires_in' => -1]]]);
