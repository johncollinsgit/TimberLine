<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    $this->withoutVite();
});

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
    config()->set('app.url', 'https://app.theeverbranch.com');
    URL::forceRootUrl('https://app.theeverbranch.com');
    URL::forceScheme('https');

    $user = User::factory()->unverified()->create();
    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    expect(parse_url($verificationUrl, PHP_URL_HOST))->toBe('app.theeverbranch.com');

    URL::forceRootUrl(null);
    URL::forceScheme(null);
});

test('verify email notification link prefers canonical landlord host when app url is legacy', function (): void {
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.theeverbranch.com');

    $user = User::factory()->unverified()->create();
    $mail = (new VerifyEmail)->toMail($user);

    expect(parse_url((string) $mail->actionUrl, PHP_URL_HOST))->toBe('app.theeverbranch.com')
        ->and(parse_url((string) $mail->actionUrl, PHP_URL_SCHEME))->toBe('https')
        ->and(parse_url((string) $mail->actionUrl, PHP_URL_PATH))->toStartWith('/email/confirm/');
});

test('signed email link confirms the addressed account without a browser login session', function (): void {
    $user = User::factory()->unverified()->create();
    $url = (new VerifyEmail)->toMail($user)->actionUrl;
    Event::fake([Verified::class]);

    $response = $this->get($url);
    $response->assertOk()->assertSee('Email confirmed');
    $cookie = collect($response->headers->getCookies())
        ->first(fn ($candidate) => $candidate->getName() === 'everbranch-mobile-auth-session');
    expect($cookie)->not->toBeNull()
        ->and($cookie->getDomain())->toBeNull()
        ->and($cookie->getSameSite())->toBe('lax');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatchedTimes(Verified::class, 1);

    $this->get($url)->assertOk();
    Event::assertDispatchedTimes(Verified::class, 1);
});

test('email link confirms its account even when a different account is signed in', function (): void {
    $user = User::factory()->unverified()->create();
    $other = User::factory()->create();
    $url = (new VerifyEmail)->toMail($user)->actionUrl;

    $this->actingAs($other)->get($url)
        ->assertOk()
        ->assertSee('Sign out before continuing.');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and(auth()->id())->toBe($other->id);
});

test('email link resumes an existing mobile authorization for its signed-in account', function (): void {
    $user = User::factory()->unverified()->create();
    $url = (new VerifyEmail)->toMail($user)->actionUrl;
    $intended = '/mobile/authorize?client_id=everbranch-mobile';

    $this->actingAs($user)
        ->withSession(['url.intended' => $intended])
        ->get($url)
        ->assertRedirect($intended);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('member confirmation ignores a stale admin dashboard destination', function (): void {
    $this->withoutVite();

    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    $user = User::factory()->unverified()->create(['role' => 'member', 'is_active' => true]);
    $user->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $url = (new VerifyEmail)->toMail($user)->actionUrl;

    $this->actingAs($user)
        ->withSession(['url.intended' => '/dashboard'])
        ->get($url)
        ->assertRedirect(route('field-service.index', absolute: false).'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->get('https://app.theeverbranch.com/field-service')->assertOk();
});

test('email confirmation rejects expired links and links for a different email', function (): void {
    $user = User::factory()->unverified()->create();
    $expired = URL::temporarySignedRoute('verification.confirm', now()->subMinute(), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);
    $wrongEmail = URL::temporarySignedRoute('verification.confirm', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1('different@example.com'),
    ]);

    $this->get($expired)->assertForbidden();
    $this->get($wrongEmail)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});
