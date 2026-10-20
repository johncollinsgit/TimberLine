<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\MarketplaceAccess;
use App\Services\Navigation\UnifiedAppNavigationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config(['app.url' => 'https://app.theeverbranch.com', 'marketplace.enabled' => false, 'marketplace.checkout_enabled' => false, 'marketplace.host' => '', 'marketplace.account_preview_user_ids' => []]);
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
    $this->viewer = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->previewUrl = 'https://app.theeverbranch.com/marketplace/preview';
});

test('each section opens only as a private example-data preview with global marketplace off', function (string $tab): void {
    config(['marketplace.account_preview_user_ids' => [$this->viewer->id]]);
    $response = $this->actingAs($this->viewer)->get($this->previewUrl.'?tab='.$tab)
        ->assertOk()->assertSeeText('Private account preview')->assertSeeText('Example workspace')
        ->assertSeeText('No public storefront or payment collection')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertDontSee('name="admin_token"', false)->assertDontSee('name="payment_method_token"', false)
        ->assertDontSee('<form', false);
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    expect(config('marketplace.enabled'))->toBeFalse()->and(config('marketplace.checkout_enabled'))->toBeFalse();
    Http::assertNothingSent();
})->with(['overview', 'makers', 'listings', 'orders', 'customers', 'discounts', 'markets', 'pickups', 'rates', 'statements', 'payments', 'connections', 'activity']);

test('guest must use normal login and the requested preview remains the intended location', function (): void {
    config(['marketplace.account_preview_user_ids' => [$this->viewer->id]]);
    $this->get($this->previewUrl)->assertRedirect(route('login'))->assertSessionHas('url.intended', $this->previewUrl);
});

test('default-off and other accounts remain denied even with platform and merchant roles', function (): void {
    $this->actingAs($this->viewer)->get($this->previewUrl)->assertNotFound();
    config(['marketplace.account_preview_user_ids' => [$this->viewer->id]]);
    $other = User::factory()->platformAdmin()->create(['is_active' => true, 'email_verified_at' => now()]);
    config(['marketplace.operator_user_ids' => [$other->id], 'marketplace.enabled' => true]);
    $this->actingAs($other)->get($this->previewUrl)->assertNotFound();
});

test('the grant binds an exact account id rather than email similarity or workspace membership', function (): void {
    $tenant = Tenant::create(['name' => 'Existing business', 'slug' => 'preview-shared-business']);
    $other = User::factory()->create(['email' => 'john+other@example.test', 'is_active' => true, 'email_verified_at' => now()]);
    foreach ([$this->viewer, $other] as $user) {
        $user->tenants()->attach($tenant, ['role' => 'admin', 'membership_active' => true]);
    }
    config(['marketplace.account_preview_user_ids' => [$this->viewer->id]]);
    $this->actingAs($other)->get($this->previewUrl)->assertNotFound();
    $this->actingAs($this->viewer)->get($this->previewUrl)->assertOk();
    config(['marketplace.account_preview_user_ids' => [(string) $this->viewer->id]]);
    $this->get($this->previewUrl)->assertNotFound();
});

test('inactive and unverified accounts cannot use their preview grant', function (array $attributes): void {
    config(['marketplace.account_preview_user_ids' => [$this->viewer->id]]);
    $this->viewer->forceFill($attributes)->save();
    $this->actingAs($this->viewer)->get($this->previewUrl)->assertNotFound();
})->with([[['is_active' => false]], [['email_verified_at' => null]]]);

test('preview does not select tenants or stores and rejects foreign scope parameters', function (string $key): void {
    config(['marketplace.account_preview_user_ids' => [$this->viewer->id]]);
    $tenant = Tenant::create(['name' => 'Private foreign business', 'slug' => 'preview-foreign-business']);
    $this->actingAs($this->viewer)->withSession(['tenant_id' => 17])
        ->get($this->previewUrl.'?'.$key.'='.$tenant->id)->assertNotFound()->assertSessionHas('tenant_id', 17);
})->with(['tenant', 'tenant_id', 'store', 'store_id']);

