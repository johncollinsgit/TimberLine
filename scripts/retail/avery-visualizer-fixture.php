<?php

// Disposable local browser QA only; never a deployment/bootstrap operation.
$root = dirname(__DIR__, 2);
$database = $root.'/output/retail/avery-visualizer-qa/fixture.sqlite';
if (getenv('AVERY_VISUALIZER_LOCAL_QA') !== '1' || getenv('APP_ENV') !== 'staging' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== $database || getenv('TENANCY_LANDLORD_PRIMARY_HOST') !== '127.0.0.1') {
    fwrite(STDERR, "Requires the isolated loopback SQLite visualizer QA environment.\n");
    exit(1);
}
if (! is_dir(dirname($database))) {
    mkdir(dirname($database), 0700, true);
}
if (is_file($database)) {
    fwrite(STDERR, "Fixture already exists; choose a fresh isolated QA checkout or remove only its disposable fixture first.\n");
    exit(1);
}
touch($database);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$owner = \App\Models\User::factory()->create(['email' => 'visualizer-owner@example.invalid', 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
\App\Models\User::factory()->create(['email' => 'avery-demo-visitor@everbranch-demo.invalid', 'role' => 'pouring', 'is_active' => true, 'email_verified_at' => now()]);
app(\App\Services\Retail\RetailShowroomService::class)->seed($owner);
echo "Disposable fictional Avery browser fixture ready.\n";
