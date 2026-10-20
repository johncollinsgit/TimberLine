<?php

// CLI loopback acceptance server only. This file is outside public/ and never registered by the app.
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($root.'/public'.$path) && str_starts_with(realpath($root.'/public'.$path), realpath($root.'/public').DIRECTORY_SEPARATOR)) {
    return false;
}
$database = (string) getenv('DB_DATABASE');
$allowed = str_replace('\\', '/', $root.'/storage/framework/testing/');
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || ! str_starts_with(str_replace('\\', '/', $database), $allowed) || ! is_file($database.'.fixture.json')) {
    http_response_code(403);
    exit('Disposable loopback fixture required.');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->booted(function (): void {
    config(['team_operations.fixture' => true]);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $project = ['gid' => '91001', 'name' => 'Fictional Asana · Seasonal launch', 'notes' => 'Fictional source project for local integration acceptance. No real Asana data.', 'workspace' => ['gid' => '91000'], 'archived' => false, 'permalink_url' => 'https://app.asana.com/0/91001/list'];
        $task = ['gid' => '92001', 'name' => 'Review the fictional launch checklist', 'notes' => "Original fictional Asana notes.\n\nThis content is a read-only test projection, not an imported Teams task.", 'workspace' => ['gid' => '91000'], 'memberships' => [['project' => ['gid' => '91001'], 'section' => ['gid' => '93001', 'name' => 'In progress']]], 'assignee' => ['gid' => '94001', 'name' => 'Fictional Asana owner'], 'completed' => false, 'due_on' => '2026-10-23', 'start_on' => '2026-10-20', 'custom_fields' => [['name' => 'Channel', 'display_value' => 'Wholesale']], 'num_subtasks' => 1, 'permalink_url' => 'https://app.asana.com/0/91001/92001'];
        $data = match ($path) {
            '/api/1.0/workspaces' => [['gid' => '91000', 'name' => 'Fictional Asana workspace']],
            '/api/1.0/workspaces/91000/projects' => [$project],
            '/api/1.0/projects/91001' => $project,
            '/api/1.0/projects/91001/tasks' => [$task, array_replace($task, ['gid' => '92002', 'name' => 'Confirm fictional event materials', 'due_on' => null, 'start_on' => null, 'custom_fields' => [], 'num_subtasks' => 0, 'assignee' => null])],
            '/api/1.0/projects/91001/sections' => [['gid' => '93001', 'name' => 'In progress']],
            '/api/1.0/tasks/92001', '/api/1.0/tasks/92002' => $task,
            '/api/1.0/tasks/92001/subtasks' => [['gid' => '92003', 'name' => 'Review fictional packaging', 'completed' => false]],
            '/api/1.0/tasks/92003' => array_replace($task, ['gid' => '92003', 'name' => 'Review fictional packaging', 'memberships' => [], 'parent' => ['gid' => '92001'], 'num_subtasks' => 0]),
            '/api/1.0/tasks/92001/stories', '/api/1.0/tasks/92003/stories' => [['gid' => '95001', 'text' => 'Fictional source conversation, visible read only.', 'created_at' => '2026-10-19T12:00:00Z', 'created_by' => ['name' => 'Fictional Asana owner'], 'resource_subtype' => 'comment_added']],
            '/api/1.0/attachments', '/api/1.0/tasks/92003/subtasks' => [],
            default => null,
        };

        return $data !== null ? \Illuminate\Support\Facades\Http::response(['data' => $data]) : \Illuminate\Support\Facades\Http::response(['errors' => [['message' => 'No fictional response defined.']]], 404);
    });
});
$app->handleRequest(\Illuminate\Http\Request::capture());
