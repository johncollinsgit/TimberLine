<?php

namespace Tests\Feature\Enterprise;

use App\Foundation\EnterprisePosSchema;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pos\PosStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class EnterprisePosRuntimeTest extends TestCase
{
    private string|false $oldFlag;

    public function createApplication()
    {
        $this->oldFlag = getenv('ENTERPRISE_POS_ENABLED');
        putenv('ENTERPRISE_POS_ENABLED=true');
        $app = require dirname(__DIR__, 3).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set(['app.env' => 'testing', 'app.url' => 'https://enterprise.example.invalid',
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', 'enterprise_runtime.isolated' => true,
            'enterprise_runtime.base_path' => '', 'enterprise_runtime.trial_mount_only' => false,
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'pos_live.enabled' => true, 'pos_live.runtime_id' => 'isolated_enterprise_pos_fixture',
            'pos_live.collection_enabled' => false, 'session.driver' => 'array', 'cache.default' => 'array']);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
        EnterprisePosSchema::install();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            putenv($this->oldFlag === false ? 'ENTERPRISE_POS_ENABLED' : 'ENTERPRISE_POS_ENABLED='.$this->oldFlag);
        }
    }

    private function fixture(string $role): array
    {
        $tenant = Tenant::create(['name' => 'Fictional Enterprise', 'slug' => 'enterprise-pos-fixture']);
        $user = User::create(['name' => 'Fictional staff', 'email' => 'pos@example.invalid', 'password' => bcrypt('fixture-password'), 'is_active' => true, 'email_verified_at' => now()]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->tenants()->attach($tenant, ['role' => $role, 'membership_active' => true]);
        $store = app(PosStore::class);
        foreach (['a', 'b'] as $index => $character) {
            $id = str_repeat($character, 32);
            $store->rows('registers', $tenant->id)->insert($store->values($tenant->id, ['id' => $id, 'name' => 'Counter '.$character,
                'location_id' => 'location_'.$character, 'location_name' => 'Location '.$character, 'connection_id' => str_repeat('c', 32),
                'catalog_source' => 'native', 'configuration' => $store->encrypt([]), 'servicing_enabled' => true]));
            $store->rows('items', $tenant->id)->insert($store->values($tenant->id, ['id' => $id, 'location_id' => 'location_'.$character,
                'sku' => 'FIXTURE-'.$character, 'title' => 'Fictional item', 'kind' => 'merchandise', 'unit_amount_minor' => 100,
                'tax_bps' => 0, 'tax_basis' => 'Fictional evidence', 'tax_reviewed_at' => now(), 'reviewed_by' => $user->id,
                'revision' => str_repeat('d', 64), 'tracked' => true, 'quantity' => 5, 'active' => true]));
        }
        $store->rows('assignments', $tenant->id)->insert($store->values($tenant->id, ['register_id' => str_repeat('a', 32),
            'user_id' => $user->id, 'device_id' => 'assigned_enterprise_fixture', 'role' => 'manager', 'active' => true]));
        $this->actingAs($user);

        return [$tenant, $user, $store];
    }

    public function test_pos_composes_only_required_shared_components_and_its_own_database(): void
    {
        $this->assertTrue(Route::has('enterprise.pos.api.workspace.pos.registers'));
        foreach (['orders', 'shopify_stores', 'website_orders', 'square_payments', 'pos_registers'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        foreach (['shopify.app', 'platform.demos.pos', 'billing.checkout'] as $route) {
            $this->assertFalse(Route::has($route));
        }
        $this->assertSame('enterprise', config('database.default'));
        $this->assertNull(config('pos_live.square_application_secret'));
        $this->assertArrayNotHasKey(\App\Providers\AppServiceProvider::class, $this->app->getLoadedProviders());
        $this->assertTrue(Schema::hasTable('payment_core_operations'));
    }

    public function test_location_manager_cannot_read_or_adjust_the_second_location(): void
    {
        [$tenant, $user, $store] = $this->fixture('manager');
        $this->getJson('/enterprise/pos/report?tenant='.$tenant->slug.'&from='.date('Y-m-d').'&to='.date('Y-m-d'))
            ->assertOk()->assertJsonCount(1, 'locations')->assertJsonPath('locations.0.location_id', 'location_a');
        $input = ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'delta' => 2, 'reason' => 'Fictional stock count'];
        $this->post('/enterprise/pos/items/'.str_repeat('b', 32).'/adjust?tenant='.$tenant->slug, $input)->assertNotFound();
        $this->post('/enterprise/pos/items/'.str_repeat('a', 32).'/adjust?tenant='.$tenant->slug, $input)->assertRedirect();
        $this->post('/enterprise/pos/items/'.str_repeat('a', 32).'/adjust?tenant='.$tenant->slug, $input)->assertRedirect();
        $this->assertSame(7, (int) $store->rows('items', $tenant->id)->where('id', str_repeat('a', 32))->value('quantity'));
        $this->post('/enterprise/pos/pause?tenant='.$tenant->slug)->assertForbidden();
    }

    public function test_organization_admin_sees_both_locations_and_can_pause_collection_without_losing_servicing(): void
    {
        [$tenant, $user, $store] = $this->fixture('admin');
        $this->getJson('/enterprise/pos/report?tenant='.$tenant->slug.'&from='.date('Y-m-d').'&to='.date('Y-m-d'))->assertOk()->assertJsonCount(2, 'locations');
        $store->rows('registers', $tenant->id)->update(['collection_enabled' => true]);
        $this->post('/enterprise/pos/pause?tenant='.$tenant->slug)->assertRedirect();
        $this->assertSame(0, $store->rows('registers', $tenant->id)->where('collection_enabled', true)->count());
        $this->assertSame(2, $store->rows('registers', $tenant->id)->where('servicing_enabled', true)->count());
        DB::table('tenant_user')->where('tenant_id', $tenant->id)->update(['membership_active' => false]);
        $this->getJson('/enterprise/pos/report?tenant='.$tenant->slug.'&from='.date('Y-m-d').'&to='.date('Y-m-d'))->assertForbidden();
    }

    public function test_native_api_does_not_accept_an_administrative_browser_session(): void
    {
        [$tenant] = $this->fixture('admin');
        $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/pos/registers')->assertUnauthorized();
    }

    public function test_staff_web_register_uses_only_its_enterprise_location_and_cannot_authorize_collection(): void
    {
        [$tenant, $user, $store] = $this->fixture('member');
        \App\Models\TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'pos',
            'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped',
            'entitlement_source' => 'test', 'price_source' => 'catalog']);
        $store->rows('connections', $tenant->id)->insert($store->values($tenant->id, ['id' => str_repeat('c', 32),
            'merchant_id' => 'fictional_enterprise_merchant', 'provider_location_id' => 'fictional_enterprise_location',
            'environment' => 'sandbox', 'credentials' => $store->encrypt([])]));
        $this->withSession(['pos_web_device' => 'assigned_enterprise_fixture']);
        $base = '/enterprise/pos/staff/'.$tenant->slug;
        $this->get($base)->assertOk()->assertSee('Staff register');
        $this->get($base.'/register.js')->assertOk()->assertHeader('Content-Type', 'application/javascript');
        $this->getJson($base.'/registers/'.str_repeat('a', 32).'/catalog')->assertOk()->assertJsonPath('items.0.sku', 'FIXTURE-a');
        $this->getJson($base.'/registers/'.str_repeat('b', 32).'/catalog')->assertNotFound();
        $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/pos/registers/'.str_repeat('a', 32).'/authorize-device')->assertUnauthorized();
        $this->assertTrue(Schema::hasTable('pos_live_handoffs'));
        $this->assertFalse(Schema::hasTable('shopify_stores'));
    }

    public function test_native_pkce_exchange_and_assigned_catalog_use_only_enterprise_identity(): void
    {
        [$tenant, $user, $store] = $this->fixture('admin');
        config(['pos_live.native_client_enabled' => true]);
        \App\Models\TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'pos',
            'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped',
            'entitlement_source' => 'test', 'price_source' => 'catalog']);
        $store->rows('connections', $tenant->id)->insert($store->values($tenant->id, ['id' => str_repeat('c', 32),
            'merchant_id' => 'fictional_enterprise_merchant', 'provider_location_id' => 'fictional_enterprise_location',
            'environment' => 'sandbox', 'credentials' => $store->encrypt([])]));
        $verifier = str_repeat('v', 43);
        \App\Models\MobileAuthorizationCode::create(['user_id' => $user->id, 'client_id' => 'everbranch-pos-ios',
            'redirect_uri' => 'everbranchpos://auth/callback', 'code_hash' => hash('sha256', 'fictional_code'),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'expires_at' => now()->addMinutes(5)]);
        $token = $this->postJson('/api/mobile/v1/auth/exchange', ['client_id' => 'everbranch-pos-ios',
            'redirect_uri' => 'everbranchpos://auth/callback', 'code' => 'fictional_code', 'code_verifier' => $verifier])
            ->assertOk()->json('access_token');
        $this->withToken($token)->withHeader('X-Pos-Device', 'assigned_enterprise_fixture')
            ->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/pos/registers/'.str_repeat('a', 32).'/catalog')
            ->assertOk()->assertJsonPath('items.0.sku', 'FIXTURE-a');
        $this->assertFalse(Schema::hasTable('shopify_stores'));
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->withHeader('X-Pos-Device', 'unassigned_enterprise_device')
            ->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/pos/registers/'.str_repeat('b', 32).'/catalog')->assertNotFound();
    }
}
