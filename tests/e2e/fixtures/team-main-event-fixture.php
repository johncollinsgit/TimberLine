<?php

// Reuse the disposable-browser guard and baseline setup.
ob_start();
require __DIR__.'/../../../scripts/everbranch-team/operations-calendar-browser-fixture.php';
$fixture = json_decode(ob_get_clean(), true, 64, JSON_THROW_ON_ERROR);
$tenant = App\Models\Tenant::findOrFail($fixture['tenant_id']);
$area = App\Models\TeamArea::findOrFail($fixture['area_id']);
$area->update(['settings' => [...$area->settings, 'timezone' => 'America/New_York']]);
for ($i = 0; $i < 35; $i++) {
    $sessionId = (string) Illuminate\Support\Str::uuid();
    $day = $i < 30 ? '2026-11-20' : '2026-11-04';
    App\Models\TeamOperationRecord::create([
        'id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
        'source_key' => hash('sha256', 'main-window-'.$i), 'kind' => 'event', 'title' => sprintf('Window event %02d', $i),
        'status' => 'accepted', 'visibility' => 'area', 'starts_on' => '2026-11-12', 'ends_on' => '2026-11-12',
        'data' => ['_teams_enrichment' => ['calendar' => [
            'sessions' => [['id' => $sessionId, 'kind' => 'selling', 'date' => $day]], 'schedule_complete' => false,
            'session_evidence' => [['session_id' => $sessionId, 'status' => 'staff_attested']],
        ]]],
    ]);
}
App\Models\TeamOperationRecord::create([
    'id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
    'source_key' => hash('sha256', 'main-unconfirmed-due'), 'kind' => 'event', 'title' => '11.09.26 - 11.10.26 Fictional Asana due marker',
    'status' => 'accepted', 'visibility' => 'area', 'starts_on' => null, 'ends_on' => null,
    'data' => ['source_schedule' => ['due_on' => '2026-11-09']],
]);
// More than one transport batch inside the upcoming window.
for ($i = 0; $i < 205; $i++) {
    App\Models\TeamOperationRecord::create([
        'id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
        'source_key' => hash('sha256', 'all-events-'.$i), 'kind' => 'event', 'title' => sprintf('Upcoming event %03d', $i),
        'status' => 'accepted', 'visibility' => 'area', 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25',
        'data' => ['sessions' => [['id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'selling', 'date' => '2026-11-25']]],
    ]);
}
foreach ([['Historical archive market', '2022-02-10'], ['Future January market', '2027-01-10'], ['Future December market', '2026-12-10']] as [$title, $day]) {
    App\Models\TeamOperationRecord::create([
        'id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
        'source_key' => hash('sha256', $title), 'kind' => 'event', 'title' => $title,
        'status' => 'accepted', 'visibility' => 'area', 'starts_on' => $day, 'ends_on' => $day,
        'data' => ['sessions' => [['id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'selling', 'date' => $day]]],
    ]);
}
$fixture['upcoming_event_count'] = 239;
$fixture['november_event_count'] = 244;
$fixture['event_count'] = App\Models\TeamOperationRecord::where('area_id', $area->id)->where('kind', 'event')->count();
[$other, $otherOwner] = teamWorkspace();
$other->update(['slug' => 'fictional-no-events']);
$otherOwner->update(['email' => 'no-events@fixture.invalid', 'email_verified_at' => now(), 'password' => Illuminate\Support\Facades\Hash::make('Fictional-local-only-2026!')]);
$fixture['other_tenant'] = $other->slug;
$fixture['other_tenant_id'] = (string) $other->id;
echo json_encode($fixture, JSON_THROW_ON_ERROR);
