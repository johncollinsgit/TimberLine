<?php

use App\Models\Tenant;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use App\Services\PointOfSale\PosAccess;
use App\Services\PointOfSale\TerminalWorkspaceAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Symfony\Component\HttpKernel\Exception\HttpException;

function terminalAccessTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

function terminalAccessRedirect(TestResponse $response, string $path): TestResponse
{
    $response->assertRedirect();
    $target = (string) $response->headers->get('Location');
    expect(parse_url($target, PHP_URL_PATH))->toBe($path);
    $host = parse_url($target, PHP_URL_HOST);
    if ($host !== null) {
        expect($host)->toBe('app.theeverbranch.com');
    }

    return $response;
}

function terminalAccessBusinessState(): array
{
    $state = [];
    foreach (['tenant_user', 'tenant_payment_accounts', 'website_customers', 'website_products', 'website_product_variants',
        'website_orders', 'website_order_lines', 'website_payments', 'website_inventory_reservations', 'website_inventory_movements',
        'pos_registers', 'pos_payment_attempts', 'pos_refunds', 'pos_simulator_objects'] as $table) {
        $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    return $state;
}

beforeEach(function (): void {
    $this->freezeTime();
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set(['app.url' => 'https://app.theeverbranch.com',
        'tenancy.domains.canonical.base_domain' => 'theeverbranch.com', 'tenancy.domains.canonical.public_host' => 'theeverbranch.com',
        'tenancy.domains.canonical.landlord_host' => 'app.theeverbranch.com',
        'tenancy.domains.legacy.base_domains' => [], 'tenancy.domains.legacy.public_hosts' => [], 'tenancy.domains.legacy.landlord_hosts' => [],
        'tenancy.landlord.primary_host' => 'app.theeverbranch.com', 'tenancy.landlord.hosts' => ['app.theeverbranch.com'],
        'tenancy.auth.flagship_hosts' => ['app.theeverbranch.com', 'theeverbranch.com'], 'tenancy.auth.host_map' => []]);
    $this->actor = User::factory()->create(['name' => 'Fictional terminal operator', 'email' => 'terminal-operator@example.test',
        'role' => 'member', 'is_active' => true, 'approved_at' => now()]);
    $this->watershed = terminalAccessTenant('Fictional Watershed Workspace', 'fictional-watershed-terminal');
    $this->other = terminalAccessTenant('Fictional Other Workspace', 'fictional-other-terminal');
    $this->actor->tenants()->attach($this->watershed->id, ['role' => 'admin', 'membership_active' => true]);
    $this->url = fn (string $name): string => 'https://app.theeverbranch.com'.route($name, [], false);
    $this->select = fn (?int $tenantId = null): TestResponse => $this->post(($this->url)('terminal.select'), ['tenant_id' => $tenantId ?? $this->watershed->id]);
    $this->selection = fn (array $changes = []): array => [...['user_id' => $this->actor->id, 'tenant_id' => $this->watershed->id,
        'selected_at' => now()->timestamp, 'last_seen_at' => now()->timestamp], ...$changes];
});

afterEach(function (): void {
    Http::assertNothingSent();
});

it('continues real Everbranch password login into explicit workspace selection and clears it on normal logout', function (): void {
    $memberships = DB::table('tenant_user')->get()->toJson();
    $entry = $this->get(($this->url)('terminal.entry'))->assertOk()->assertSeeText('Everbranch terminal');
    $entry->assertSessionHas('url.intended', fn ($target) => parse_url($target, PHP_URL_PATH) === '/terminal/workspaces');
    terminalAccessRedirect($this->post('https://app.theeverbranch.com/login', ['email' => $this->actor->email, 'password' => 'password']), '/terminal/workspaces');
    $this->assertAuthenticatedAs($this->actor);
    $this->get(($this->url)('terminal.workspaces'))->assertOk()->assertSeeText('Select your workspace')->assertSeeText($this->watershed->name);
    terminalAccessRedirect(($this->select)(), '/terminal/register');
    $this->get(($this->url)('terminal.register'))->assertOk()->assertSeeText($this->watershed->name)->assertSeeText('Live payments unavailable');
    $this->post('https://app.theeverbranch.com/logout')->assertRedirect()->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY)->assertSessionMissing('tenant_id');
    $this->assertGuest();
    terminalAccessRedirect($this->get(($this->url)('terminal.register')), '/login');
    expect(DB::table('tenant_user')->get()->toJson())->toBe($memberships)->and(DB::table('pos_payment_attempts')->count())->toBe(0);
});

