<?php

use App\Models\MobileAuthorizationCode;
use App\Models\Tenant;
use App\Models\TenantBillingReceipt;
use App\Models\User;
use App\Notifications\SelfServiceVerifyEmail;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

beforeEach(function (): void {
    $this->withoutVite();
    Notification::fake();
    Http::preventStrayRequests();
    config()->set([
        'self_service.registration_enabled' => true,
        'session.driver' => 'database',
        'session.secure' => true,
        'session.platform_cookie_defaults' => [
            'cookie' => 'fictional-web-session', 'domain' => '.theeverbranch.com',
            'same_site' => 'lax', 'partitioned' => false,
        ],
    ]);
    $this->cookieJar = [];
    // Exercise encrypted response cookies and fresh guards/stores on every
    // request. Keeping Laravel's test guard or Store would hide this loop.
    $this->browserRequest = function (string $method, string $url, array $data = []) {
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        foreach (app('cookie')->getQueuedCookies() as $queued) {
            app('cookie')->unqueue($queued->getName(), $queued->getPath());
        }
        $response = $this->call($method, $url, $data, $this->cookieJar);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() <= time()) {
                unset($this->cookieJar[$cookie->getName()]);
            } else {
                $this->cookieJar[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    };
    $this->pendingOwner = fn (array $extra = []) => User::factory()->unverified()->create([
        'is_active' => true, 'role' => 'self_service_pending', 'requested_via' => 'self_service_registration',
        'onboarding_guide_answers' => ['business_template' => 'generic'], ...$extra,
    ]);
});

test('guest confirmation then normal cookie aware password login opens workspace creation and logs out normally', function (): void {
    $user = ($this->pendingOwner)();
    ($this->browserRequest)('GET', 'https://app.theeverbranch.com/platform/signup')->assertOk();
    $originalWebSession = CookieValuePrefix::remove(Crypt::decryptString($this->cookieJar['fictional-web-session']));
    ($this->browserRequest)('GET', (new SelfServiceVerifyEmail)->toMail($user)->actionUrl)->assertOk()->assertSeeText('Email confirmed');
    expect($this->cookieJar)->toHaveKey('everbranch-mobile-auth-session');

    $login = ($this->browserRequest)('GET', 'https://app.theeverbranch.com/login')->assertOk();
    $retired = collect($login->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'everbranch-mobile-auth-session');
    $web = collect($login->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'fictional-web-session');
    expect($retired)->not->toBeNull()
        ->and($retired->getExpiresTime())->toBe(1)
        ->and($retired->getPath())->toBe('/')
        ->and($retired->getDomain())->toBeNull()
        ->and($retired->getSameSite())->toBe('lax')
        ->and($retired->isSecure())->toBeTrue()
        ->and($retired->isHttpOnly())->toBeTrue()
        ->and($retired->isPartitioned())->toBeFalse()
        ->and($web->getExpiresTime())->toBeGreaterThan(time())
        ->and($web->getDomain())->toBe('.theeverbranch.com')
        ->and(CookieValuePrefix::remove(Crypt::decryptString($this->cookieJar['fictional-web-session'])))->toBe($originalWebSession)
        ->and($this->cookieJar)->not->toHaveKey('everbranch-mobile-auth-session');

    ($this->browserRequest)('POST', 'https://app.theeverbranch.com/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/workspace/create');
    ($this->browserRequest)('GET', 'https://app.theeverbranch.com/workspace/create')->assertOk()->assertSeeText('Your business');
    expect(Tenant::query()->count())->toBe(0)->and(TenantBillingReceipt::query()->count())->toBe(0);
    ($this->browserRequest)('POST', 'https://app.theeverbranch.com/logout')->assertRedirect();
    ($this->browserRequest)('GET', 'https://app.theeverbranch.com/workspace/create')->assertRedirect(route('login'));
    Http::assertNothingSent();
});

test('normal web two factor uses its own session after an unrelated mobile confirmation cookie', function (): void {
    $user = ($this->pendingOwner)([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['fictional-web-recovery'], JSON_THROW_ON_ERROR)),
        'two_factor_confirmed_at' => now(),
    ]);
    ($this->browserRequest)('GET', (new SelfServiceVerifyEmail)->toMail($user)->actionUrl)->assertOk();
    ($this->browserRequest)('GET', 'https://app.theeverbranch.com/login')->assertOk();
    ($this->browserRequest)('POST', 'https://app.theeverbranch.com/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    ($this->browserRequest)('GET', 'https://app.theeverbranch.com/two-factor-challenge')->assertOk();
    ($this->browserRequest)('POST', 'https://app.theeverbranch.com/two-factor-challenge', ['recovery_code' => 'fictional-web-recovery'])
        ->assertRedirect('/workspace/create');
    ($this->browserRequest)('GET', 'https://app.theeverbranch.com/workspace/create')->assertOk();
    expect($this->cookieJar)->not->toHaveKey('everbranch-mobile-auth-session');
    Http::assertNothingSent();
});

