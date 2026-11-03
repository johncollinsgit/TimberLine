<?php

use App\Models\MobileAuthorizationCode;
use App\Models\User;
use App\Support\Auth\MobileAuthorizationClients;
use Illuminate\Support\Str;

function paymentsAuthParameters(string $verifier): array
{
    return [
        'client_id' => MobileAuthorizationClients::PAYMENTS_CLIENT,
        'redirect_uri' => MobileAuthorizationClients::PAYMENTS_REDIRECT,
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
        'state' => Str::random(32),
        'auth_method' => 'email',
    ];
}

function paymentsIssueAuthorization($test, User $user, string $verifier): array
{
    $parameters = paymentsAuthParameters($verifier);
    $response = $test->actingAs($user)->get('/mobile/authorize?'.http_build_query($parameters))->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $callback);
    expect($callback['state'])->toBe($parameters['state']);

    return [
        'client_id' => $parameters['client_id'],
        'redirect_uri' => $parameters['redirect_uri'],
        'code' => $callback['code'],
        'code_verifier' => $verifier,
    ];
}

test('payments pkce configuration is disabled by default before any code is issued', function (): void {
    expect(config('mobile_authorization.evergrove_payments_enabled'))->toBeFalse();
    $this->actingAs(User::factory()->create())->get('/mobile/authorize?'.http_build_query(paymentsAuthParameters(Str::random(64))))
        ->assertSessionHasErrors('client_id');
    expect(MobileAuthorizationCode::query()->count())->toBe(0);
});

test('mobile pkce accepts only complete exact client callback pairs', function (): void {
    config(['mobile_authorization.evergrove_payments_enabled' => true]);
    $user = User::factory()->create();
    foreach ([
        ['everbranch-mobile', MobileAuthorizationClients::PAYMENTS_REDIRECT],
        [MobileAuthorizationClients::PAYMENTS_CLIENT, 'everbranch://auth/callback'],
        [MobileAuthorizationClients::PAYMENTS_CLIENT, MobileAuthorizationClients::PAYMENTS_REDIRECT.'?next=https://example.test'],
        [MobileAuthorizationClients::PAYMENTS_CLIENT, 'https://example.test/auth/callback'],
    ] as [$client, $redirect]) {
        $params = array_replace(paymentsAuthParameters(Str::random(64)), ['client_id' => $client, 'redirect_uri' => $redirect]);
        $this->actingAs($user)->get('/mobile/authorize?'.http_build_query($params))->assertSessionHasErrors('client_id');
    }
    expect(MobileAuthorizationCode::query()->count())->toBe(0);
});

test('payments pkce exchanges once for explicit scoped abilities and preserves state', function (): void {
    config(['mobile_authorization.evergrove_payments_enabled' => true]);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $payload = paymentsIssueAuthorization($this, $user, Str::random(64));
    $this->postJson('/api/mobile/v1/auth/exchange', $payload)->assertOk();
    expect($user->tokens()->sole()->abilities)->toBe(['mobile:read', 'mobile:write', 'payments:read', 'payments:write']);
    $this->postJson('/api/mobile/v1/auth/exchange', $payload)->assertUnprocessable()->assertJsonValidationErrors('code');
    expect($user->tokens()->count())->toBe(1);
});

test('disabling payments pkce also blocks exchange of a previously issued code', function (): void {
    config(['mobile_authorization.evergrove_payments_enabled' => true]);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $payload = paymentsIssueAuthorization($this, $user, Str::random(64));
    config(['mobile_authorization.evergrove_payments_enabled' => false]);
    $this->postJson('/api/mobile/v1/auth/exchange', $payload)->assertUnprocessable()->assertJsonValidationErrors('client_id');
    expect($user->tokens()->count())->toBe(0)->and(MobileAuthorizationCode::query()->sole()->consumed_at)->toBeNull();
});

test('payments exchange rechecks email verification after authorization issuance', function (): void {
    config(['mobile_authorization.evergrove_payments_enabled' => true]);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $payload = paymentsIssueAuthorization($this, $user, Str::random(64));
    $user->forceFill(['email_verified_at' => null])->save();
    $this->postJson('/api/mobile/v1/auth/exchange', $payload)->assertForbidden();
    expect($user->tokens()->count())->toBe(0);
});

test('a payments code cannot exchange through legacy tuple or a wrong verifier', function (): void {
    config(['mobile_authorization.evergrove_payments_enabled' => true]);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $payload = paymentsIssueAuthorization($this, $user, Str::random(64));
    $this->postJson('/api/mobile/v1/auth/exchange', array_replace($payload, ['client_id' => 'everbranch-mobile', 'redirect_uri' => 'everbranch://auth/callback']))
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/mobile/v1/auth/exchange', array_replace($payload, ['code_verifier' => Str::random(64)]))
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/mobile/v1/auth/exchange', $payload)->assertOk();
});

test('legacy refresh cannot acquire payments abilities from a device name', function (): void {
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $token = $user->createToken('Everbranch mobile:fixture', ['mobile:read', 'mobile:write'], now()->addHour());
    $this->withToken($token->plainTextToken)->postJson('/api/mobile/v1/auth/refresh', ['device_name' => 'Evergrove Payments'])
        ->assertOk();
    expect($user->tokens()->sole()->abilities)->toBe(['mobile:read', 'mobile:write']);
});

test('payments refresh preserves scope and respects disabled configuration', function (): void {
    config(['mobile_authorization.evergrove_payments_enabled' => true]);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $token = $user->createToken('Everbranch mobile:payments fixture', ['mobile:read', 'mobile:write', 'payments:read', 'payments:write'], now()->addHour());
    $response = $this->withToken($token->plainTextToken)->postJson('/api/mobile/v1/auth/refresh')->assertOk();
    expect($user->tokens()->sole()->abilities)->toBe(['mobile:read', 'mobile:write', 'payments:read', 'payments:write']);
    config(['mobile_authorization.evergrove_payments_enabled' => false]);
    auth()->forgetGuards();
    $this->withToken($response->json('access_token'))->postJson('/api/mobile/v1/auth/refresh')->assertUnprocessable();
    expect($user->tokens()->count())->toBe(1);
});
