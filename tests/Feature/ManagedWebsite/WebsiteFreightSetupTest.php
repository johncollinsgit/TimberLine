<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteOrder;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteFreightProviderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->tenant = Tenant::query()->create(['name' => 'Freight setup fixture', 'slug' => 'freight-setup-fixture']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [$this->tenant->id],
        'managed_website.freight_booking_enabled' => false]);
    $this->site = app(ManagedWebsiteService::class)->createSite($this->tenant, $this->actor);
    $this->key = 'fictional_freight_setup_key_12345';
    $this->input = ['api_key' => $this->key, 'label' => 'Fixture broker', 'billing_confirmed' => true];
    $this->setupUrl = route('managed-website.commerce.freight', ['tenant' => $this->tenant->id]);
    $this->fakeAccounts = function (array $ids = ['se-4821']): void {
        Http::fake(['https://api.shipstation.com/v2/freight/provider_accounts' => Http::response([
            'freight_provider_accounts' => array_map(fn ($id) => ['freight_provider_account_id' => $id,
                'nickname' => 'Fictional provider', 'freight_provider_code' => 'UNISHIPPERS'], $ids),
        ])]);
    };
});

test('freight setup works before the first order and shows saved readiness without provider calls', function (): void {
    $response = $this->actingAs($this->actor)->get($this->setupUrl)->assertOk()->assertSee('Set up pallet freight')
        ->assertSee('Ship-from location: Needed')->assertSee('Awaiting launch approval')->assertSee('Check original booking')
        ->assertSee(route('managed-website.commerce.freight.store'), false);
    if (getenv('FREIGHT_SETUP_FIXTURES') === '1') {
        $directory = storage_path('framework/testing/freight');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($directory);
        file_put_contents($directory.'/setup.html', $response->getContent());
    }
    expect(WebsiteOrder::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('setup verifies only active accounts without buying freight or activating booking', function (): void {
    ($this->fakeAccounts)();
    $this->actingAs($this->actor)->post($this->setupUrl, $this->input)->assertRedirect(route('managed-website.commerce.freight'));
    $connection = app(WebsiteFreightProviderService::class)->currentForSite($this->site);
    expect($connection->isConnected())->toBeTrue()->and($connection->access_token)->toBe($this->key)
        ->and(data_get($connection->metadata, 'native_freight.provider_account_id'))->toBe('se-4821')
        ->and(config('managed_website.freight_booking_enabled'))->toBeFalse()->and(WebsiteOrder::query()->count())->toBe(0);
    expect(DB::table('integration_connections')->where('id', $connection->id)->value('access_token'))->not->toContain($this->key);
    $this->get($this->setupUrl)->assertOk()->assertSee('Last account check')->assertDontSee($this->key);
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->hasHeader('api-key', $this->key));
});

test('empty and multiple account lists retain pending setup until a returned provider is selected', function (array $ids): void {
    ($this->fakeAccounts)($ids);
    $this->actingAs($this->actor)->post($this->setupUrl, $this->input)->assertRedirect();
    $provider = app(WebsiteFreightProviderService::class);
    $original = $provider->currentForSite($this->site);
    expect($original->isConnected())->toBeFalse();
    $this->get($this->setupUrl)->assertOk()->assertDontSee($this->key);
    if ($ids) {
        $this->post($this->setupUrl, ['label' => 'Chosen fixture', 'provider_account_id' => $ids[1], 'billing_confirmed' => true])->assertRedirect();
        expect($provider->currentForSite($this->site)->isConnected())->toBeTrue()
            ->and(data_get($provider->currentForSite($this->site)->metadata, 'native_freight.provider_account_id'))->toBe($ids[1])
            ->and($original->fresh()->access_token)->toBe($this->key);
    }
})->with(['none' => [[]], 'multiple' => [['se-4821', 'se-4822']]]);

test('failed provider checks never flash keys or replace saved credential evidence', function (): void {
    Http::fake(['*' => Http::response(['errors' => [['message' => $this->key]]], 401)]);
    $this->actingAs($this->actor)->from($this->setupUrl)->post($this->setupUrl, $this->input)->assertRedirect($this->setupUrl)->assertSessionHasErrors();
    expect(session()->getOldInput('api_key'))->toBeNull()->and(IntegrationConnection::query()->count())->toBe(0);
    $this->get($this->setupUrl)->assertDontSee($this->key);
});

test('tenant and financial membership boundaries protect pre-order setup', function (string $role, bool $active): void {
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => $role, 'membership_active' => $active]);
    $this->actingAs($this->actor)->get($this->setupUrl)->assertForbidden();
    $this->post($this->setupUrl, $this->input)->assertForbidden();
    Http::assertNothingSent();
})->with([['manager', true], ['viewer', true], ['admin', false]]);

test('plan and rate-limit failures give recovery guidance without saving a connection', function (int $status, string $message): void {
    Http::fake(['*' => Http::response(['message' => $this->key], $status)]);
    $this->actingAs($this->actor)->from($this->setupUrl)->post($this->setupUrl, $this->input)->assertSessionHasErrors('freight');
    $this->get($this->setupUrl)->assertOk()->assertSee($message)->assertDontSee($this->key);
    expect(IntegrationConnection::query()->count())->toBe(0);
    Http::assertSentCount(1);
})->with([[402, 'review plan access and billing'], [429, 'temporarily limiting requests']]);

test('a different workspace cannot read or reuse this stores freight credentials', function (): void {
    ($this->fakeAccounts)();
    app(WebsiteFreightProviderService::class)->connectSite($this->site, $this->input, $this->actor);
    $other = Tenant::query()->create(['name' => 'Other setup fixture', 'slug' => 'other-setup-fixture']);
    $this->actingAs($this->actor)->get(route('managed-website.commerce.freight', ['tenant' => $other->id]))->assertForbidden();
    Http::assertSentCount(1);
});
