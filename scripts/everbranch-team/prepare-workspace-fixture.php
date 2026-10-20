<?php

declare(strict_types=1);

// The existing preparer enforces a NEW, checkout-bound, disposable SQLite file.
require __DIR__.'/prepare-laravel-fixture.php';
$tenants[1]->users()->attach($users['owner']->id, ['role' => 'member', 'membership_active' => true]);
$secondProject = (string) Illuminate\Support\Str::uuid();
$secondTask = (string) Illuminate\Support\Str::uuid();
foreach ([
    ['kind' => 'project.create', 'id' => $secondProject, 'name' => 'Fictional west operations'],
    ['kind' => 'task.create', 'id' => $secondTask, 'title' => 'Inspect the fictional west gate', 'description' => 'West original notes remain in this workspace.', 'assignee_id' => (string) $users['owner']->id, 'memberships' => [$secondProject => []]],
] as $command) {
    $repo->execute($tenants[1]->id, fn () => $access->context($users['owner'], $tenants[1]), $command + ['idempotency_key' => (string) Illuminate\Support\Str::uuid()]);
}
$marker = json_decode(file_get_contents($database.'.fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$marker['purpose'] = 'workspace-switch-acceptance';
$marker['secondProjectId'] = $secondProject;
$marker['secondTaskId'] = $secondTask;
file_put_contents($database.'.fixture.json', json_encode($marker, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Prepared two-workspace synthetic acceptance.\n";
