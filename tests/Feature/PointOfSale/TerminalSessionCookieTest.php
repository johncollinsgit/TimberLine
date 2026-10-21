<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\PointOfSale\TerminalWorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;
use Laravel\Socialite\Facades\Socialite;

beforeEach(function (): void {
    $this->freezeTime();
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set(['app.url' => 'https://app.theeverbranch.com', 'session.driver' => 'database', 'session.lottery' => [0, 100],
        'tenancy.domains.canonical.base_domain' => 'theeverbranch.com', 'tenancy.domains.canonical.public_host' => 'theeverbranch.com',
        'tenancy.domains.canonical.landlord_host' => 'app.theeverbranch.com',
        'tenancy.domains.legacy.base_domains' => [], 'tenancy.domains.legacy.public_hosts' => [], 'tenancy.domains.legacy.landlord_hosts' => [],
        'tenancy.landlord.primary_host' => 'app.theeverbranch.com', 'tenancy.landlord.hosts' => ['app.theeverbranch.com'],
        'tenancy.auth.flagship_hosts' => ['app.theeverbranch.com', 'theeverbranch.com'], 'tenancy.auth.host_map' => [],
        'session.platform_cookie_defaults' => ['cookie' => 'fictional-platform-session', 'domain' => '.theeverbranch.com',
            'same_site' => 'none', 'partitioned' => true]]);
    $this->terminalActor = User::factory()->create(['name' => 'Fictional first-party terminal actor', 'role' => 'member', 'is_active' => true]);
    $this->platformActor = User::factory()->create(['name' => 'Fictional unrelated platform actor', 'role' => 'member', 'is_active' => true]);
    $this->tenant = Tenant::query()->create(['name' => 'Fictional cookie workspace', 'slug' => 'fictional-cookie-workspace']);
    $this->terminalActor->tenants()->attach($this->tenant->id, ['role' => 'member', 'membership_active' => true]);
    $guardKey = Auth::guard('web')->getName();
    $this->platformSessionId = Str::random(40);
    $this->mobileSessionId = Str::random(40);
    DB::table('sessions')->insert([
        ['id' => $this->platformSessionId, 'user_id' => $this->platformActor->id,
            'payload' => base64_encode(serialize([$guardKey => $this->platformActor->id, '_token' => 'fictional-platform-csrf',
                'private_platform_marker' => 'PRIVATE unrelated platform session'])), 'last_activity' => now()->timestamp],
        ['id' => $this->mobileSessionId, 'user_id' => null,
            'payload' => base64_encode(serialize(['_token' => 'fictional-first-party-csrf'])), 'last_activity' => now()->timestamp],
    ]);
    $this->cookieJar = ['fictional-platform-session' => $this->platformSessionId, 'everbranch-mobile-auth-session' => $this->mobileSessionId];
    $this->browserRequest = function (string $method, string $path, array $input = [], bool $firstParty = true) {
        // Reload the same browser's durable cookies from the database. Keeping
        // PHPUnit's cached guard/store could conceal a split-cookie identity bug.
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app()->forgetInstance('redirect');
        app('cookie')->flushQueuedCookies();
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
        $this->withCookies($this->cookieJar);
        $url = 'https://app.theeverbranch.com'.$path;
        $response = $method === 'GET' ? $this->get($url) : $this->post($url, $input);
        $name = $firstParty ? 'everbranch-mobile-auth-session' : 'fictional-platform-session';
        $cookie = $response->getCookie($name);
        expect($cookie)->not->toBeNull();
        if ($firstParty) {
            expect($cookie->getDomain())->toBeNull()->and($cookie->getSameSite())->toBe('lax')->and($cookie->isPartitioned())->toBeFalse();
            $response->assertCookieMissing('fictional-platform-session');
        }
        foreach (array_keys($this->cookieJar) as $key) {
            if ($updated = $response->getCookie($key)) {
                $this->cookieJar[$key] = $updated->getValue();
            }
        }

        return $response;
    };
});

afterEach(function (): void {
    Http::assertNothingSent();
});