it('does not allow a pending two-factor challenge to select a terminal workspace', function (): void {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is disabled by the application.');
    }
    $this->actor->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_recovery_codes' => encrypt(json_encode(['fictional-terminal-recovery'])),
        'two_factor_confirmed_at' => now()])->save();
    $this->get(($this->url)('terminal.entry'));
    terminalAccessRedirect($this->post('https://app.theeverbranch.com/login', ['email' => $this->actor->email, 'password' => 'password']), '/two-factor-challenge');
    $this->assertGuest();
    terminalAccessRedirect(($this->select)(), '/login')->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY);
    // The denied POST may replace the ordinary intended URL with the safe
    // terminal entry. Completing 2FA still requires the explicit chooser.
    terminalAccessRedirect($this->post('https://app.theeverbranch.com/two-factor-challenge', ['recovery_code' => 'fictional-terminal-recovery']), '/terminal');
    $this->assertAuthenticatedAs($this->actor);
    terminalAccessRedirect($this->get(($this->url)('terminal.entry')), '/terminal/workspaces');
    terminalAccessRedirect(($this->select)(), '/terminal/register');
});

it('lists only currently active memberships in recognized terminal roles without granting or exposing unrelated access', function (): void {
    $visible = [$this->watershed->name];
    foreach (['owner', 'tenant_owner', 'manager', 'member'] as $role) {
        $tenant = terminalAccessTenant('Fictional visible '.$role, 'terminal-visible-'.str_replace('_', '-', $role));
        $this->actor->tenants()->attach($tenant->id, ['role' => $role, 'membership_active' => true]);
        $visible[] = $tenant->name;
    }
    $inactive = terminalAccessTenant('PRIVATE inactive membership', 'terminal-inactive');
    $this->actor->tenants()->attach($inactive->id, ['role' => 'owner', 'membership_active' => false]);
    $unknown = terminalAccessTenant('PRIVATE unsupported role', 'terminal-unsupported');
    $this->actor->tenants()->attach($unknown->id, ['role' => 'marketing_manager', 'membership_active' => true]);
    $before = terminalAccessBusinessState();
    $response = $this->actingAs($this->actor)->get(($this->url)('terminal.workspaces'))->assertOk();
    foreach ($visible as $name) {
        $response->assertSeeText($name);
    }
    $response->assertDontSeeText($this->other->name)->assertDontSeeText($inactive->name)->assertDontSeeText($unknown->name)
        ->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY);
    expect(terminalAccessBusinessState())->toBe($before);
});

it('rotates the selection session while preserving the global workspace and the same Everbranch actor', function (string $role): void {
    $this->actor->tenants()->updateExistingPivot($this->watershed->id, ['role' => $role]);
    $before = terminalAccessBusinessState();
    $this->actingAs($this->actor)->withSession(['tenant_id' => $this->other->id]);
    $previousId = session()->getId();
    $response = terminalAccessRedirect(($this->select)(), '/terminal/register');
    $selection = session(TerminalWorkspaceAccess::SESSION_KEY);
    $response->assertSessionHas('tenant_id', $this->other->id);
    expect(session()->getId())->not->toBe($previousId)->and($selection['user_id'])->toBe($this->actor->id)
        ->and($selection['tenant_id'])->toBe($this->watershed->id)->and($selection['selected_at'])->toBe(now()->timestamp)
        ->and($selection['last_seen_at'])->toBe(now()->timestamp);
    $this->travel(1)->minutes();
    $this->get(($this->url)('terminal.register'))->assertOk()->assertSeeText($this->watershed->name);
    expect(session(TerminalWorkspaceAccess::SESSION_KEY.'.selected_at'))->toBe($selection['selected_at'])
        ->and(session(TerminalWorkspaceAccess::SESSION_KEY.'.last_seen_at'))->toBe(now()->timestamp)
        ->and(terminalAccessBusinessState())->toBe($before);
    $this->assertAuthenticatedAs($this->actor);
})->with(['owner', 'tenant_owner', 'admin', 'manager', 'member']);

