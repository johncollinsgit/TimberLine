<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), '/codex-team-calendar-')) {
    throw new RuntimeException('Disposable calendar browser DB only.');
}
require base_path('tests/Support/TeamStudioFixtures.php');
[$tenant, $owner] = teamWorkspace();
$tenant->update(['slug' => 'fictional-calendar']);
$owner->update(['name' => 'Fictional Owner', 'email' => 'owner@fixture.invalid', 'email_verified_at' => now(), 'password' => Illuminate\Support\Facades\Hash::make('Fictional-local-only-2026!')]);
$area = studioArea($tenant);
$area->update(['settings' => ['source_authority' => 'teams', 'stages' => config('team_operations.profiles.events.stages')]]);
$sessions = [
    ['id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'setup', 'date' => '2026-11-05', 'opens' => '10:00', 'closes' => '19:00', 'label' => 'Organizer setup window'],
    ['id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'selling', 'date' => '2026-11-06', 'opens' => '10:00', 'closes' => '18:00'],
    ['id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'selling', 'date' => '2026-11-08', 'opens' => '11:00', 'closes' => '17:00'],
];
$make = fn ($title, $data, $start, $end) => App\Models\TeamOperationRecord::create(['id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
    'source_key' => hash('sha256', $title), 'kind' => 'event', 'title' => $title, 'status' => 'accepted', 'data' => $data, 'starts_on' => $start, 'ends_on' => $end, 'assigned_user_id' => $owner->id, 'visibility' => 'area']);
$event = $make('Fictional seasonal makers and home goods weekend market', ['sessions' => $sessions, 'schedule_complete' => true, 'calendar_color' => '#325b91', 'timezone' => 'America/New_York', 'source_schedule' => ['due_on' => '2026-11-06']], '2026-11-05', '2026-11-08');
$make('Fictional legacy range', [], '2026-11-06', '2026-11-15');
$make('Fictional partial travel', ['sessions' => [['id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'travel', 'date' => null], ['id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'travel', 'date' => '2026-11-10']], 'schedule_complete' => false], null, null);
if (getenv('TEAM_CALENDAR_WINDOW_FIXTURE') === '1') {
    for ($i = 0; $i < 40; $i++) {
        $make('Historical padding '.sprintf('%03d', $i), [], '2022-02-01', '2022-02-02');
    }
    for ($i = 0; $i < 92; $i++) {
        $make('Current page event '.sprintf('%03d', $i), ['sessions' => [['id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'selling', 'date' => '2026-11-12']]], '2026-11-12', '2026-11-12');
    }
    $make('Fictional reviewed outlier', ['_teams_enrichment' => ['calendar' => ['sessions' => [['id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'setup', 'date' => '2026-10-31'], ['id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'selling', 'date' => '2026-11-08']], 'schedule_complete' => true]], 'source_schedule' => ['due_on' => '2026-11-10']], '2022-02-01', '2022-02-02');
    $make('Fictional due anchor', ['source_schedule' => ['due_on' => '2026-11-09']], null, null);
    // A real scan-limited partial page: 30 + 30 + 20, followed by a full page.
    for ($i = 0; $i < 80; $i++) {
        $make('Partial batch current '.sprintf('%03d', $i), ['sessions' => [['id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'selling', 'date' => '2026-11-12']]], '2026-11-12', '2026-11-12');
    }
    for ($i = 0; $i < 120; $i++) {
        $make('Partial batch excluded '.sprintf('%03d', $i), ['sessions' => [['id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'selling', 'date' => '2026-12-20']]], '2026-11-20', '2026-11-20');
    }
    for ($i = 0; $i < 60; $i++) {
        $make('Partial batch historical '.sprintf('%03d', $i), [], '2021-02-01', '2021-02-02');
    }
    for ($i = 0; $i < 31; $i++) {
        $make('Partial batch outlier '.sprintf('%03d', $i), ['sessions' => [['id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'selling', 'date' => '2026-11-12']]], '2022-02-01', '2022-02-02');
    }
}
echo json_encode(['tenant' => $tenant->slug, 'tenant_id' => (string) $tenant->id, 'area_id' => $area->id, 'event_id' => $event->id, 'person_id' => 'native:'.$owner->id], JSON_THROW_ON_ERROR);
