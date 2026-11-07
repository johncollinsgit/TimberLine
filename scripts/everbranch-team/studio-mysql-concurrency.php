<?php

// Opt-in two-process acceptance; never creates, resets or drops a database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
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
        $result = app(TeamsStudio::class)->createEvent(Tenant::findOrFail($context['tenant']), User::findOrFail($context['user']), TeamArea::findOrFail($context['area']), $context['command']);
        echo json_encode(['status' => 200, 'event' => $result['event_id']]);
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
foreach (['same_command', 'competing_commands'] as $scenario) {
    $draft = $studio->saveDraft($tenant, $owner, $area, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'title' => 'Fictional concurrency event', 'step' => 4, 'policy_id' => $policy['id'], 'logistics' => ['starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'timezone' => 'America/New_York'], 'budget_inputs' => studioInputs()]);
    $command = ['request_id' => (string) Str::uuid(), 'draft_id' => $draft['id'], 'expected_version' => 1, 'accept_review_items' => true];
    $processes = [];
    foreach ([0, 1] as $i) {
        $file = tempnam(sys_get_temp_dir(), 'teams-race-');
        file_put_contents($file, json_encode(['tenant' => $tenant->id, 'user' => $owner->id, 'area' => $area->id, 'command' => array_replace($command, $scenario === 'competing_commands' && $i === 1 ? ['request_id' => (string) Str::uuid()] : [])], JSON_THROW_ON_ERROR));
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
    $eventCount = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'source_key' => hash('sha256', 'wizard:'.$draft['id'])])->count();
    $expected = $scenario === 'same_command' ? [200, 200] : [200, 409];
    if ($statuses !== $expected || $eventCount !== 1 || ($scenario === 'same_command' && $outcomes[0]['event'] !== $outcomes[1]['event'])) {
        throw new RuntimeException('Race invariant failed: '.$scenario);
    }
    $results[$scenario] = ['statuses' => $statuses, 'events_created' => $eventCount];
}
echo json_encode(['driver' => 'mysql', 'processes_per_race' => 2, 'checks' => $results], JSON_PRETTY_PRINT), "\n";