it('requires an explicit selected workspace and clear removes only that selection', function (): void {
    $this->actingAs($this->actor)->withSession(['tenant_id' => $this->other->id]);
    terminalAccessRedirect($this->get(($this->url)('terminal.register')), '/terminal/workspaces');
    terminalAccessRedirect(($this->select)(), '/terminal/register');
    terminalAccessRedirect($this->post(($this->url)('terminal.clear')), '/terminal/workspaces')
        ->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY)->assertSessionHas('tenant_id', $this->other->id);
    $this->assertAuthenticatedAs($this->actor);
    terminalAccessRedirect($this->get(($this->url)('terminal.register')), '/terminal/workspaces');
});

it('keeps the first terminal deadline across chooser visits clearing and workspace reselection', function (): void {
    $this->actor->tenants()->attach($this->other->id, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($this->actor)->get(($this->url)('terminal.workspaces'))->assertOk();
    $startedAt = session(TerminalWorkspaceAccess::CLOCK_KEY.'.selected_at');
    ($this->select)();
    $this->travel(TerminalWorkspaceAccess::MAX_SECONDS - 60)->seconds();
    // Represent intervening active use without thousands of polling requests.
    session()->put(TerminalWorkspaceAccess::CLOCK_KEY.'.last_seen_at', now()->timestamp);
    session()->put(TerminalWorkspaceAccess::SESSION_KEY.'.last_seen_at', now()->timestamp);
    $this->get(($this->url)('terminal.workspaces'))->assertOk()->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY);
    expect(session(TerminalWorkspaceAccess::CLOCK_KEY.'.selected_at'))->toBe($startedAt);
    terminalAccessRedirect(($this->select)($this->other->id), '/terminal/register');
    expect(session(TerminalWorkspaceAccess::SESSION_KEY.'.selected_at'))->toBe($startedAt)
        ->and(session(TerminalWorkspaceAccess::CLOCK_KEY.'.selected_at'))->toBe($startedAt);
    terminalAccessRedirect($this->post(($this->url)('terminal.clear')), '/terminal/workspaces');
    expect(session(TerminalWorkspaceAccess::CLOCK_KEY.'.selected_at'))->toBe($startedAt);
    $this->travel(60)->seconds();
    terminalAccessRedirect(($this->select)(), '/terminal')->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY)
        ->assertSessionMissing(TerminalWorkspaceAccess::CLOCK_KEY);
    $this->assertGuest();
});

it('still requires a fresh login after idle expiry when workspace selection has been cleared', function (): void {
    $this->actingAs($this->actor);
    ($this->select)();
    terminalAccessRedirect($this->post(($this->url)('terminal.clear')), '/terminal/workspaces')
        ->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY)->assertSessionHas(TerminalWorkspaceAccess::CLOCK_KEY);
    $this->travel(TerminalWorkspaceAccess::IDLE_SECONDS)->seconds();
    terminalAccessRedirect($this->get(($this->url)('terminal.workspaces')), '/terminal')
        ->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY)->assertSessionMissing(TerminalWorkspaceAccess::CLOCK_KEY);
    $this->assertGuest();
});

