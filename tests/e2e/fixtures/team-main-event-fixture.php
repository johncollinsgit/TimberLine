<?php

// Reuse the disposable-browser guard and baseline setup.
ob_start();
require __DIR__.'/../../../scripts/everbranch-team/operations-calendar-browser-fixture.php';
$fixture = json_decode(ob_get_clean(), true, 64, JSON_THROW_ON_ERROR);
$tenant = App\Models\Tenant::findOrFail($fixture['tenant_id']);
$area = App\Models\TeamArea::findOrFail($fixture['area_id']);
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
[$other, $otherOwner] = teamWorkspace();
$other->update(['slug' => 'fictional-no-events']);
$otherOwner->update(['email' => 'no-events@fixture.invalid', 'email_verified_at' => now(), 'password' => Illuminate\Support\Facades\Hash::make('Fictional-local-only-2026!')]);
$fixture['other_tenant'] = $other->slug;
$fixture['other_tenant_id'] = (string) $other->id;
echo json_encode($fixture, JSON_THROW_ON_ERROR);
