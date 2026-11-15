<?php

use App\Models\Tenant;
use App\Models\TenantBillingReceipt;
use App\Models\User;
use App\Notifications\SelfServiceVerifyEmail;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    $this->withoutVite();
    Notification::fake();
    config()->set('self_service.registration_enabled', true);
});

function pendingEmailOwner(array $attributes = []): User
{
    return User::factory()->unverified()->create([
        'is_active' => true,
        'role' => 'self_service_pending',
        'requested_via' => 'self_service_registration',
        'onboarding_guide_answers' => ['business_template' => 'generic', 'branch_selection' => ['managed_website']],
        ...$attributes,
    ]);
}

test('self service emails use canonical signed confirmation instead of the signup request host', function (): void {
    config()->set('tenancy.domains.canonical.landlord_host', 'app.theeverbranch.com');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    URL::forceRootUrl('https://theeverbranch.com');
    $user = pendingEmailOwner();

    $url = (new SelfServiceVerifyEmail)->toMail($user)->actionUrl;

    expect(parse_url($url, PHP_URL_HOST))->toBe('app.theeverbranch.com')
        ->and(parse_url($url, PHP_URL_SCHEME))->toBe('https')
        ->and(parse_url($url, PHP_URL_PATH))->toStartWith('/email/confirm/');
    $this->get($url)->assertOk()->assertSeeText('Email confirmed');
    $this->assertGuest();
});

test('a fresh email browser confirms then signs in and continues setup without paid access', function (): void {
    $password = 'fictional-email-recovery-password';
    $user = pendingEmailOwner(['password' => $password]);
    $url = (new SelfServiceVerifyEmail)->toMail($user)->actionUrl;

    $response = $this->get($url)->assertOk()->assertSeeText('Email confirmed');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $this->assertGuest();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and($user->fresh()->role)->toBe('self_service_pending')
        ->and(Tenant::query()->count())->toBe(0)
        ->and(TenantBillingReceipt::query()->count())->toBe(0);

    $this->post(route('login'), ['email' => $user->email, 'password' => $password])
        ->assertRedirect(route('workspace.first-login', absolute: false));
    $this->get(route('workspace.first-login'))->assertOk()->assertSeeText('Your business');
    expect(Tenant::query()->count())->toBe(0);
});