it('does not renew the absolute deadline through a rejected foreign workspace selection', function (): void {
    $this->actingAs($this->actor);
    ($this->select)();
    $startedAt = session(TerminalWorkspaceAccess::CLOCK_KEY.'.selected_at');
    $this->travel(TerminalWorkspaceAccess::MAX_SECONDS - 60)->seconds();
    session()->put(TerminalWorkspaceAccess::CLOCK_KEY.'.last_seen_at', now()->timestamp);
    session()->put(TerminalWorkspaceAccess::SESSION_KEY.'.last_seen_at', now()->timestamp);
    ($this->select)($this->other->id)->assertForbidden()->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY);
    expect(session(TerminalWorkspaceAccess::CLOCK_KEY.'.selected_at'))->toBe($startedAt);
    $this->get(($this->url)('terminal.workspaces'))->assertOk();
    terminalAccessRedirect(($this->select)(), '/terminal/register');
    expect(session(TerminalWorkspaceAccess::SESSION_KEY.'.selected_at'))->toBe($startedAt);
    $this->travel(60)->seconds();
    terminalAccessRedirect($this->get(($this->url)('terminal.register')), '/terminal');
    $this->assertGuest();
});

it('derives the original deadline from an existing legacy selection without extending it', function (): void {
    $selectedAt = now()->subMinutes(3)->timestamp;
    $this->actingAs($this->actor)->withSession([TerminalWorkspaceAccess::SESSION_KEY => ($this->selection)(['selected_at' => $selectedAt])])
        ->get(($this->url)('terminal.register'))->assertOk();
    expect(session(TerminalWorkspaceAccess::CLOCK_KEY))->toBe(['user_id' => $this->actor->id,
        'selected_at' => $selectedAt, 'last_seen_at' => now()->timestamp])
        ->and(session(TerminalWorkspaceAccess::SESSION_KEY.'.selected_at'))->toBe($selectedAt);
});

it('denies malformed or cross-user clocks even when no workspace selection remains', function (string $malformation): void {
    $clock = ['user_id' => $this->actor->id, 'selected_at' => now()->timestamp, 'last_seen_at' => now()->timestamp];
    $clock = match ($malformation) {
        'other actor' => [...$clock, 'user_id' => $this->actor->id + 1],
        'string start' => [...$clock, 'selected_at' => (string) now()->timestamp],
        'future activity' => [...$clock, 'last_seen_at' => now()->addSecond()->timestamp],
        'extra tenant authority' => [...$clock, 'tenant_id' => $this->watershed->id],
        'non-array' => 'invalid clock',
    };
    $before = terminalAccessBusinessState();
    $this->actingAs($this->actor)->withSession([TerminalWorkspaceAccess::CLOCK_KEY => $clock])
        ->get(($this->url)('terminal.workspaces'))->assertForbidden()->assertSessionMissing(TerminalWorkspaceAccess::CLOCK_KEY)
        ->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY);
    $this->assertGuest();
    expect(terminalAccessBusinessState())->toBe($before);
})->with(['other actor', 'string start', 'future activity', 'extra tenant authority', 'non-array']);

it('enforces real CSRF on workspace selection before it can change the terminal session', function (): void {
    $this->actingAs($this->actor)->withSession(['_token' => 'fictional-valid-terminal-csrf', 'tenant_id' => $this->other->id]);
    $before = terminalAccessBusinessState();
    app()->instance('env', 'local');
    try {
        $this->postJson(($this->url)('terminal.select'), ['tenant_id' => $this->watershed->id])->assertStatus(419)
            ->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY)->assertSessionHas('tenant_id', $this->other->id);
        terminalAccessRedirect($this->post(($this->url)('terminal.select'), ['tenant_id' => $this->watershed->id, '_token' => 'fictional-valid-terminal-csrf']), '/terminal/register');
    } finally {
        app()->instance('env', 'testing');
    }
    expect(terminalAccessBusinessState())->toBe($before);
});

