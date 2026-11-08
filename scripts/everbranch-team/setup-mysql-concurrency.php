<?php

// Opt-in two-process acceptance on an already migrated disposable local database.
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
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Operations\TeamsSetup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$db = DB::connection();
if (! app()->environment('testing') || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1' || $db->getDriverName() !== 'mysql' || ! str_starts_with($db->getDatabaseName(), 'codex_teams_') || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Requires an explicitly isolated local codex_teams_ MySQL database.');
}
config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
Http::preventStrayRequests();
if (($argv[1] ?? '') === '--worker') {
    $context = json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
    config(['team_work.tenant_allowlist' => [(string) $context['tenant']]]);
    try {
        $method = $context['operation'];
        $result = app(TeamsSetup::class)->$method(Tenant::findOrFail($context['tenant']), User::findOrFail($context['user']), $context['command']);
        echo json_encode(['status' => 200, 'area' => $result['area']['id'] ?? null, 'version' => $result['version']]);
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
        echo json_encode(['status' => $error->getStatusCode()]);
    }
    exit;
}
require __DIR__.'/../../tests/Support/TeamStudioFixtures.php';
$results = [];
foreach (['same_finish', 'competing_finish', 'competing_draft'] as $scenario) {
    [$tenant, $owner] = teamWorkspace();
    $service = app(TeamsSetup::class);
    $values = ['home_base' => ['address' => '1 Fictional Studio Road', 'timezone' => 'America/New_York'], 'area' => ['name' => 'Fictional native events', 'profile' => 'events'], 'member_ids' => [], 'event_defaults' => ['teardown_minutes' => 60, 'arrival_buffer_minutes' => 0], 'cost_policy' => ['template' => 'blank', 'currency' => 'USD', 'effective_on' => '2026-11-08', 'mileage_rate_minor' => 67], 'connection' => 'later'];
    $finishing = $scenario !== 'competing_draft';
    $competing = $scenario !== 'same_finish';
    if ($finishing) {
        $service->saveDraft($tenant, $owner, ['request_id' => (string) Str::uuid(), 'expected_version' => 0, 'step' => 3, 'values' => $values]);
        $command = ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'reviewed' => true];
    } else {
        $command = ['request_id' => (string) Str::uuid(), 'expected_version' => 0, 'step' => 0, 'values' => $values];
    }
    $workers = [];
    foreach ([0, 1] as $i) {
        $file = tempnam(sys_get_temp_dir(), 'teams-setup-race-');
        chmod($file, 0600);
        file_put_contents($file, json_encode(['tenant' => $tenant->id, 'user' => $owner->id, 'operation' => $finishing ? 'finish' : 'saveDraft', 'command' => array_replace($command, $competing && $i === 1 ? ['request_id' => (string) Str::uuid()] : [])], JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $workers[] = [$process, $pipes, $file];
    }
    $outcomes = [];
    foreach ($workers as [$process, $pipes, $file]) {
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
    $areas = TeamArea::where('tenant_id', $tenant->id)->count();
    $policies = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'kind' => 'cost_policy'])->count();
    $receipts = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'kind' => 'teams.setup'])->count();
    $state = $service->status($tenant, $owner);
    if ($statuses !== ($competing ? [200, 409] : [200, 200]) || $areas !== ($finishing ? 1 : 0) || $policies !== ($finishing ? 1 : 0) || $receipts !== ($finishing ? 1 : 0) || $state['version'] !== ($finishing ? 2 : 1) || (! $competing && $outcomes[0]['area'] !== $outcomes[1]['area'])) {
        throw new RuntimeException('Setup race invariant failed: '.$scenario);
    }
    $results[$scenario] = ['statuses' => $statuses, 'areas_created' => $areas, 'cost_policy_drafts_created' => $policies, 'completion_receipts' => $receipts, 'version' => $state['version']];
}
echo json_encode(['driver' => 'mysql', 'processes_per_race' => 2, 'checks' => $results], JSON_PRETTY_PRINT), "\n";