test('normal Google sign in retains its web state after retiring an unrelated mobile confirmation cookie', function (): void {
    $user = ($this->pendingOwner)();
    config()->set(['services.google.enabled' => true, 'services.google.client_id' => 'fictional-client',
        'services.google.client_secret' => 'fictional-secret', 'services.google.redirect' => 'https://app.theeverbranch.com/auth/google/callback']);
    ($this->browserRequest)('GET', (new SelfServiceVerifyEmail)->toMail($user)->actionUrl)->assertOk();
    ($this->browserRequest)('GET', 'https://app.theeverbranch.com/login')->assertOk();
    $provider = Mockery::mock();
    $provider->shouldReceive('scopes')->once()->with(['openid', 'profile', 'email'])->andReturnSelf();
    $provider->shouldReceive('redirect')->once()->andReturnUsing(function () {
        session()->put('fictional.web.oauth.state', 'web-state');

        return redirect('https://accounts.google.com/o/oauth2/auth');
    });
    $provider->shouldReceive('user')->once()->andReturnUsing(function () use ($user) {
        expect(session('fictional.web.oauth.state'))->toBe('web-state');

        return (new GoogleUser)->setRaw(['email_verified' => true])->map(['id' => 'fictional-web-google-user', 'email' => $user->email, 'name' => $user->name]);
    });
    Socialite::shouldReceive('driver')->with('google')->twice()->andReturn($provider);
    ($this->browserRequest)('GET', 'https://app.theeverbranch.com/auth/google/redirect')->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    ($this->browserRequest)('GET', 'https://app.theeverbranch.com/auth/google/callback')->assertRedirect('/workspace/create');
    ($this->browserRequest)('GET', 'https://app.theeverbranch.com/workspace/create')->assertOk();
    expect($this->cookieJar)->not->toHaveKey('everbranch-mobile-auth-session');
    Http::assertNothingSent();
});

test('explicit mobile email sign in keeps its isolated cookie and completes PKCE even with a web cookie', function (bool $twoFactor): void {
    ($this->browserRequest)('GET', 'https://app.theeverbranch.com/login')->assertOk();
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), ...($twoFactor ? [
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['fictional-mobile-recovery'], JSON_THROW_ON_ERROR)),
        'two_factor_confirmed_at' => now(),
    ] : [])]);
    $parameters = ['client_id' => 'everbranch-mobile', 'redirect_uri' => 'everbranch://auth/callback',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', Str::random(64), true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256', 'state' => Str::random(32), 'auth_method' => 'email'];
    $authorize = 'https://app.theeverbranch.com/mobile/authorize?'.http_build_query($parameters);
    ($this->browserRequest)('GET', $authorize)->assertRedirect(route('login', ['mobile_email' => 1]));
    $login = ($this->browserRequest)('GET', 'https://app.theeverbranch.com/login?mobile_email=1')->assertOk();
    $mobile = collect($login->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'everbranch-mobile-auth-session');
    expect($mobile->getExpiresTime())->toBeGreaterThan(time())
        ->and($mobile->getDomain())->toBeNull()
        ->and($mobile->getSameSite())->toBe('lax')
        ->and($mobile->isPartitioned())->toBeFalse()
        ->and($this->cookieJar)->toHaveKeys(['everbranch-mobile-auth-session', 'fictional-web-session']);
    $signedIn = ($this->browserRequest)('POST', 'https://app.theeverbranch.com/login', [
        'mobile_email' => 1, 'email' => $user->email, 'password' => 'password',
    ]);
    if ($twoFactor) {
        $signedIn->assertRedirect(route('two-factor.login'));
        ($this->browserRequest)('GET', 'https://app.theeverbranch.com/two-factor-challenge')->assertOk();
        $signedIn = ($this->browserRequest)('POST', 'https://app.theeverbranch.com/two-factor-challenge', ['recovery_code' => 'fictional-mobile-recovery']);
    }
    $signedIn->assertRedirect(route('mobile.everbranch.authorize', $parameters, absolute: false));
    ($this->browserRequest)('GET', $authorize)->assertRedirectContains('everbranch://auth/callback?');
    expect(MobileAuthorizationCode::query()->where('user_id', $user->id)->count())->toBe(1);
    // The native authorization never authenticates the separate web workspace.
    ($this->browserRequest)('GET', 'https://app.theeverbranch.com/workspace/create')->assertRedirect(route('login'));
    Http::assertNothingSent();
})->with([false, true]);