it('rejects unauthorized selections and clears previous terminal context without modifying memberships', function (string $denial): void {
    $this->actingAs($this->actor)->withSession(['tenant_id' => $this->other->id, TerminalWorkspaceAccess::SESSION_KEY => ($this->selection)()]);
    $target = $this->other->id;
    if ($denial === 'inactive membership') {
        $this->actor->tenants()->attach($target, ['role' => 'admin', 'membership_active' => false]);
    } elseif ($denial === 'unsupported role') {
        $this->actor->tenants()->attach($target, ['role' => 'platform_admin', 'membership_active' => true]);
    } elseif ($denial === 'nonexistent workspace') {
        $target = 999999;
    }
    $before = terminalAccessBusinessState();
    ($this->select)($target)->assertForbidden()->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY)->assertSessionHas('tenant_id', $this->other->id);
    expect(terminalAccessBusinessState())->toBe($before);
})->with(['foreign workspace', 'inactive membership', 'unsupported role', 'nonexistent workspace']);

it('rechecks fresh actor membership and role after selection instead of trusting the cached user or session', function (string $revocation): void {
    $this->actingAs($this->actor);
    ($this->select)();
    match ($revocation) {
        'disabled actor' => DB::table('users')->where('id', $this->actor->id)->update(['is_active' => false]),
        'unverified actor' => DB::table('users')->where('id', $this->actor->id)->update(['email_verified_at' => null]),
        'inactive pivot' => DB::table('tenant_user')->where('user_id', $this->actor->id)->update(['membership_active' => false]),
        'removed pivot' => DB::table('tenant_user')->where('user_id', $this->actor->id)->delete(),
        'changed role' => DB::table('tenant_user')->where('user_id', $this->actor->id)->update(['role' => 'viewer']),
        'other actor selection' => session()->put(TerminalWorkspaceAccess::SESSION_KEY, ($this->selection)(['user_id' => $this->actor->id + 1])),
    };
    $before = terminalAccessBusinessState();
    $this->get(($this->url)('terminal.register'))->assertForbidden()->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY);
    if (in_array($revocation, ['disabled actor', 'unverified actor', 'other actor selection'], true)) {
        $this->assertGuest();
        expect(session(TerminalWorkspaceAccess::CLOCK_KEY))->toBeNull();
    }
    expect(terminalAccessBusinessState())->toBe($before);
})->with(['disabled actor', 'unverified actor', 'inactive pivot', 'removed pivot', 'changed role', 'other actor selection']);

it('clears malformed or future-dated terminal selection instead of accepting client-shaped authorization state', function (string $malformation): void {
    $selection = match ($malformation) {
        'string identity' => ($this->selection)(['tenant_id' => (string) $this->watershed->id]),
        'future activity' => ($this->selection)(['last_seen_at' => now()->addMinute()->timestamp]),
        'reversed activity' => ($this->selection)(['selected_at' => now()->timestamp, 'last_seen_at' => now()->subSecond()->timestamp]),
        'extra authority' => ($this->selection)(['role' => 'owner']),
        'non-array' => (string) $this->watershed->id,
    };
    $before = terminalAccessBusinessState();
    $this->actingAs($this->actor)->withSession([TerminalWorkspaceAccess::SESSION_KEY => $selection])
        ->get(($this->url)('terminal.register'))->assertForbidden()->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY);
    $this->assertGuest();
    expect(session(TerminalWorkspaceAccess::CLOCK_KEY))->toBeNull();
    expect(terminalAccessBusinessState())->toBe($before);
})->with(['string identity', 'future activity', 'reversed activity', 'extra authority', 'non-array']);

