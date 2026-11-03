<?php

use App\Models\MobileAuthorizationCode;
use App\Models\User;
use App\Support\Auth\MobileAuthorizationClients;
use Illuminate\Support\Str;

it('independently prevents refresh from broadening restricted token abilities', function (array $originalAbilities): void {
    config()->set('mobile_authorization.evergrove_payments_enabled', true);
    $actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $token = $actor->createToken('Everbranch mobile:independent restricted payment fixture', $originalAbilities, now()->addHour());
    $response = $this->withToken($token->plainTextToken)->postJson('/api/mobile/v1/auth/refresh');
    if ($response->isSuccessful()) {
        $current = $actor->tokens()->sole();
        expect(array_diff($current->abilities, $originalAbilities))->toBe([]);
    } else {
        expect($response->getStatusCode())->toBeIn([401, 403, 422])
            ->and($actor->tokens()->count())->toBe(1);
    }
})->with([
    'full-payment-lane' => [['mobile:read', 'mobile:write', 'payments:read', 'payments:write']],
    'payment-read-only' => [['mobile:read', 'payments:read']],
    'missing-mobile-write' => [['mobile:read', 'payments:read', 'payments:write']],
    'legacy-read-only' => [['mobile:read']],
]);

it('independently accepts no client redirect cross product or callback mutation', function (string $client, string $redirect): void {
    config()->set('mobile_authorization.evergrove_payments_enabled', true);
    $actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $verifier = Str::random(64);
    $parameters = ['client_id' => $client, 'redirect_uri' => $redirect,
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256', 'state' => Str::random(32), 'auth_method' => 'email'];
    $this->actingAs($actor)->get('/mobile/authorize?'.http_build_query($parameters))->assertSessionHasErrors('client_id');
    $this->postJson('/api/mobile/v1/auth/exchange', ['client_id' => $client, 'redirect_uri' => $redirect,
        'code' => Str::random(64), 'code_verifier' => $verifier])->assertUnprocessable()->assertJsonValidationErrors('client_id');
    expect(MobileAuthorizationCode::query()->count())->toBe(0)->and($actor->tokens()->count())->toBe(0);
})->with([
    'legacy-new' => ['everbranch-mobile', 'evergrovepayments://auth/callback'],
    'new-legacy' => ['evergrove-payments-ios', 'everbranch://auth/callback'],
    'case-scheme' => ['evergrove-payments-ios', 'EVERGROVEPAYMENTS://auth/callback'],
    'fragment' => ['evergrove-payments-ios', 'evergrovepayments://auth/callback#other'],
    'query' => ['evergrove-payments-ios', 'evergrovepayments://auth/callback?other=1'],
    'suffix' => ['evergrove-payments-ios', 'evergrovepayments://auth/callback/more'],
    'userinfo' => ['evergrove-payments-ios', 'evergrovepayments://attacker@auth/callback'],
    'encoded-path' => ['evergrove-payments-ios', 'evergrovepayments://auth/%63allback'],
    'trailing-slash' => ['evergrove-payments-ios', 'evergrovepayments://auth/callback/'],
]);

it('independently keeps truthy configuration strings from enabling the payment client', function (): void {
    config()->set('mobile_authorization.evergrove_payments_enabled', 'true');
    expect(fn () => MobileAuthorizationClients::assertAllowed(MobileAuthorizationClients::PAYMENTS_CLIENT, MobileAuthorizationClients::PAYMENTS_REDIRECT))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});
