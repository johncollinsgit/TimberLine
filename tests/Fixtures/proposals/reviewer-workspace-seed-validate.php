<?php

// Pure local validation/projector probe. No Kernel, ENV, database, real grant or provider service is booted.
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = new Illuminate\Foundation\Application($root);
$app->instance('config', new Illuminate\Config\Repository([
    'service_proposals' => ['reviewer_workspace' => ['financial_enabled' => true, 'writes_enabled' => true]],
]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, 'en');
$app->instance('validator', new Illuminate\Validation\Factory($translator, $app));
$diagnostics = [];
set_error_handler(function ($severity, $message, $file, $line) use (&$diagnostics) {
    if ($severity === E_DEPRECATED) {
        $diagnostics[] = ['type' => 'php_deprecation', 'file' => basename($file), 'line' => $line, 'message' => $message];

        return true; // Retained explicitly in the result, never silently discarded.
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    $bytes = stream_get_contents(STDIN, 300000);
    $graph = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($graph)) {
        throw new RuntimeException('Graph object required.');
    }
    $schema = new App\Proposals\ReviewerWorkspaceSchema;
    $graph = $schema->graph($graph, true);
    $access = new App\Proposals\ReviewerWorkspaceAccess(new App\Proposals\Access, new App\Proposals\CampusReviewManifest, $schema);
    $projector = new App\Proposals\ReviewerWorkspaceProjector($access, new App\Proposals\ReviewerAiSnapshot);
    $areas = array_column($graph['areas'], 'id');
    $scope = ['policy' => ['max_role' => 'editor', 'area_ids' => $areas, 'allow_financial' => true], 'capability_hash' => str_repeat('a', 64)];
    $snapshots = [];
    foreach ([...array_map(fn ($id) => [$id], $areas), $areas] as $selected) {
        $selection = ['persona' => 'demo_manager', 'area_ids' => $selected, 'person_id' => null];
        $visible = $projector->graph($scope, $selection, $graph);
        $snapshot = $projector->snapshot($scope, $selection, $graph, 1);
        $snapshots[] = ['area_ids' => $selected, 'projects' => count($visible['projects']), 'tasks' => count($visible['tasks']),
            'tickets' => count($visible['tickets']), 'sources' => count($snapshot['sources']), 'bytes' => strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'coverage' => $snapshot['coverage']];
    }
    echo json_encode(['valid' => true, 'graph_sha256' => App\Proposals\ReviewerWorkspaceSchema::digest($graph),
        'diagnostics' => $diagnostics, 'counts' => array_map('count', array_filter($graph, 'is_array')), 'projector_scopes' => $snapshots,
        'authority' => 'pure fictional policy fixture only; no native account/grant/DB/provider'], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['valid' => false, 'error' => 'seed_validation_refused'], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