test('canonical signup confirmation does not switch a different signed in account', function (): void {
    $target = pendingEmailOwner();
    $other = User::factory()->create();
    $url = (new SelfServiceVerifyEmail)->toMail($target)->actionUrl;

    $this->actingAs($other)->get($url)->assertOk()->assertSeeText('Sign out before continuing.');

    $this->assertAuthenticatedAs($other);
    expect($target->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and($target->fresh()->role)->toBe('self_service_pending')
        ->and(Tenant::query()->count())->toBe(0);
});

test('previously issued signup links work anonymously on their original host', function (): void {
    $user = pendingEmailOwner();
    URL::forceRootUrl('https://theeverbranch.com');
    URL::forceScheme('https');
    $url = URL::temporarySignedRoute('self-service.verify', now()->addMinutes(10), ['id' => $user->id, 'hash' => sha1($user->email)]);
    URL::forceRootUrl(null);
    URL::forceScheme(null);

    $this->get($url)->assertOk()->assertSeeText('Email confirmed');
    $this->assertGuest();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('previous signup links preserve the different signed in account fence', function (): void {
    $user = pendingEmailOwner();
    $other = User::factory()->create();
    $url = URL::temporarySignedRoute('self-service.verify', now()->addMinutes(10), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->actingAs($other)->get($url)->assertForbidden();

    $this->assertAuthenticatedAs($other);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('inactive self service accounts cannot confirm through either link or provision a workspace', function (string $route): void {
    $user = pendingEmailOwner(['is_active' => false]);
    $url = URL::temporarySignedRoute($route, now()->addMinutes(10), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->get($url)->assertForbidden();
    $this->assertGuest();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    $this->actingAs($user)->post(route('workspace.first-login.store'), [
        'workspace_name' => 'Fictional blocked business', 'template_key' => 'generic', 'start_path' => 'self',
    ])->assertRedirect(route('self-service.verify.notice'));
    expect(Tenant::query()->count())->toBe(0);
})->with(['verification.confirm', 'self-service.verify']);

test('signup confirmation ignores stale or foreign intended destinations', function (string $intended): void {
    $user = pendingEmailOwner();
    $url = (new SelfServiceVerifyEmail)->toMail($user)->actionUrl;

    $response = $this->actingAs($user)->withSession(['url.intended' => $intended])->get($url)
        ->assertRedirect(route('workspace.first-login', absolute: false).'?verified=1')
        ->assertSessionMissing('url.intended');

    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $this->assertAuthenticatedAs($user);
})->with(['/dashboard', 'https://foreign.example/mobile/authorize?client_id=everbranch-mobile']);

test('invalid email links show private recovery without resolving or disclosing their target', function (string $route, bool $expired): void {
    $target = pendingEmailOwner(['email' => 'private-target@example.test']);
    $url = URL::temporarySignedRoute($route, $expired ? now()->subMinute() : now()->addMinute(), ['id' => $target->id, 'hash' => sha1($target->email)]);
    if (! $expired) {
        $url .= '&tampered=1';
    }
    $targetLookups = 0;
    DB::listen(function (QueryExecuted $query) use ($target, &$targetLookups): void {
        if (preg_match('/from\s+["`]?users["`]?/i', $query->sql) && in_array($target->id, $query->bindings, true)) {
            $targetLookups++;
        }
    });

    $response = $this->get($url)->assertForbidden()->assertSeeText('Get a new link')
        ->assertSeeText('Sign in to request another verification email.')
        ->assertDontSee($target->email)->assertDontSee('name="email"', false);

    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    expect($targetLookups)->toBe(0);
    Notification::assertNothingSent();
    $this->assertGuest();
    expect($target->fresh()->hasVerifiedEmail())->toBeFalse();
})->with([
    'canonical expired' => ['verification.confirm', true],
    'canonical tampered' => ['verification.confirm', false],
    'legacy expired' => ['self-service.verify', true],
    'legacy tampered' => ['self-service.verify', false],
]);

test('recovery resends only to the active unverified account in the current session', function (): void {
    $target = pendingEmailOwner(['email' => 'private-other-target@example.test']);
    $current = pendingEmailOwner();
    $url = URL::temporarySignedRoute('verification.confirm', now()->subMinute(), ['id' => $target->id, 'hash' => sha1($target->email)]);

    $this->actingAs($current)->get($url)->assertForbidden()->assertSeeText('Send verification email')->assertDontSee($target->email);
    Notification::assertNothingSent();
    $this->post(route('self-service.verify.resend'))->assertRedirect();

    Notification::assertSentTo($current, SelfServiceVerifyEmail::class);
    Notification::assertNotSentTo($target, SelfServiceVerifyEmail::class);
    expect($target->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('a verified account gets an explicit account choice without a forced logout on an expired link', function (): void {
    $target = pendingEmailOwner();
    $other = User::factory()->create();
    $url = URL::temporarySignedRoute('verification.confirm', now()->subMinute(), ['id' => $target->id, 'hash' => sha1($target->email)]);

    $this->actingAs($other)->get($url)->assertForbidden()->assertSeeText('Use another account')
        ->assertDontSeeText('Send verification email')->assertDontSee($target->email);

    $this->assertAuthenticatedAs($other);
    expect($target->fresh()->hasVerifiedEmail())->toBeFalse();
    Notification::assertNothingSent();
});

test('expired link json responses remain forbidden and private', function (): void {
    $user = pendingEmailOwner();
    $url = URL::temporarySignedRoute('verification.confirm', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $response = $this->getJson($url)->assertForbidden()->assertJson(['message' => 'This verification link has expired or changed.']);

    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});
