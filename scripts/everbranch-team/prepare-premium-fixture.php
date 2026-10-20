<?php

declare(strict_types=1);

// Inherits refusal of existing/non-disposable databases. Every record is fictional.
require __DIR__.'/prepare-standalone-fixture.php';

$run = fn (array $command) => $repo->execute($tenants[0]->id, fn () => $access->context($users['owner'], $tenants[0]), $command + ['idempotency_key' => (string) Illuminate\Support\Str::uuid()]);
foreach (['Autumn collection', 'Forestry Friday', 'Wholesale partnerships'] as $name) {
    $run(['kind' => 'project.create', 'id' => (string) Illuminate\Support\Str::uuid(), 'name' => $name, 'description' => 'Fictional design and acceptance fixture. No real business records.']);
}
$sections = [];
foreach (['Ready to begin', 'In progress', 'In review'] as $name) {
    $id = (string) Illuminate\Support\Str::uuid();
    $project = $repo->state($tenants[0]->id)['projects'][$projectId];
    $run(['kind' => 'project.section', 'id' => $projectId, 'expected_version' => $project['version'], 'section_id' => $id, 'name' => $name]);
    $sections[] = $id;
}
foreach (['Review the autumn production schedule', 'Prepare cedar & amber sample collection', 'Confirm Friday market setup', 'Review wholesale packaging proofs', 'Photograph the new seasonal collection', 'Update the team field guide', 'Plan next week’s harvest', 'Check the curing room inventory', 'Share the November launch brief', 'Review the north field inspection', 'Confirm supplies for the maker workshop', 'Prepare the weekly operations update'] as $index => $title) {
    $run(['kind' => 'task.create', 'id' => (string) Illuminate\Support\Str::uuid(), 'title' => $title, 'description' => "Fictional task for local design verification.\n\nKeep the details, decisions and next steps together. This is synthetic preview content.", 'assignee_id' => (string) $users[$index % 3 === 1 ? 'member' : 'owner']->id, 'priority' => ['normal', 'high', 'normal', 'urgent'][$index % 4], 'start_on' => '2026-10-'.(18 + $index), 'due_on' => '2026-10-'.(19 + $index), 'memberships' => [$projectId => ['section_id' => $sections[$index % 3], 'position' => $index]]]);
}
$projectIds = array_keys($repo->state($tenants[0]->id)['projects']);
App\Models\TeamUserConnection::query()->create(['id' => (string) Illuminate\Support\Str::uuid(), 'tenant_id' => $tenants[0]->id, 'user_id' => $users['owner']->id, 'provider' => 'workspace', 'preferences' => ['favorites' => array_slice($projectIds, 0, 2), 'views' => []]]);
echo "Prepared fictional premium Teams design fixture.\n";
