<?php

declare(strict_types=1);

// Fictional loopback acceptance harness. Never include from Laravel or deploy as a route.
if (PHP_SAPI !== 'cli-server' || getenv('EVERBRANCH_TEAM_FIXTURE_MODE') !== '1'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
require __DIR__.'/../../app/Services/Team/WorkGraph.php';
use App\Services\Team\WorkGraph;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    readfile(__DIR__.'/fixture-ui.html');
    exit;
}
if ($path !== '/api') {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (isset($_SERVER['HTTP_ORIGIN']) && parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) !== '127.0.0.1') {
    http_response_code(403);
    echo '{"error":"origin_denied"}';
    exit;
}
$actorBase = ['tenant_id' => 'fixture-a', 'user_id' => 'owner', 'active' => true, 'verified' => true, 'entitled' => true,
    'role' => 'owner', 'active_user_ids' => ['owner', 'member', 'guest', 'viewer'],
    'active_roles' => ['owner' => 'owner', 'member' => 'member', 'guest' => 'guest', 'viewer' => 'viewer'], 'accessible_field_job_ids' => ['fixture-job']];
$actors = ['owner' => $actorBase, 'member' => array_replace($actorBase, ['user_id' => 'member', 'role' => 'member']),
    'guest' => array_replace($actorBase, ['user_id' => 'guest', 'role' => 'guest', 'accessible_field_job_ids' => []]),
    'viewer' => array_replace($actorBase, ['user_id' => 'viewer', 'role' => 'viewer']),
    'other' => array_replace($actorBase, ['tenant_id' => 'fixture-b'])];
$graph = new WorkGraph;
$file = sys_get_temp_dir().'/everbranch-team-fictional-'.substr(hash('sha256', __DIR__), 0, 12).'.json';
$handle = fopen($file, 'c+');
flock($handle, LOCK_EX);
try {
    $raw = stream_get_contents($handle);
    $state = $raw === '' ? WorkGraph::empty() : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if ($raw === '') {
        $seed = [
            ['kind' => 'project.create', 'id' => 'fixture-private', 'name' => 'Field operations', 'description' => 'Fictional private project for local acceptance.'],
            ['kind' => 'project.create', 'id' => 'fixture-shared', 'name' => 'Team planning', 'privacy' => 'workspace'],
            ['kind' => 'project.member', 'id' => 'fixture-private', 'expected_version' => 1, 'user_id' => 'member', 'access' => 'editor'],
            ['kind' => 'project.member', 'id' => 'fixture-private', 'expected_version' => 2, 'user_id' => 'guest', 'access' => 'viewer'],
            ['kind' => 'project.member', 'id' => 'fixture-private', 'expected_version' => 3, 'user_id' => 'viewer', 'access' => 'viewer'],
            ['kind' => 'task.create', 'id' => 'fixture-inspection', 'title' => 'Photograph the north field entrance', 'description' => 'Original fictional field notes remain readable. Verify access before sharing.', 'assignee_id' => 'member', 'due_on' => '2026-10-20', 'memberships' => ['fixture-private' => []]],
            ['kind' => 'task.create', 'id' => 'fixture-planning', 'title' => 'Review next week’s team priorities', 'assignee_id' => 'owner', 'memberships' => ['fixture-shared' => []]],
        ];
        foreach ($seed as $index => $command) {
            $command['idempotency_key'] = 'seed-'.$index;
            $state = $graph->execute($state, $actorBase, $command, '2026-10-16T12:00:00Z')['state'];
        }
    }
    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 200000) {
            throw new DomainException('invalid_request');
        }
        $input = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    }
    $actorName = $input['actor'] ?? $_GET['actor'] ?? 'owner';
    if (! isset($actors[$actorName])) {
        throw new DomainException('access_denied');
    }
    $actor = $actors[$actorName];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $reply = $graph->execute($state, $actor, $input['command'] ?? [], gmdate('Y-m-d\TH:i:s\Z'));
        $state = $reply['state'];
        $output = ['result' => $reply['result'], 'replayed' => $reply['replayed']];
    } elseif (isset($_GET['task'])) {
        $output = $graph->read($state, $actor, 'task', $_GET['task']);
    } else {
        $projects = [];
        foreach ($state['projects'] as $project) {
            try {
                $projects[] = $graph->read($state, $actor, 'project', $project['id']);
            } catch (DomainException) { /* Filter inaccessible objects. */
            }
        }
        $output = ['tasks' => $graph->search($state, $actor, $_GET['q'] ?? '', ($_GET['mine'] ?? '') === '1', true), 'projects' => $projects, 'scope' => $actor['tenant_id']];
    }
    // Fictional disposable state only. This is not the production persistence contract.
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
    fflush($handle);
    echo json_encode($output, JSON_THROW_ON_ERROR);
} catch (DomainException $e) {
    http_response_code($e->getMessage() === 'conflict' ? 409 : 403);
    echo json_encode(['error' => $e->getMessage(), 'saved' => false]);
} catch (Throwable) {
    http_response_code(422);
    echo '{"error":"invalid_request","saved":false}';
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}
