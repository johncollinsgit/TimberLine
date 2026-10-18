<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$script,$tenantId,$userId,$uuid,$barrier,$counter] = $argv;
$mode = $argv[6] ?? 'owner';
if (config('database.default') !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', (string) config('database.connections.mysql.database'))) {
    exit(90);
}
config(['marketing_desk.enabled' => true, 'marketing_desk.tenant_ids' => [(int) $tenantId], 'bud.ai_enabled' => true, 'bud.provider_configured' => true, 'bud.ai_api_key' => 'fictional-test-only', 'marketing_desk.ai_enabled' => true, 'marketing_desk.api_key' => 'fictional-test-only', 'marketing_desk.owner_input_micros_per_million' => 1000000, 'marketing_desk.owner_output_micros_per_million' => 1000000]);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function () use ($counter) {
    file_put_contents($counter, "call\n", FILE_APPEND | LOCK_EX);
    usleep(1500000);

    return Illuminate\Support\Facades\Http::response(['id' => 'fictional-response', 'status' => 'completed', 'usage' => ['input_tokens' => 200, 'output_tokens' => 50], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['answer' => 'A draft', 'handoff' => false, 'sources' => [], 'suggested_caption' => '', 'suggested_recipe' => ''])]]]]]);
});
file_put_contents($barrier.'.ready.'.getmypid(), 'ready');
$deadline = microtime(true) + 15;
while (! is_file($barrier) && microtime(true) < $deadline) {
    usleep(10000);
}
if ($mode === 'voice') {
    Illuminate\Support\Facades\DB::listen(function ($query) use ($uuid, $barrier): void {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'tenant_ai_usage_events') && in_array($uuid, $query->bindings, true)) {
            file_put_contents($barrier.'.voice-read', 'ready');
            usleep(1500000);
        }
    });
}
if ($mode === 'marketing_wait') {
    $deadline = microtime(true) + 10;
    while (! is_file($barrier.'.voice-read') && microtime(true) < $deadline) {
        usleep(10000);
    }
}
try {
    if ($mode === 'voice') {
        $event = app(App\Services\Billing\TenantAiUsageService::class)->reserveVoice(App\Models\Tenant::findOrFail($tenantId), App\Models\User::findOrFail($userId), $uuid, 'job_note', 'voice-test', 90);
        echo $event->status;
        exit(0);
    }
    $turn = app(App\Services\Marketing\Desk\MarketingAiService::class)->generate((int) $tenantId, App\Models\User::findOrFail($userId), $uuid, $uuid, 'owner', ['question' => 'A fictional idea']);
    echo $turn->status;
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
    echo $e->getStatusCode() === 429 ? 'blocked' : 'error:'.$e->getStatusCode();
    exit($e->getStatusCode() === 429 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
