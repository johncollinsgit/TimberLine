<?php

use App\Models\FieldServiceMaterial;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Disposable local MySQL required.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Refusing material review recovery outside disposable local MySQL.');
    }
});

test('review request key index repairs after partial DDL and preserves the original material request', function (): void {
    $migration = require database_path('migrations/2026_10_28_223000_add_field_material_review_idempotency.php');
    $migration->up();
    $tenant = Tenant::create(['name' => 'Material review recovery', 'slug' => 'material-review-'.bin2hex(random_bytes(5))]);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $uuid = (string) Illuminate\Support\Str::uuid();
    $material = FieldServiceMaterial::create(['tenant_id' => $tenant->id, 'requested_by_user_id' => $user->id,
        'review_client_uuid' => $uuid, 'name' => '4 square boxes', 'quantity' => 2, 'status' => 'needed']);
    $original = (array) DB::table('field_service_materials')->where('id', $material->id)->first();

    Schema::table('field_service_materials', fn ($table) => $table->dropUnique('fs_material_review_idempotency_uq'));
    $migration->up();
    $migration->up();

    expect(Schema::hasIndex('field_service_materials', 'fs_material_review_idempotency_uq'))->toBeTrue()
        ->and((array) DB::table('field_service_materials')->where('id', $material->id)->first())->toBe($original);
    try {
        FieldServiceMaterial::create(['tenant_id' => $tenant->id, 'requested_by_user_id' => $user->id,
            'review_client_uuid' => $uuid, 'name' => 'Different material', 'quantity' => 1, 'status' => 'needed']);
        test()->fail('The unique review key must reject a duplicate write.');
    } catch (QueryException) {
        expect(FieldServiceMaterial::where('tenant_id', $tenant->id)->where('requested_by_user_id', $user->id)->count())->toBe(1);
    } finally {
        FieldServiceMaterial::where('tenant_id', $tenant->id)->delete();
        $user->delete();
        $tenant->delete();
    }
});
