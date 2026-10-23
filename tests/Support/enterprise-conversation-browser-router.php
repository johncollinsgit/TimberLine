<?php

// Dedicated loopback test server. Fake provider transport cannot load in the deployed runtime.
$conversationFixtureRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
$db = getenv('ENTERPRISE_TEST_CONVERSATION_DATABASE');
if (PHP_SAPI !== 'cli-server' || getenv('ENTERPRISE_APP_ENV') !== 'testing' || getenv('ENTERPRISE_TEST_CONVERSATION_PROVIDER') !== '1'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || ! is_string($conversationFixtureRoot)
    || ! str_starts_with((string) realpath($conversationFixtureRoot), realpath(sys_get_temp_dir()).'/enterprise-conversation-test-')
    || ! is_string($db) || $db !== $conversationFixtureRoot.'/storage/framework/testing/enterprise-browser-conversation.sqlite' || ! is_file($db)) {
    http_response_code(404);
    exit;
}
$_SERVER['HTTPS'] = 'on';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2).'/apps/enterprise/public/index.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/enterprise-campus/index.php';
$asset = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$asset = is_string($asset) ? preg_replace('#^/enterprise-campus#', '', $asset) : '';
if (preg_match('#^/build/assets/[A-Za-z0-9_-]+\.(js|css)$#D', $asset) && is_file(dirname(__DIR__, 2).'/apps/enterprise/public'.$asset)) {
    header('Content-Type: '.(str_ends_with($asset, '.js') ? 'text/javascript' : 'text/css'));
    readfile(dirname(__DIR__, 2).'/apps/enterprise/public'.$asset);

    return;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
define('LARAVEL_START', microtime(true));
$app = require dirname(__DIR__, 2).'/apps/enterprise/bootstrap/app.php';
$app->booted(static function () use ($app, $conversationFixtureRoot, $db): void {
    $fixture = json_decode(file_get_contents($conversationFixtureRoot.'/conversation-browser.json'), true, 32, JSON_THROW_ON_ERROR);
    if (($fixture['config']['database.connections.enterprise']['database'] ?? null) !== $db) {
        throw new RuntimeException('Fixture database mismatch.');
    }
    $app['config']->set($fixture['config']);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake(['https://api.openai.com/v1/responses' => static function ($request) use ($fixture, $conversationFixtureRoot) {
        file_put_contents($conversationFixtureRoot.'/provider-receipts.jsonl', json_encode(['request_id' => $request->header('X-Client-Request-Id')[0] ?? null])."\n", FILE_APPEND | LOCK_EX);
        if ($fixture['fake_mode'] === 'timeout') {
            throw new RuntimeException('Fictional ambiguous timeout; no API call.');
        }
        $body = $request->data();
        $payload = json_decode($body['input'][0]['content'], true, 32, JSON_THROW_ON_ERROR);
        $value = ['answer' => 'Simulated conversation: inspect the current saved project before acting.', 'refs' => array_slice(array_column($payload['sources'], 'ref'), 0, 1)];

        return \Illuminate\Support\Facades\Http::response(['id' => 'resp_browser_fixture', 'model' => $body['model'], 'service_tier' => 'default', 'status' => 'completed',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'input_tokens_details' => ['cached_tokens' => 20, 'cache_write_tokens' => 10]],
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode($value, JSON_THROW_ON_ERROR)]]]]]);
    }]);
});
$app->handleRequest(\Illuminate\Http\Request::capture());