it('expires idle or absolute terminal selection before a stale browser can view switch or renew it', function (string $expiry, string $operation): void {
    $this->actingAs($this->actor)->withSession(['tenant_id' => $this->other->id]);
    ($this->select)();
    $oldId = session()->getId();
    $oldToken = session()->token();
    if ($expiry === 'idle') {
        $this->travel(TerminalWorkspaceAccess::IDLE_SECONDS)->seconds();
    } else {
        $this->travel(TerminalWorkspaceAccess::MAX_SECONDS)->seconds();
        session()->put(TerminalWorkspaceAccess::SESSION_KEY.'.last_seen_at', now()->timestamp);
        session()->put(TerminalWorkspaceAccess::CLOCK_KEY.'.last_seen_at', now()->timestamp);
    }
    $response = match ($operation) {
        'select' => ($this->select)(),
        'clear' => $this->post(($this->url)('terminal.clear')),
        'workspaces' => $this->get(($this->url)('terminal.workspaces')),
        default => $this->get(($this->url)('terminal.register')),
    };
    terminalAccessRedirect($response, '/terminal')
        ->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY)->assertSessionMissing('tenant_id');
    $this->assertGuest();
    expect(session()->getId())->not->toBe($oldId)->and(session()->token())->not->toBe($oldToken);
})->with(['idle register' => ['idle', 'register'], 'idle selection' => ['idle', 'select'], 'idle clear' => ['idle', 'clear'],
    'idle chooser' => ['idle', 'workspaces'], 'absolute despite activity' => ['absolute', 'register']]);

it('denies tenant query and header overrides before altering an existing selection', function (string $override): void {
    $this->actingAs($this->actor);
    ($this->select)();
    $before = session(TerminalWorkspaceAccess::SESSION_KEY);
    $url = ($this->url)('terminal.register');
    if (str_starts_with($override, 'header:')) {
        $this->withHeader(substr($override, 7), (string) $this->other->id)->get($url)->assertNotFound();
    } else {
        $this->call('GET', $url.'?'.$override, [], [], [], ['QUERY_STRING' => $override])->assertNotFound();
    }
    expect(session(TerminalWorkspaceAccess::SESSION_KEY))->toBe($before);
})->with(['tenant=other', 'tenant_id=16', 'unused=', '&&', 'header:X-Tenant', 'header:X-Tenant-Id']);

