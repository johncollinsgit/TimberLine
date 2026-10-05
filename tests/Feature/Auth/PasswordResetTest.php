<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });
});

test('field password setup uses first-party session cookies on canonical hosts', function () {
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('session.platform_cookie_defaults', [
        'cookie' => 'laravel-session',
        'domain' => 'theeverbranch.com',
        'same_site' => 'none',
        'partitioned' => true,
    ]);

    $assertFirstPartyCookie = function ($response): void {
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === 'everbranch-mobile-auth-session');

        expect($cookie)->not->toBeNull()
            ->and($cookie->getDomain())->toBeNull()
            ->and($cookie->getSameSite())->toBe('lax')
            ->and($cookie->isPartitioned())->toBeFalse();
    };

    $assertFirstPartyCookie($this->get('https://app.theeverbranch.com/forgot-password')->assertOk());
    $assertFirstPartyCookie($this->get('https://app.theeverbranch.com/reset-password/test-token?email=employee%40example.com')->assertOk());
    $assertFirstPartyCookie($this->post('https://app.theeverbranch.com/reset-password', [
        'token' => 'invalid-token',
        'email' => 'employee@example.com',
        'password' => 'new-password-for-test',
        'password_confirmation' => 'new-password-for-test',
    ])->assertRedirect());

    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
    $employee = User::factory()->create();
    $employee->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    Notification::fake();
    $assertFirstPartyCookie($this->get('https://collins-electric.theeverbranch.com/forgot-password')->assertOk());
    $assertFirstPartyCookie($this->post('https://collins-electric.theeverbranch.com/forgot-password', [
        'email' => $employee->email,
    ])->assertRedirect());
    $token = null;
    Notification::assertSentTo($employee, ResetPassword::class, function ($notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });
    expect($token)->toBeString();
    $assertFirstPartyCookie($this->get('https://collins-electric.theeverbranch.com/reset-password/'.$token.'?email='.urlencode($employee->email))->assertOk());
    $assertFirstPartyCookie($this->post('https://collins-electric.theeverbranch.com/reset-password', [
        'token' => $token,
        'email' => $employee->email,
        'password' => 'new-password-for-test',
        'password_confirmation' => 'new-password-for-test',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login', absolute: false)));
    expect(Hash::check('new-password-for-test', $employee->fresh()->password))->toBeTrue();

    $regularCookie = collect($this->get('https://app.theeverbranch.com/login')->assertOk()->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'laravel-session');
    expect($regularCookie)->not->toBeNull()
        ->and($regularCookie->getDomain())->toBe('theeverbranch.com')
        ->and($regularCookie->isPartitioned())->toBeTrue();
});
