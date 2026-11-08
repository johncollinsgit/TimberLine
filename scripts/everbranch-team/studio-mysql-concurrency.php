<?php

// Opt-in two-process acceptance; never creates, resets or drops a database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    exit(1);
});
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Studio\TeamsStudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$db = DB::connection();
if (! app()->environment('testing') || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1' || $db->getDriverName() !== 'mysql' || ! str_starts_with($db->getDatabaseName(), 'codex_teams_') || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Requires an explicitly isolated local codex_teams_ MySQL database.');
}
config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
if (($argv[1] ?? '') === '--worker') {
    $context = json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
    config(['team_work.tenant_allowlist' => [(string) $context['tenant']]]);
    try {
        $method = ($context['operation'] ?? 'createEvent') === 'reviseBudget' ? 'reviseBudget' : 'createEvent';
        $result = app(TeamsStudio::class)->$method(Tenant::findOrFail($context['tenant']), User::findOrFail($context['user']), TeamArea::findOrFail($context['area']), $context['command']);
        echo json_encode(['status' => 200, 'event' => $result['event_id'], 'budget' => $result['budget_id']]);
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
        echo json_encode(['status' => $error->getStatusCode()]);
    }
    exit;
}
require __DIR__.'/../../tests/Support/TeamStudioFixtures.php';
[$tenant, $owner] = teamWorkspace();
$area = studioArea($tenant);
$studio = app(TeamsStudio::class);
$policy = $studio->saveDefinition($tenant, $owner, $area, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'kind' => 'cost_policy', 'publish' => true, 'definition' => studioPolicy()]);
$results = [];
foreach (['same_command', 'competing_commands', 'same_budget_revision', 'competing_budget_revisions'] as $scenario) {
    $revising = str_contains($scenario, 'budget');
    $competing = str_starts_with($scenario, 'competing');
    $beforeEvents = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event'])->count();
    $revision = $revising ? ['event_id' => $event->fresh()->id, 'event_version' => $event->fresh()->version, 'budget_id' => $event->fresh()->data['_teams_enrichment']['budget_id'], 'reason' => 'Reviewed explicit revision for concurrent budget acceptance.'] : null;
    $draft = $studio->saveDraft($tenant, $owner, $area, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'title' => 'Fictional concurrency event', 'step' => 4, 'policy_id' => $policy['id'], 'logistics' => ['starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'timezone' => 'America/New_York'], 'budget_inputs' => studioInputs(), 'revision' => $revision]);
    $command = ['request_id' => (string) Str::uuid(), 'draft_id' => $draft['id'], 'expected_version' => 1, 'accept_review_items' => true];
    if ($revising) {
        $command += ['event_id' => $revision['event_id'], 'expected_event_version' => $revision['event_version'], 'expected_budget_id' => $revision['budget_id'], 'reason' => $revision['reason']];
    }
    $processes = [];
    foreach ([0, 1] as $i) {
        $file = tempnam(sys_get_temp_dir(), 'teams-race-');
        file_put_contents($file, json_encode(['tenant' => $tenant->id, 'user' => $owner->id, 'area' => $area->id, 'operation' => $revising ? 'reviseBudget' : 'createEvent', 'command' => array_replace($command, $competing && $i === 1 ? ['request_id' => (string) Str::uuid()] : [])], JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $processes[] = [$process, $pipes, $file];
    }
    $outcomes = [];
    foreach ($processes as [$process, $pipes, $file]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        unlink($file);
        if ($exit !== 0) {
            throw new RuntimeException('Worker failed: '.$errors);
        }
        $outcomes[] = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
    }
    $statuses = array_column($outcomes, 'status');
    sort($statuses);
    $resource = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => $revising ? 'event_budget' : 'event', 'source_key' => hash('sha256', ($revising ? 'budget:' : 'wizard:').$draft['id'])])->get();
    $afterEvents = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event'])->count();
    $expected = $competing ? [200, 409] : [200, 200];
    $key = $revising ? 'budget' : 'event';
    if ($statuses !== $expected || $resource->count() !== 1 || $afterEvents - $beforeEvents !== ($revising ? 0 : 1) || (! $competing && $outcomes[0][$key] !== $outcomes[1][$key])) {
        throw new RuntimeException('Race invariant failed: '.$scenario);
    }
    $results[$scenario] = ['statuses' => $statuses, 'events_created' => $afterEvents - $beforeEvents, 'revisions_created' => $revising ? $resource->count() : null];
    if (! $revising) {
        $event = $resource->first();
    }
}
echo json_encode(['driver' => 'mysql', 'processes_per_race' => 2, 'checks' => $results], JSON_PRETTY_PRINT), "\n";
