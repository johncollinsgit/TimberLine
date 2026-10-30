<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config()->set('campus_facilities.storage_path', $argv[1]);
$input = json_decode(base64_decode($argv[2], true), true, 32, JSON_THROW_ON_ERROR);
for ($attempt = 0; $attempt < 500 && ! is_file($argv[1].'/go'); $attempt++) {
    usleep(10000);
}
try {
    $ticket = app(App\Services\CampusFacilities\DemoStore::class)->access(fn (&$state) => app(App\Services\CampusFacilities\Tickets::class)->save($state, $input));
    echo json_encode(['status' => 200, 'revision' => $ticket['revision']]);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
    echo json_encode(['status' => $error->getStatusCode()]);
}
