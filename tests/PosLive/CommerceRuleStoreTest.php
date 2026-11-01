<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Pos\PosCommerceRuleStore;
use App\Services\Pos\PosException;
use App\Services\Pos\PosStore;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

it('keeps rule changes tenant-scoped, audited and protected from stale saves', function (): void {
    config()->set('pos_live.runtime_id', 'fixture_rules_runtime');
    $first = Tenant::create(['name' => 'First', 'slug' => 'rules-a-'.Str::random(8), 'status' => 'active']);
    $second = Tenant::create(['name' => 'Second', 'slug' => 'rules-b-'.Str::random(8), 'status' => 'active']);
    $actor = User::factory()->create();
    $a = ['tenant_id' => $first->id, 'actor_id' => $actor->id, 'admin' => true];
    $b = ['tenant_id' => $second->id, 'actor_id' => $actor->id, 'admin' => true];
    $store = app(PosCommerceRuleStore::class);
    $empty = $store->get($a);
    $rule = ['id' => 'five', 'name' => '$5 off', 'kind' => 'fixed', 'value' => 500,
        'scopes' => ['sale'], 'channels' => ['in_person'], 'active' => true];
    $created = $store->save($a, $rule, $empty['version']);
    expect($store->get($a)['rules'])->toEqual([$rule])->and($store->get($b)['rules'])->toBe([])
        ->and(app(PosStore::class)->rows('events', $first->id)->where('code', 'checkout_rules_updated')->count())->toBe(1)
        ->and(app(PosStore::class)->rows('events', $second->id)->count())->toBe(0);
    expect(fn () => $store->delete($a, 'five', $empty['version']))->toThrow(PosException::class);
    expect(fn () => $store->save([...$a, 'admin' => false], $rule, $created['version']))->toThrow(PosException::class);
    $foreign = str_repeat('c', 32);
    app(\App\Services\Inventory\StockLedger::class)->rows('items', $second->id)->insert([
        'id' => $foreign, 'tenant_id' => $second->id, 'sku' => 'FOREIGN', 'title' => 'Other tenant item',
        'product_key' => 'fixture', 'revision' => str_repeat('d', 64), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $addon = ['id' => 'addon', 'name' => 'Add-on', 'kind' => 'addon', 'value' => 1500,
        'scopes' => ['item'], 'channels' => ['in_person'], 'active' => true,
        'eligible_item_ids' => [$foreign], 'requires_other_item' => true];
    expect(fn () => $store->save($a, $addon, $created['version']))->toThrow(PosException::class);
    expect($store->delete($a, 'five', $created['version'])['rules'])->toBe([]);
});

it('allows only assigned tenant administrators to edit rule sets through the mobile API', function (): void {
    config()->set(['pos_live.enabled' => true, 'pos_live.runtime_id' => 'fixture_rules_runtime']);
    $tenant = Tenant::create(['name' => 'Fixture', 'slug' => 'rules-api-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $admin = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $admin->tenants()->attach($tenant, ['role' => 'admin', 'membership_active' => true]);
    $store = app(PosStore::class);
    $register = str_repeat('a', 32);
    $connection = str_repeat('b', 32);
    $store->rows('connections', $tenant->id)->insert($store->values($tenant->id, ['id' => $connection,
        'merchant_id' => 'fictional', 'provider_location_id' => 'fictional', 'environment' => 'sandbox',
        'credentials' => $store->encrypt([]), 'active' => true]));
    $store->rows('registers', $tenant->id)->insert($store->values($tenant->id, ['id' => $register, 'name' => 'Fixture',
        'location_id' => 'fixture', 'location_name' => 'Fixture', 'connection_id' => $connection,
        'catalog_source' => 'native', 'configuration' => $store->encrypt([]), 'active' => true]));
    $store->rows('assignments', $tenant->id)->insert($store->values($tenant->id, ['register_id' => $register,
        'user_id' => $admin->id, 'device_id' => 'fixture_device_1234', 'role' => 'admin', 'active' => true]));
    $token = $admin->createToken('fixture', ['mobile:read', 'mobile:write', 'payments:read', 'payments:write'], now()->addDay())->plainTextToken;
    $this->withToken($token)->withHeader('X-Pos-Device', 'fixture_device_1234');
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/pos/registers/'.$register.'/checkout-rules';
    $version = $this->getJson($base)->assertOk()->json('version');
    $rule = ['id' => 'five', 'name' => '$5 off', 'kind' => 'fixed', 'value' => 500,
        'scopes' => ['sale'], 'channels' => ['in_person'], 'active' => true];
    $saved = $this->postJson($base, ['version' => $version, 'rule' => $rule])->assertOk()->json();
    $this->postJson($base, ['version' => $version, 'rule' => $rule])->assertStatus(409);
    $this->getJson($base.'/review')->assertOk()->assertJsonPath('version', $saved['version'])
        ->assertJsonPath('reviewed_version', null)->assertJsonPath('checkout_enabled', false);
    $review = ['version' => $saved['version'], 'previous_reviewed_version' => null, 'confirmed' => true];
    $this->postJson($base.'/publish', [...$review, 'confirmed' => false])->assertStatus(422);
    $this->postJson($base.'/publish', [...$review, 'version' => $version])->assertStatus(409);
    $this->postJson($base.'/publish', $review)->assertOk()->assertJsonPath('reviewed_version', $saved['version'])
        ->assertJsonPath('checkout_enabled', false);
    $this->getJson($base.'/available')->assertOk()->assertJsonPath('version', null)->assertJsonPath('rules', []);
    config(['pos_live.checkout_rules_enabled' => true]);
    $this->getJson($base.'/available')->assertOk()->assertJsonPath('version', $saved['version'])->assertJsonPath('rules.0.id', 'five');
    $this->postJson($base.'/publish', $review)->assertStatus(409);
    $review['previous_reviewed_version'] = $saved['version'];
    $this->postJson($base.'/publish', $review)->assertOk();
    $setting = $store->decrypt($store->rows('registers', $tenant->id)->where('id', $register)->first()->configuration);
    expect($setting['checkout_rules_review']['rules'])->toEqual([$rule])
        ->and($setting['checkout_rules_review']['actor_id'])->toBe($admin->id)
        ->and($store->rows('events', $tenant->id)->where('code', 'checkout_rules_published')->count())->toBe(1);
    $this->deleteJson($base.'/five', ['version' => $saved['version']])->assertOk()->assertJsonPath('rules', []);
    $this->getJson($base.'/review')->assertOk()->assertJsonPath('reviewed_version', $saved['version'])->assertJsonPath('rules', []);
    $this->getJson($base.'/available')->assertStatus(503)->assertJsonPath('error.code', 'checkout_rules_review_required');
    $this->postJson($base.'/publish', $review)->assertStatus(409);
    $otherBase = '/api/mobile/v1/workspaces/'.$tenant->slug.'/pos/registers/'.str_repeat('e', 32).'/checkout-rules';
    $this->postJson($otherBase.'/publish', $review)->assertStatus(404);
    $admin->tenants()->updateExistingPivot($tenant->id, ['role' => 'member']);
    $this->getJson($base)->assertStatus(403);
    $this->getJson($base.'/review')->assertStatus(403);
    $this->postJson($base.'/publish', $review)->assertStatus(403);
});

it('keeps mysql json rule versions and reviewed snapshots stable across round trips', function (): void {
    if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires an explicitly authorized disposable MySQL database.');
    }
    config(['pos_live.runtime_id' => 'fixture_rules_mysql', 'pos_live.checkout_rules_enabled' => true]);
    $tenant = Tenant::create(['name' => 'MySQL rules', 'slug' => 'rules-mysql-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $actor = User::factory()->create();
    $scope = ['tenant_id' => (int) $tenant->id, 'actor_id' => (int) $actor->id, 'admin' => true];
    $pos = app(PosStore::class);
    $register = str_repeat('c', 32);
    $connection = str_repeat('d', 32);
    $pos->rows('connections', $tenant->id)->insert($pos->values($tenant->id, ['id' => $connection,
        'merchant_id' => 'fictional', 'provider_location_id' => 'fictional', 'environment' => 'sandbox',
        'credentials' => $pos->encrypt([]), 'active' => true]));
    $pos->rows('registers', $tenant->id)->insert($pos->values($tenant->id, ['id' => $register, 'name' => 'Fixture',
        'location_id' => 'fixture', 'location_name' => 'Fixture', 'connection_id' => $connection,
        'catalog_source' => 'native', 'configuration' => $pos->encrypt(['unrelated' => 'retained']), 'active' => true]));
    $store = app(PosCommerceRuleStore::class);
    $initial = $store->get($scope);
    $rule = ['name' => 'Teacher', 'value' => 1000, 'active' => true, 'channels' => ['in_person'],
        'id' => 'teacher', 'scopes' => ['item', 'sale'], 'kind' => 'percent'];
    $saved = $store->save($scope, $rule, $initial['version']);
    $roundtrip = $store->get($scope);
    expect($roundtrip)->toBe($saved);
    $published = $store->publish($scope, $register, $saved['version'], null, true);
    $row = $pos->rows('registers', $tenant->id)->where('id', $register)->firstOrFail();
    $configuration = $pos->decrypt($row->configuration);
    expect($published['reviewed_version'])->toBe($saved['version'])
        ->and($configuration['checkout_rules_review']['rules'])->toBe($saved['rules'])
        ->and($configuration['unrelated'])->toBe('retained')
        ->and(app(\App\Services\Pos\PosCheckoutOffers::class)->available(['tenant_id' => $tenant->id, 'register' => $row])['version'])->toBe($saved['version']);
    $store->publish($scope, $register, $saved['version'], $saved['version'], true);
    expect($pos->rows('events', $tenant->id)->where('code', 'checkout_rules_published')->count())->toBe(1);
});
