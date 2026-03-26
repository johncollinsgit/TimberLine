<?php

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config()->set('services.google.enabled', true);
    config()->set('services.google.client_id', 'test-google-client-id');
    config()->set('services.google.client_secret', 'test-google-client-secret');
    config()->set('services.google.redirect', 'http://localhost:8000/auth/google/callback');
    config()->set('services.google.auto_provision', true);
});

test('google redirect route redirects to the provider', function () {
    $provider = \Mockery::mock();
    $provider->shouldReceive('scopes')
        ->once()
        ->with(['openid', 'profile', 'email'])
        ->andReturnSelf();
    $provider->shouldReceive('redirect')
        ->once()
        ->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth?client_id=test-google-client-id'));

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($provider);

    $this->get(route('auth.google.redirect'))
        ->assertRedirect('https://accounts.google.com/o/oauth2/auth?client_id=test-google-client-id');
});

test('google callback links an existing active user and signs them in', function () {
    $user = User::factory()->create([
        'email' => 'pouring@example.com',
        'role' => 'pouring',
        'is_active' => true,
    ]);

    $googleUser = (new SocialiteUser())
        ->setRaw(['sub' => 'google-user-123'])
        ->map([
            'id' => 'google-user-123',
            'email' => 'pouring@example.com',
            'name' => 'Pouring User',
            'avatar' => 'https://example.com/avatar.png',
        ]);

    $provider = \Mockery::mock();
    $provider->shouldReceive('user')
        ->once()
        ->andReturn($googleUser);

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($provider);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('pouring.index', absolute: false));

    $user->refresh();

    $this->assertAuthenticatedAs($user);
    expect($user->google_id)->toBe('google-user-123')
        ->and($user->google_avatar)->toBe('https://example.com/avatar.png');
});

test('google callback retries stateless when the session state expires', function () {
    $user = User::factory()->create([
        'email' => 'pouring@example.com',
        'role' => 'pouring',
        'is_active' => true,
    ]);

    $googleUser = (new SocialiteUser())
        ->setRaw(['sub' => 'google-user-123'])
        ->map([
            'id' => 'google-user-123',
            'email' => 'pouring@example.com',
            'name' => 'Pouring User',
            'avatar' => 'https://example.com/avatar.png',
        ]);

    $provider = \Mockery::mock();
    $userCallCount = 0;
    $provider->shouldReceive('user')
        ->twice()
        ->andReturnUsing(function () use (&$userCallCount, $googleUser) {
            $userCallCount++;

            if ($userCallCount === 1) {
                throw new InvalidStateException('state mismatch');
            }

            return $googleUser;
        });
    $provider->shouldReceive('stateless')
        ->once()
        ->andReturnSelf();

    Socialite::shouldReceive('driver')
        ->twice()
        ->with('google')
        ->andReturn($provider);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('pouring.index', absolute: false));

    $user->refresh();

    $this->assertAuthenticatedAs($user);
    expect($user->google_id)->toBe('google-user-123')
        ->and($user->google_avatar)->toBe('https://example.com/avatar.png');
});

test('google callback redirects back to login when the provider exchange fails', function () {
    $provider = \Mockery::mock();
    $provider->shouldReceive('user')
        ->once()
        ->andThrow(new RuntimeException('invalid_client'));

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($provider);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['email']);
    $this->assertGuest();
});

test('google redirect fails gracefully when config is incomplete and logs preflight failure', function () {
    config()->set('services.google.client_secret', '');
    Log::spy();

    Socialite::shouldReceive('driver')->never();

    $response = $this->get(route('auth.google.redirect'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['email']);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'auth.google.oauth.preflight_failed'
                && (string) ($context['category'] ?? '') === 'auth.google.oauth'
                && (string) ($context['event'] ?? '') === 'preflight_failed'
                && (string) ($context['phase'] ?? '') === 'redirect';
        })
        ->once();
});

test('google callback logs sanitized oauth failure details without leaking secrets', function () {
    config()->set('services.google.client_secret', 'sensitive-secret-value');
    Log::spy();

    $provider = \Mockery::mock();
    $provider->shouldReceive('user')
        ->once()
        ->andThrow(new RuntimeException('invalid_client sensitive-secret-value'));

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($provider);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['email']);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            $oauthErrorMessage = (string) ($context['oauth_error_message'] ?? '');

            return $message === 'auth.google.oauth.callback_failure'
                && (string) ($context['category'] ?? '') === 'auth.google.oauth'
                && (string) ($context['failure_class'] ?? '') === 'invalid_client'
                && array_key_exists('state_present', $context)
                && array_key_exists('code_present', $context)
                && ! str_contains($oauthErrorMessage, 'sensitive-secret-value');
        })
        ->once();
});