it('keeps password two-factor terminal selection and logout on one first-party session when both browser cookies exist', function (): void {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is disabled by the application.');
    }
    $this->terminalActor->forceFill(['two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['fictional-cookie-recovery'])), 'two_factor_confirmed_at' => now()])->save();
    $platformBefore = DB::table('sessions')->where('id', $this->platformSessionId)->first();
    $memberships = DB::table('tenant_user')->get()->toJson();
    app()->instance('env', 'local');
    try {
        ($this->browserRequest)('GET', '/terminal')->assertOk()->assertSeeText('Continue to Everbranch sign-in');
        $this->assertGuest();
        expect(session('url.intended'))->toBe('/terminal/workspaces');
        ($this->browserRequest)('GET', '/login')->assertOk();
        ($this->browserRequest)('POST', '/login', ['email' => $this->terminalActor->email, 'password' => 'password', '_token' => session()->token()])
            ->assertRedirectToRoute('two-factor.login');
        $this->assertGuest();
        ($this->browserRequest)('GET', '/two-factor-challenge')->assertOk();
        ($this->browserRequest)('POST', '/two-factor-challenge', ['recovery_code' => 'fictional-cookie-recovery', '_token' => session()->token()])
            ->assertRedirectToRoute('terminal.workspaces');
        $this->assertAuthenticatedAs($this->terminalActor);
        ($this->browserRequest)('GET', '/terminal/workspaces')->assertOk()->assertSeeText($this->tenant->name)
            ->assertDontSeeText($this->platformActor->name);
        $this->assertAuthenticatedAs($this->terminalActor);
        ($this->browserRequest)('POST', '/terminal/workspace', ['tenant_id' => $this->tenant->id, '_token' => session()->token()])
            ->assertRedirectToRoute('terminal.register');
        ($this->browserRequest)('GET', '/terminal/register')->assertOk()->assertSeeText($this->terminalActor->name)
            ->assertSeeText($this->tenant->name)->assertDontSeeText($this->platformActor->name);
        expect(session(TerminalWorkspaceAccess::SESSION_KEY.'.user_id'))->toBe($this->terminalActor->id);
        ($this->browserRequest)('POST', '/logout', ['_token' => session()->token()])->assertRedirect();
        $this->assertGuest();
        expect(session(TerminalWorkspaceAccess::SESSION_KEY))->toBeNull()->and(session(TerminalWorkspaceAccess::CLOCK_KEY))->toBeNull()
            ->and(DB::table('sessions')->where('id', $this->platformSessionId)->first())->toEqual($platformBefore);
        ($this->browserRequest)('GET', '/terminal/register')->assertRedirectToRoute('login');
        $this->assertGuest();
        // Ordinary platform traffic still uses its own pre-existing session.
        ($this->browserRequest)('GET', route('password.confirm', [], false), [], false)->assertOk();
        $this->assertAuthenticatedAs($this->platformActor);
    } finally {
        app()->instance('env', 'testing');
    }
    expect(DB::table('tenant_user')->get()->toJson())->toBe($memberships)->and(DB::table('website_orders')->count())->toBe(0)
        ->and(DB::table('pos_payment_attempts')->count())->toBe(0);
});

it('keeps Google redirect state and its failure return in the terminal first-party session with an unrelated platform cookie present', function (): void {
    config()->set(['services.google.enabled' => true, 'services.google.client_id' => 'fictional-cookie-client',
        'services.google.client_secret' => 'fictional-cookie-secret', 'services.google.redirect' => 'https://app.theeverbranch.com/auth/google/callback']);
    $provider = Mockery::mock();
    $provider->shouldReceive('scopes')->once()->with(['openid', 'profile', 'email'])->andReturnSelf();
    $provider->shouldReceive('redirect')->once()->andReturnUsing(function (): RedirectResponse {
        session()->put('state', 'fictional-terminal-oauth-state');

        return new RedirectResponse('https://accounts.google.com/o/oauth2/auth?state=fictional-terminal-oauth-state');
    });
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
    $platformBefore = DB::table('sessions')->where('id', $this->platformSessionId)->first();
    ($this->browserRequest)('GET', '/terminal')->assertOk();
    ($this->browserRequest)('GET', '/auth/google/redirect')->assertRedirect('https://accounts.google.com/o/oauth2/auth?state=fictional-terminal-oauth-state');
    ($this->browserRequest)('GET', '/auth/google/callback?error=access_denied&state=fictional-terminal-oauth-state')
        ->assertRedirectToRoute('login')->assertSessionHasErrors('email');
    expect(session('state'))->toBe('fictional-terminal-oauth-state')
        ->and(DB::table('sessions')->where('id', $this->platformSessionId)->first())->toEqual($platformBefore);
    $this->assertGuest();
});
