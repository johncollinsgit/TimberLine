<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('resumes HighLevel Fleet after MySQL retains the first table and device column', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }
    foreach (['tenants', 'users', 'integration_connections', 'fleet_tracking_devices'] as $name) {
        if (! Schema::hasTable($name)) {
            Schema::create($name, fn (Blueprint $table) => $table->id());
        }
    }
    $migration = require database_path('migrations/2026_10_04_220000_create_highlevel_fleet_tables.php');
    $migration->up();
    foreach (['highlevel_oauth_states', 'highlevel_sessions', 'highlevel_webhook_events', 'highlevel_user_bindings', 'fleet_provider_device_claims', 'highlevel_installations'] as $name) {
        Schema::dropIfExists($name);
    }
    Schema::table('fleet_tracking_devices', fn (Blueprint $table) => $table->dropIndex('fleet_device_connection_idx'));
    // The authorization table and device column survived failed migration DDL.
    expect(Schema::hasTable('highlevel_authorizations'))->toBeTrue()
        ->and(Schema::hasColumn('fleet_tracking_devices', 'integration_connection_id'))->toBeTrue();
    $migration->up();
    $migration->up();
    foreach (['highlevel_authorizations', 'highlevel_installations', 'highlevel_user_bindings', 'highlevel_sessions', 'highlevel_oauth_states', 'highlevel_webhook_events', 'fleet_provider_device_claims'] as $name) {
        expect(Schema::hasTable($name))->toBeTrue();
    }
    expect(Schema::hasIndex('fleet_tracking_devices', 'fleet_device_connection_idx'))->toBeTrue();
});
