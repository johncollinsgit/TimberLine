<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Tests\Support\RentalDisposableMySql::assertSafe(\Illuminate\Support\Facades\DB::connection()->getConfig(),
    config('database.connections.'.\Illuminate\Support\Facades\DB::getDefaultConnection(), []),
    app()->environment('testing'), getenv('TEAM_WORK_DISPOSABLE_MYSQL') === '1');
[$script, $tenantId, $userId, $property, $week, $version, $key, $directory, $index] = $argv;
config(['rentals.enabled' => true, 'rentals.tenant_allowlist' => [(string) $tenantId]]);
$tenant = \App\Models\Tenant::findOrFail($tenantId);
$user = \App\Models\User::findOrFail($userId);
touch($directory.'/ready-'.$index);
$deadline = microtime(true) + 20;
while (! is_file($directory.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Rental race barrier timed out.');
    }
    usleep(10000);
}
try {
    app(\App\Services\Rentals\RentalRepository::class)->execute($tenant, $user, $property, ['action' => 'claimWeek', 'weekId' => $week, 'version' => (int) $version, 'requestKey' => $key]);
    echo json_encode(['status' => 200, 'actor' => (int) $userId]);
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
    if ($error->getStatusCode() !== 409) {
        throw $error;
    }
    echo json_encode(['status' => 409, 'actor' => (int) $userId]);
}
