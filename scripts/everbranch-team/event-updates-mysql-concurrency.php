<?php

// Two-process acceptance against an already migrated, explicitly disposable database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    exit(1);
});

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Operations\EventUpdates;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$db = DB::connection();
if (! app()->environment('testing') || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1' || $db->getDriverName() !== 'mysql' || ! str_starts_with($db->getDatabaseName(), 'codex_teams_') || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Requires an explicitly isolated local codex_teams_ MySQL database.');
}
config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'team_operations.asana_write_enabled' => true, 'cache.default' => 'array']);
Cache::purge('array'); // Each process intentionally has independent cache locks.
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
Http::preventStrayRequests();

function eventRaceWait(string $file): void
{
    $until = microtime(true) + 20;
    while (! is_file($file)) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Timed out waiting for the isolated race gate.');
        }
        usleep(20000);
    }
}
function eventRaceStart(array $context, string $directory): array
{
    $file = tempnam($directory, 'worker-');
    chmod($file, 0600);
    file_put_contents($file, json_encode($context, JSON_THROW_ON_ERROR));
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start isolated race worker.');
    }

    return [$process, $pipes, $file];
}
function eventRaceFinish(array $worker): array
{
    [$process, $pipes, $file] = $worker;
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    unlink($file);
    if ($code !== 0) {
        throw new RuntimeException('Isolated worker failed: '.$error);
    }

    return json_decode($output, true, 32, JSON_THROW_ON_ERROR);
}
if (($argv[1] ?? '') === '--worker') {
    $context = json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
    config(['team_work.tenant_allowlist' => [(string) $context['tenant']]]);
    $task = $context['task'];
    Http::fake(function ($request) use ($context, $task) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($request->method() === 'POST' && $path === '/api/1.0/tasks/400/stories') {
            file_put_contents($context['directory'].'/posts.jsonl', json_encode(['worker' => getmypid(), 'request_hash' => hash('sha256', $request['data']['text'])])."\n", FILE_APPEND | LOCK_EX);
            if ($context['operation'] === 'paused_claim') {
                touch($context['directory'].'/claimed');
                eventRaceWait($context['directory'].'/release');
            }

            return Http::response(['data' => ['gid' => '500', 'text' => $request['data']['text'], 'resource_subtype' => 'comment_added', 'created_at' => now()->toIso8601String(), 'created_by' => ['name' => 'Fictional author']]]);
        }
        if ($request->method() === 'GET' && $path === '/api/1.0/tasks/400') {
            return Http::response(['data' => $task]);
        }
        throw new RuntimeException('Unexpected source call in isolated fake provider.');
    });
    if ($context['operation'] === 'paused_prepare') {
        TeamOperationAttempt::created(function ($attempt) use ($context) {
            if ($attempt->kind === 'event.update' && $attempt->request_id === $context['command']['request_id']) {
                touch($context['directory'].'/prepared');
                eventRaceWait($context['directory'].'/release');
            }
        });
    }
    $tenant = Tenant::findOrFail($context['tenant']);
    $user = User::findOrFail($context['user']);
    $event = TeamOperationRecord::where('tenant_id', $tenant->id)->findOrFail($context['event']);
    try {
        if ($context['operation'] === 'review_unsent') {
            $attempt = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'kind' => 'event.update', 'request_id' => $context['command']['request_id']])->firstOrFail();
            $receipt = app(EventUpdates::class)->reconcile($tenant, $user, $event, $attempt->id, true, 'unsent');
        } else {
            $receipt = app(EventUpdates::class)->post($tenant, $user, $event, $context['command']);
        }
        echo json_encode(['http' => 200, 'id' => $receipt['id'], 'status' => $receipt['status'], 'not_sent' => $receipt['not_sent'] ?? false]);
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
        echo json_encode(['http' => $error->getStatusCode()]);
    }
    exit;
}
require __DIR__.'/../../tests/Support/TeamStudioFixtures.php';
$results = [];
foreach (['review_before_prepare', 'review_after_claim', 'same_parent_after_claim'] as $scenario) {
    [$tenant, $owner] = teamWorkspace();
    $area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Fictional source events', 'slug' => 'events', 'profile' => 'events', 'settings' => ['source_authority' => 'asana']]);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'Fictional source', 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional-not-a-live-token'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200'], 'external_user_id' => '300', 'scopes' => 'tasks:read tasks:write stories:write', 'send_authorized' => true]]);
    $task = ['gid' => '400', 'name' => 'Fictional source event', 'notes' => 'Fictional organizer notes', 'completed' => false, 'due_on' => '2026-11-20', 'modified_at' => '2026-11-01T12:00:00Z', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]];
    $event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'connection_id' => $connection->id, 'kind' => 'event', 'title' => $task['name'], 'source_key' => hash('sha256', Str::uuid()), 'status' => 'source', 'data' => ['source_workspace' => '100', 'source_project' => '200', 'source_id' => '400', 'source_version' => $task['modified_at'], '_generation' => 1]]);
    $directory = sys_get_temp_dir().'/teams-updates-race-'.Str::uuid();
    mkdir($directory, 0700);
    $context = ['tenant' => $tenant->id, 'user' => $owner->id, 'event' => $event->id, 'task' => $task, 'directory' => $directory, 'command' => ['request_id' => (string) Str::uuid(), 'body' => 'Fictional race update', 'file_ids' => []]];
    $first = eventRaceStart($context + ['operation' => $scenario === 'review_before_prepare' ? 'paused_prepare' : 'paused_claim'], $directory);
    eventRaceWait($directory.($scenario === 'review_before_prepare' ? '/prepared' : '/claimed'));
    $second = eventRaceStart($context + ['operation' => $scenario === 'same_parent_after_claim' ? 'post' : 'review_unsent'], $directory);
    try {
        $secondReply = eventRaceFinish($second);
    } finally {
        touch($directory.'/release');
    }
    $firstReply = eventRaceFinish($first);
    // A completed first request must replay without any second provider call.
    $replay = eventRaceFinish(eventRaceStart($context + ['operation' => 'post'], $directory));
    $parent = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'kind' => 'event.update'])->firstOrFail();
    $children = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'kind' => 'asana.update'])->get();
    $postCount = is_file($directory.'/posts.jsonl') ? count(file($directory.'/posts.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    $reviewed = $scenario === 'review_before_prepare';
    if ($firstReply['http'] !== 200 || $secondReply['http'] !== 200 || $replay['http'] !== 200 || $firstReply['id'] !== $secondReply['id'] || $parent->status !== ($reviewed ? 'reviewed' : 'succeeded') || $postCount !== ($reviewed ? 0 : 1) || $replay['not_sent'] !== $reviewed
        || ($reviewed && (! $secondReply['not_sent'] || $secondReply['status'] !== 'reviewed')) || (! $reviewed && ($secondReply['not_sent'] || $secondReply['status'] !== 'executing')) || $children->count() > 1) {
        throw new RuntimeException('Event update race invariant failed: '.$scenario.' '.json_encode(compact('firstReply', 'secondReply', 'replay', 'postCount')));
    }
    $results[$scenario] = ['first' => $firstReply['status'], 'second' => $secondReply['status'], 'final' => $parent->status, 'not_sent' => $replay['not_sent'], 'provider_posts' => $postCount, 'parent_receipts' => TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'kind' => 'event.update'])->count(), 'child_receipts' => $children->count()];
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    } rmdir($directory);
}
echo json_encode(['driver' => 'mysql', 'cache_lock_store' => 'array-process-local', 'provider' => 'strictly-local-http-fake', 'checks' => $results], JSON_PRETTY_PRINT), "\n";
