<?php

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
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
