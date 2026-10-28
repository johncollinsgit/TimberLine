<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', Illuminate\Support\Facades\DB::connection()->getDatabaseName())) {
    throw new RuntimeException('Disposable test database required.');
}
touch($argv[2].'.'.getmypid().'.ready');
$deadline = microtime(true) + 10;
while (! is_file($argv[2])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker barrier timeout.');
    }
    usleep(1000);
}
$birthday = App\Models\CustomerBirthdayProfile::findOrFail((int) $argv[1]);
$result = app(App\Services\Marketing\BirthdayRewardEngineService::class)->issueAnnualReward($birthday, ['send_email' => false]);
echo json_encode(['id' => $result['issuance']->id, 'code' => $result['issuance']->reward_code], JSON_THROW_ON_ERROR);
