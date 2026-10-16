<?php

$root = dirname(__DIR__, 2);
$asset = realpath($root.'/public'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($asset && is_file($asset) && str_starts_with($asset, $root.'/public/') && ! str_ends_with($asset, '.php')) {
    return false;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
if (! app()->environment('testing') || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || ! preg_match('/(^|_)(ci|test|testing)(_|$)/', Illuminate\Support\Facades\DB::connection()->getDatabaseName())) {
    throw new RuntimeException('Local disposable testing preview only.');
}
$fixture = json_decode(file_get_contents(getenv('AI_TEST_FIXTURE_FILE')), true);
$ids = array_column($fixture, 'tenant');
config()->set(['ai_branch.enabled' => true, 'ai_branch.tenant_ids' => $ids, 'ai_branch.workspace_context_enabled' => true, 'ai_branch.workspace_context_tenant_ids' => $ids, 'ai_branch.api_key' => 'fake-local-only', 'ai_branch.model' => 'fake-local', 'ai_branch.input_micros_per_million' => 1000000, 'ai_branch.output_micros_per_million' => 4000000, 'ai_branch.actions_enabled' => false]);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function ($request) {
    if ($request->url() !== 'https://api.openai.com/v1/responses') {
        throw new RuntimeException('Unexpected preview provider request.');
    }
    file_put_contents(getenv('AI_TEST_DISPATCH_FILE'), "fake-dispatch\n", FILE_APPEND | LOCK_EX);
    $context = json_decode($request['input'][0]['content'], true)['untrusted_workspace_context'];
    $text = $context['workspace']['name']." attention briefing\n\n";
    foreach ($context['attention'] as $candidate) {
        $text .= $candidate['title'].' ['.$candidate['ref']."] — review this item with your team.\n";
    }
    $text .= "\nThese are sampled permitted sources. Check the coverage below; no action has been taken.";

    return Illuminate\Support\Facades\Http::response(['id' => 'fictional_response', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 60]]);
});
$response = $kernel->handle($request = Illuminate\Http\Request::capture());
$response->headers->set('X-Everbranch-AI-Preview', 'fictional-fake-provider');
$response->send();
$kernel->terminate($request, $response);
