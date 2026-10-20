<?php

declare(strict_types=1);

// Real simultaneous processes, but ONLY against a marked disposable local SQLite fixture.
$root = dirname(__DIR__, 2);
$database = str_replace('\\', '/', (string) getenv('DB_DATABASE'));
$allowed = str_replace('\\', '/', $root.'/storage/framework/testing/');
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || ! str_starts_with($database, $allowed) || str_contains(substr($database, strlen($allowed)), '/')
    || ! preg_match('/^team-fixture-[a-f0-9-]+\.sqlite$/D', basename($database)) || ! is_file($database.'.fixture.json')) {
    throw new RuntimeException('Requires an existing marked synthetic fixture in this checkout.');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || str_replace('\\', '/', config('database.connections.sqlite.database')) !== $database) {
    throw new RuntimeException('Fixture binding mismatch.');
}
$fixture = json_decode(file_get_contents($database.'.fixture.json'), true, 512, JSON_THROW_ON_ERROR);
if (str_replace('\\', '/', $fixture['database']) !== $database) {
    throw new RuntimeException('Fixture marker mismatch.');
}
config(['team_work.enabled' => true, 'team_work.tenant_allowlist' => array_map(fn ($tenant) => (string) $tenant['id'], $fixture['tenants'])]);
Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 10000');
$tenant = App\Models\Tenant::query()->findOrFail($fixture['tenants'][0]['id']);
$user = App\Models\User::query()->where('email', 'owner@fictional.invalid')->firstOrFail();
$repository = app(App\Services\Team\TeamWorkRepository::class);
$actor = fn () => app(App\Services\Team\TeamWorkAccess::class)->context($user, $tenant);
Illuminate\Support\Facades\Date::setTestNow('2026-01-31T14:00:00Z');
if (($argv[1] ?? '') === 'worker') {
    if (! Illuminate\Support\Str::isUuid($argv[3] ?? '') || ! in_array($argv[4] ?? '', ['0', '1'], true)) {
        throw new RuntimeException('Invalid synthetic barrier arguments.');
    }
    $command = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $barrier = $database.'.barrier-'.$argv[3];
    file_put_contents($barrier.'.ready-'.$argv[4], 'ready');
    $deadline = microtime(true) + 10;
    while (! is_file($barrier) && microtime(true) < $deadline) {
        usleep(10000);
        clearstatcache(true, $barrier);
    }
    if (! is_file($barrier)) {
        throw new RuntimeException('Synthetic barrier timed out.');
    }
    try {
        $result = $repository->execute($tenant->id, $actor, $command);
        echo json_encode(['status' => $result['replayed'] ? 'replayed' : 'accepted']), "\n";
    } catch (DomainException $e) {
        echo json_encode(['status' => $e->getMessage()]), "\n";
    }
    exit;
}
$assert = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$checks = [];
foreach ((($argv[1] ?? '') === 'milestone-c' ? ['recurrence_different_keys', 'template_same_key'] : ['different_keys', 'same_key']) as $scenario) {
    $id = (string) Illuminate\Support\Str::uuid();
    $repository->execute($tenant->id, $actor, ['kind' => 'task.create', 'id' => $id, 'title' => 'Synthetic race '.$scenario, 'idempotency_key' => (string) Illuminate\Support\Str::uuid()]);
    $command = ['kind' => 'task.update', 'id' => $id, 'expected_version' => 1, 'changes' => ['description' => 'Retained winner'], 'idempotency_key' => (string) Illuminate\Support\Str::uuid()];
    if ($scenario === 'recurrence_different_keys') {
        $repository->execute($tenant->id, $actor, ['kind' => 'task.update', 'id' => $id, 'expected_version' => 1, 'changes' => ['due_on' => '2026-01-31'], 'idempotency_key' => (string) Illuminate\Support\Str::uuid()]);
        $repository->execute($tenant->id, $actor, ['kind' => 'task.recurrence', 'id' => $id, 'expected_version' => 2, 'rule' => ['frequency' => 'monthly', 'interval' => 1, 'timezone' => 'America/New_York', 'overlap' => 'reject'], 'acknowledge_sharing' => true, 'idempotency_key' => (string) Illuminate\Support\Str::uuid()]);
        $command = ['kind' => 'task.complete', 'id' => $id, 'expected_version' => 3, 'idempotency_key' => (string) Illuminate\Support\Str::uuid()];
    } elseif ($scenario === 'template_same_key') {
        $state = $repository->state($tenant->id);
        $sourceProject = $fixture['projectId'];
        $template = (string) Illuminate\Support\Str::uuid();
        $repository->execute($tenant->id, $actor, ['kind' => 'project.template', 'id' => $sourceProject, 'expected_version' => $state['projects'][$sourceProject]['version'], 'template_id' => $template, 'template_type' => 'project', 'name' => 'Synthetic race blueprint', 'idempotency_key' => (string) Illuminate\Support\Str::uuid()]);
        $state = $repository->state($tenant->id);
        $id = (string) Illuminate\Support\Str::uuid();
        $command = ['kind' => 'project.from_template', 'id' => $id, 'name' => 'Synthetic race copy', 'project_id' => $sourceProject, 'project_version' => $state['projects'][$sourceProject]['version'], 'template_id' => $template, 'template_version' => 1, 'acknowledge_sharing' => true, 'idempotency_key' => (string) Illuminate\Support\Str::uuid()];
    }
    $round = (string) Illuminate\Support\Str::uuid();
    $processes = [];
    for ($index = 0; $index < 2; $index++) {
        $input = $command;
        if ($index === 1 && in_array($scenario, ['different_keys', 'recurrence_different_keys'], true)) {
            if ($scenario === 'different_keys') {
                $input['changes']['description'] = 'Other retained winner';
            }
            $input['idempotency_key'] = (string) Illuminate\Support\Str::uuid();
        }
        $process = new Symfony\Component\Process\Process([PHP_BINARY, __FILE__, 'worker', json_encode($input, JSON_THROW_ON_ERROR), $round, (string) $index], $root);
        $process->setTimeout(25);
        $process->start();
        $processes[] = $process;
    }
    $barrier = $database.'.barrier-'.$round;
    $deadline = microtime(true) + 15;
    do {
        clearstatcache();
        $ready = is_file($barrier.'.ready-0') && is_file($barrier.'.ready-1');
        if (! $ready) {
            usleep(10000);
        }
    } while (! $ready && microtime(true) < $deadline);
    $assert($ready, 'Both isolated workers must reach the barrier.');
    file_put_contents($barrier, 'go');
    $statuses = [];
    foreach ($processes as $process) {
        $process->wait();
        $assert($process->isSuccessful(), 'Worker failed: '.$process->getErrorOutput());
        $statuses[] = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR)['status'];
    }
    sort($statuses);
    $assert($statuses === (in_array($scenario, ['same_key', 'template_same_key'], true) ? ['accepted', 'replayed'] : ['accepted', 'conflict']), 'Unexpected race result.');
    $state = $repository->state($tenant->id);
    $type = $scenario === 'template_same_key' ? 'projects' : 'tasks';
    $version = $scenario === 'recurrence_different_keys' ? 4 : ($scenario === 'template_same_key' ? 1 : 2);
    $events = $scenario === 'recurrence_different_keys' ? 4 : 2;
    $assert($state[$type][$id]['version'] === $version, 'Race must advance version once.');
    $assert(count(array_filter($state['events'], fn ($event) => $event['entity_id'] === $id)) === $events, 'No duplicate mutation events.');
    $assert(count(array_filter($state['receipts'], fn ($receipt) => $receipt['entity_id'] === $id)) === $events, 'No duplicate mutation receipts.');
    if ($scenario === 'recurrence_different_keys') {
        $successor = $state['tasks'][$id]['recurrence_successor_id'];
        $assert($state['tasks'][$successor]['due_on'] === '2026-02-28', 'Month anchor must survive concurrent completion.');
        $assert(count(array_filter($state['tasks'], fn ($task) => ($task['recurrence_series_id'] ?? null) === $id)) === 2, 'Exactly original plus one successor.');
    }
    if ($scenario === 'template_same_key') {
        $assert(count(array_filter($state['tasks'], fn ($task) => isset($task['memberships'][$id]))) === 1, 'Template root copied exactly once.');
    }
    $checks[] = ['scenario' => $scenario, 'statuses' => $statuses, 'version' => $version, 'events' => $events, 'receipts' => $events];
}
echo json_encode(['database' => 'marked_disposable_sqlite', 'parallel_processes_per_race' => 2, 'scenarios' => $checks, 'mysql_certified' => false, 'controlled_clock' => now()->toIso8601String()], JSON_PRETTY_PRINT), "\n";
