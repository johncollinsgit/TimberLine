<?php

use App\Services\Soccer\SoccerStore;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(Tests\TestCase::class);
it('serializes concurrent family requests against the final public seat on MySQL', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    $tenant = random_int(700000000, 799999999);
    $store = app(SoccerStore::class);
    $teams = config('soccer.teams');
    $teams['green-bears']['public'] = 1;
    $teams['green-bears']['reserved'] = 0;
    $season = $store->insert('seasons', $tenant, ['season_key' => 'spring-2027', 'name' => 'Concurrency fixture', 'starts_on' => '2027-03-15', 'age_as_of' => '2027-03-15', 'registration_open' => true, 'teams' => json_encode($teams)]);
    $dir = sys_get_temp_dir().'/soccer-concurrency-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $requests = [];
    $processes = [];
    try {
        foreach ([1, 2] as $n) {
            $family = $store->insert('families', $tenant, ['email' => 'concurrent'.$n.'@example.com', 'profile' => $store->encrypt(['parentName' => 'Fictional Parent', 'phone' => '+12025550100', 'emergencyContact' => 'Fictional Contact', 'emergencyPhone' => '+12025550101'])]);
            $player = $store->insert('players', $tenant, ['family_id' => $family, 'name' => 'Fictional player', 'birthday' => '2023-01-01', 'profile' => $store->encrypt([])]);
            $store->insert('releases', $tenant, ['family_id' => $family, 'player_id' => $player, 'season_key' => 'spring-2027', 'source_key' => 'fixture-'.$n, 'form_version' => 'test', 'answers' => $store->encrypt([]), 'sha256' => str_repeat('a', 64), 'signed_at' => now()]);
            $requests[] = ['tenant' => $tenant, 'family' => $family, 'input' => ['season_id' => $season, 'volunteer' => false, 'children' => [['player_id' => $player, 'needs_jersey' => false]]], 'barrier' => $dir.'/go'];
        }
        $code = 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $d=json_decode(stream_get_contents(STDIN),true); for($n=0;!file_exists($d["barrier"])&&$n<500;$n++){usleep(10000);} echo json_encode(app(App\\Services\\Soccer\\SoccerRegistrationService::class)->register($d["tenant"],$d["family"],$d["input"]));';
        $cfg = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $cfg['host'], 'DB_PORT' => (string) $cfg['port'], 'DB_DATABASE' => $cfg['database'], 'DB_USERNAME' => $cfg['username'], 'DB_PASSWORD' => $cfg['password'], 'LOG_CHANNEL' => 'null'];
        foreach ($requests as $request) {
            $process = new Process([PHP_BINARY, '-r', $code], base_path(), $environment, json_encode($request), 20);
            $process->start();
            $processes[] = $process;
        }
        touch($dir.'/go');
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        }
        expect($store->query('registrations', $tenant)->where('status', 'offered')->count())->toBe(1)->and($store->query('registrations', $tenant)->where('status', 'waitlisted')->count())->toBe(1)->and($store->query('invoices', $tenant)->count())->toBe(1);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }foreach (['deliveries', 'events', 'invoices', 'registrations', 'releases', 'players', 'families', 'seasons'] as $table) {
            $store->query($table, $tenant)->delete();
        }if (file_exists($dir.'/go')) {
            unlink($dir.'/go');
        }rmdir($dir);
    }
});
