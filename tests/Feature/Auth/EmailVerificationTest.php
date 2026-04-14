<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')]
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('already verified user visiting verification link is redirected without firing event again', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertNotDispatched(Verified::class);
});

test('verification signed url uses canonical app host when generated without request host context', function (): void {
    config()->set('app.url', 'https://app.grovebud.com');
    URL::forceRootUrl('https://app.grovebud.com');
    URL::forceScheme('https');

    $user = User::factory()->unverified()->create();
    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    expect(parse_url($verificationUrl, PHP_URL_HOST))->toBe('app.grovebud.com');

    URL::forceRootUrl(null);
    URL::forceScheme(null);
});

test('verify email notification link prefers canonical landlord host when app url is legacy', function (): void {
    config()->set('app.url', 'https://app.forestrybackstage.com');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.landlord.primary_host', 'app.grovebud.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.grovebud.com');

    $user = User::factory()->unverified()->create();
    $mail = (new VerifyEmail)->toMail($user);

    expect(parse_url((string) $mail->actionUrl, PHP_URL_HOST))->toBe('app.grovebud.com')
        ->and(parse_url((string) $mail->actionUrl, PHP_URL_SCHEME))->toBe('https');
});