test('preview cannot open merchant management, providers, payments or public storefront', function (): void {
    config(['marketplace.account_preview_user_ids' => [$this->viewer->id]]);
    $this->actingAs($this->viewer);
    foreach (['/marketplace/operations', '/marketplace/workspaces/1'] as $path) {
        $this->get('https://app.theeverbranch.com'.$path)->assertNotFound();
    }
    foreach (['/marketplace/operations/settings', '/marketplace/operations/connection', '/marketplace/operations/wallet', '/marketplace/operations/refunds/1'] as $path) {
        $this->post('https://app.theeverbranch.com'.$path, [])->assertNotFound();
    }
    $this->post($this->previewUrl)->assertStatus(405);
    expect(app(MarketplaceAccess::class)->publicHost('app.theeverbranch.com'))->toBeFalse();
    $this->assertDatabaseCount('marketplace_stores', 0);
    $this->assertDatabaseCount('marketplace_settings', 0);
    $this->assertDatabaseCount('marketplace_checkouts', 0);
    $this->assertDatabaseCount('tenant_module_entitlements', 0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

test('preview preserves current workspace and all membership roles and reads no merchant records', function (): void {
    $tenant = Tenant::create(['name' => 'Existing owner workspace', 'slug' => 'preview-existing-owner']);
    $this->viewer->tenants()->attach($tenant, ['role' => 'owner', 'membership_active' => true]);
    $before = DB::table('tenant_user')->get()->toJson();
    config(['marketplace.account_preview_user_ids' => [$this->viewer->id]]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $this->actingAs($this->viewer)->withSession(['tenant_id' => $tenant->id])->get($this->previewUrl)
        ->assertOk()->assertSessionHas('tenant_id', $tenant->id)->assertDontSeeText($tenant->name);
    foreach ($queries as $query) {
        expect($query)->not->toMatch('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i')
            ->and($query)->not->toContain('marketplace_', 'tenant_payment_accounts', 'trajectory_');
    }
    expect(DB::table('tenant_user')->get()->toJson())->toBe($before);
    Http::assertNothingSent();
});

test('only the account receives the console entry and existing console destinations stay unchanged', function (): void {
    $tenant = Tenant::create(['name' => 'Existing console', 'slug' => 'preview-existing-console']);
    $this->viewer->tenants()->attach($tenant, ['role' => 'admin', 'membership_active' => true]);
    $request = Illuminate\Http\Request::create('https://app.theeverbranch.com/dashboard');
    $request->attributes->set('current_tenant', $tenant);
    $request->setUserResolver(fn () => $this->viewer);
    $this->app->instance('request', $request);
    $before = app(UnifiedAppNavigationService::class)->build($request, $this->viewer)['console_switches'];
    config(['marketplace.account_preview_user_ids' => [$this->viewer->id]]);
    $after = app(UnifiedAppNavigationService::class)->build($request, $this->viewer)['console_switches'];
    expect(array_slice($after, 0, -1))->toBe($before);
    $entry = end($after);
    expect($entry['key'])->toBe('marketplace-preview')->and($entry['label'])->toBe('Markets & Makers')
        ->and($entry['href'])->toBe($this->previewUrl)->and($entry['descriptor'])->toBe('Private account preview');
    config(['marketplace.account_preview_user_ids' => []]);
    expect(app(UnifiedAppNavigationService::class)->build($request, $this->viewer)['console_switches'])->toBe($before);
});

test('preview refuses other hosts and invalid sections', function (): void {
    config(['marketplace.account_preview_user_ids' => [$this->viewer->id]]);
    $this->actingAs($this->viewer)->get('https://unknown.example.test/marketplace/preview')->assertNotFound();
    $this->get($this->previewUrl.'?tab=private')->assertNotFound();
    $this->get($this->previewUrl.'?tab[]=payments')->assertNotFound();
});
