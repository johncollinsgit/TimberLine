<?php

ob_start();
require __DIR__.'/browser-toolbar-fixture.php';
$fixture = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$cards = [];
foreach (['Fictional early task' => '2026-11-02', 'Fictional later task' => '2026-11-20', 'Fictional undated task' => null] as $title => $due) {
    $id = (string) Illuminate\Support\Str::uuid();
    $repository->execute($tenant->id, $actor, ['kind' => 'task.create', 'id' => $id, 'idempotency_key' => (string) Illuminate\Support\Str::uuid(), 'title' => $title, 'description' => 'Fictional original full task description.', 'memberships' => [$projectId => []], 'assignee_id' => (string) $owner->id, 'due_on' => $due]);
    $cards[$title] = $id;
}
echo json_encode($fixture + ['cards' => $cards], JSON_THROW_ON_ERROR);
