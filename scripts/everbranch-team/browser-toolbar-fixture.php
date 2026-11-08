<?php

// The reused fixture rejects every database except its disposable browser SQLite file.
ob_start();
require __DIR__.'/operations-calendar-browser-fixture.php';
$fixture = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$tenant->update(['name' => 'Fictional Evergreen Makers']);
$member = teamMember($tenant, 'member');
$member->update(['name' => 'Fictional member without area access', 'email' => 'member@fixture.invalid', 'password' => Illuminate\Support\Facades\Hash::make('Fictional-local-only-2026!')]);
$repository = app(App\Services\Team\TeamWorkRepository::class);
$actor = fn () => app(App\Services\Team\TeamWorkAccess::class)->context($owner, $tenant);
$projectId = (string) Illuminate\Support\Str::uuid();
$repository->execute($tenant->id, $actor, ['kind' => 'project.create', 'id' => $projectId, 'idempotency_key' => (string) Illuminate\Support\Str::uuid(), 'name' => 'Fictional seasonal market preparation and retail operations', 'privacy' => 'workspace']);
foreach (['Review organizer setup information', 'Confirm the packing checklist', 'Prepare the seasonal display'] as $title) {
    $repository->execute($tenant->id, $actor, ['kind' => 'task.create', 'id' => (string) Illuminate\Support\Str::uuid(), 'idempotency_key' => (string) Illuminate\Support\Str::uuid(), 'title' => $title, 'description' => 'Fictional browser control fixture.', 'memberships' => [$projectId => []], 'assignee_id' => (string) $owner->id, 'due_on' => '2026-11-06']);
}
App\Models\TeamArea::create(['id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'slug' => 'retail-preparation', 'name' => 'Retail preparation and seasonal planning', 'profile' => 'custom', 'settings' => ['source_authority' => 'teams']]);
echo json_encode($fixture + ['project_id' => $projectId], JSON_THROW_ON_ERROR);