it('rejects ambient selectors on workspace selection POST before they can replace the chosen workspace', function (): void {
    $this->actor->tenants()->attach($this->other->id, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($this->actor);
    ($this->select)();
    $selection = session(TerminalWorkspaceAccess::SESSION_KEY);
    $before = terminalAccessBusinessState();
    $this->postJson(($this->url)('terminal.select').'?tenant='.$this->other->slug, ['tenant_id' => $this->other->id])->assertNotFound();
    $this->withHeader('X-Tenant-Id', (string) $this->other->id)
        ->postJson(($this->url)('terminal.select'), ['tenant_id' => $this->other->id])->assertNotFound();
    expect(session(TerminalWorkspaceAccess::SESSION_KEY))->toBe($selection)->and(terminalAccessBusinessState())->toBe($before);
});

it('accepts no extra selection fields as role grants routing or global workspace changes', function (): void {
    $this->actingAs($this->actor)->withSession(['tenant_id' => $this->other->id]);
    $before = terminalAccessBusinessState();
    $this->postJson(($this->url)('terminal.select'), ['tenant_id' => $this->watershed->id, 'role' => 'owner', 'user_id' => $this->actor->id,
        'merchant_id' => 'acct_fictional_injected', 'redirect' => 'https://untrusted.example.test'])->assertUnprocessable()
        ->assertSessionMissing(TerminalWorkspaceAccess::SESSION_KEY)->assertSessionHas('tenant_id', $this->other->id);
    expect(terminalAccessBusinessState())->toBe($before);
});

it('denies tenant public and untrusted hosts without bootstrapping even a global platform operator', function (): void {
    $this->actor->tenants()->detach();
    $this->actor->update(['role' => 'platform_admin']);
    $this->actingAs($this->actor);
    foreach (['theeverbranch.com', $this->watershed->slug.'.theeverbranch.com', 'untrusted.example.test'] as $host) {
        $this->get('https://'.$host.'/terminal')->assertNotFound();
    }
    $this->postJson(($this->url)('terminal.select'), ['tenant_id' => $this->watershed->id])->assertForbidden();
    expect(DB::table('tenant_user')->where('user_id', $this->actor->id)->count())->toBe(0);
});

it('shows only the selected workspace saved status to owners and admins while staff see no financial details', function (string $role): void {
    $alpha = Tenant::query()->forceCreate(['id' => 16, 'name' => 'PRIVATE fictional Alpha16', 'slug' => 'private-fictional-alpha16']);
    $account = TenantPaymentAccount::query()->create(['tenant_id' => $this->watershed->id, 'provider_account_id' => 'acct_PRIVATE_WATERSHED_ROUTING',
        'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => false, 'last_checked_at' => '2026-10-20 10:15:00',
        'metadata' => ['routing_mode' => 'direct', 'private_fixture' => 'PRIVATE selected account metadata']]);
    TenantPaymentAccount::query()->create(['tenant_id' => $alpha->id, 'provider_account_id' => 'acct_PRIVATE_ALPHA16_ROUTING',
        'status' => 'restricted', 'charges_enabled' => false, 'payouts_enabled' => true,
        'metadata' => ['private_fixture' => 'PRIVATE foreign account metadata']]);
    $this->actor->tenants()->updateExistingPivot($this->watershed->id, ['role' => $role]);
    $before = terminalAccessBusinessState();
    $this->actingAs($this->actor);
    ($this->select)();
    $response = $this->get(($this->url)('terminal.register'))->assertOk()->assertSeeText($this->watershed->name)
        ->assertSeeText('Live payments unavailable')->assertDontSeeText($alpha->name)
        ->assertDontSee('acct_PRIVATE_WATERSHED_ROUTING', false)->assertDontSee('acct_PRIVATE_ALPHA16_ROUTING', false)
        ->assertDontSee('PRIVATE selected account metadata', false)->assertDontSee('PRIVATE foreign account metadata', false);
    if (in_array($role, ['owner', 'tenant_owner', 'admin'], true)) {
        $response->assertSeeText('Saved Stripe connection')->assertViewHas('canManagePayments', true)
            ->assertViewHas('connection', fn ($connection) => is_array($connection) && $connection['cardsEnabled'] === true
                && $connection['payoutsEnabled'] === false && $connection['checkedAt'] === 'Oct 20, 2026 10:15 UTC'
                && $connection['identityLabel'] === 'Saved merchant connection for '.$this->watershed->name
                && ! in_array('acct_PRIVATE_WATERSHED_ROUTING', $connection, true));
    } else {
        $response->assertDontSeeText('Saved Stripe connection')->assertViewHas('canManagePayments', false)->assertViewHas('connection', null);
    }
    expect(terminalAccessBusinessState())->toBe($before)->and($account->fresh()->charges_enabled)->toBeTrue()
        ->and($account->fresh()->payouts_enabled)->toBeFalse();
})->with(['owner', 'tenant_owner', 'admin', 'manager', 'member']);

it('permits the production readiness shell without enabling private POS or providing payment command endpoints', function (): void {
    $this->actingAs($this->actor)->withSession([TerminalWorkspaceAccess::SESSION_KEY => ($this->selection)()]);
    config()->set(['pos.enabled' => true, 'pos.tenant_ids' => [$this->watershed->id]]);
    $before = terminalAccessBusinessState();
    app()->instance('env', 'production');
    try {
        $response = $this->get(($this->url)('terminal.register'))->assertOk()->assertSeeText('Live payments unavailable')
            ->assertViewHas('practiceUrl', null);
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
        try {
            app(PosAccess::class)->authorize($this->watershed, $this->actor);
            test()->fail('The readiness shell must not change production payment authorization.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(404);
        }
        $this->get('https://localhost/terminal')->assertNotFound();
    } finally {
        app()->instance('env', 'testing');
    }
    foreach (['/terminal/sales', '/terminal/payments', '/terminal/refunds'] as $path) {
        $this->postJson('https://app.theeverbranch.com'.$path, ['amount_cents' => 1])->assertNotFound();
    }
    expect(terminalAccessBusinessState())->toBe($before)->and(config('pos.live_payments_enabled'))->toBeFalse()
        ->and(config('pos.terminal_enabled'))->toBeFalse();
});
