<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), '/codex-team-search-')) {
    throw new RuntimeException('Disposable search browser database only.');
}
require base_path('tests/Support/TeamFixtures.php');
[$tenant, $owner] = teamWorkspace();
$tenant->update(['name' => 'Fictional Evergreen Events and Seasonal Makers Collective', 'slug' => 'fictional-search']);
$owner->update(['name' => 'Fictional Owner', 'email' => 'owner@fixture.invalid', 'email_verified_at' => now(), 'password' => Illuminate\Support\Facades\Hash::make('Fictional-local-only-2026!')]);
[$other] = teamWorkspace();
$other->update(['name' => 'Fictional Long Workspace Name for the Regional Arts and Community Events Team', 'slug' => 'fictional-long-workspace']);
$other->users()->attach($owner->id, ['role' => 'owner', 'membership_active' => true]);
$area = App\Models\TeamArea::create(['id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Events', 'slug' => 'events', 'profile' => 'events', 'settings' => ['source_authority' => 'teams']]);
$event = App\Models\TeamOperationRecord::create(['id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'source_key' => hash('sha256', 'fictional-seasonal-market'), 'kind' => 'event',
    'title' => 'Fictional seasonal makers and home goods weekend market', 'status' => 'accepted', 'data' => ['notes' => 'Copper lantern setup'], 'assigned_user_id' => $owner->id, 'visibility' => 'area']);
echo json_encode(['tenant' => $tenant->slug, 'tenant_id' => (string) $tenant->id, 'allowlist' => $tenant->id.','.$other->id, 'area_id' => $area->id, 'event_id' => $event->id], JSON_THROW_ON_ERROR);
