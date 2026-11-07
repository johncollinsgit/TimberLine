<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

final class PosProcessRace
{
    /** Real independent connections. The first writer pauses after taking its locks, then the contender must wait. */
    public static function run(callable $first, callable $second, callable $pauseOn): array
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1' || ! function_exists('pcntl_fork')
            || ! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
            || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
            throw new \RuntimeException('Requires explicitly authorized local disposable MySQL.');
        }
        $dir = sys_get_temp_dir().'/pos-fence-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $wait = static function ($file): void {
            $until = microtime(true) + 15;
            while (! is_file($file)) {
                if (microtime(true) > $until) {
                    throw new \RuntimeException('Race barrier timeout');
                }
                usleep(2000);
                clearstatcache();
            }
        };
        DB::disconnect();
        $pids = [];
        try {
            foreach ([$first, $second] as $i => $work) {
                $pid = pcntl_fork();
                if ($pid < 0) {
                    throw new \RuntimeException('fork failed');
                }
                if ($pid === 0) {
                    try {
                        if ($i === 0) {
                            $paused = false;
                            DB::listen(function ($query) use ($pauseOn, $dir, $wait, &$paused): void {
                                if (! $paused && DB::transactionLevel() > 0 && $pauseOn($query)) {
                                    $paused = true;
                                    touch($dir.'/held');
                                    $wait($dir.'/release');
                                }
                            });
                        } else {
                            $wait($dir.'/held');
                            touch($dir.'/attempted');
                        }
                        $result = $work();
                        file_put_contents($dir.'/'.$i, json_encode($result));
                        DB::disconnect();
                        exit(0);
                    } catch (\Throwable $e) {
                        file_put_contents($dir.'/'.$i, json_encode(['error' => $e->getMessage()]));
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }
            $until = microtime(true) + 15;
            while (! is_file($dir.'/held')) {
                clearstatcache();
                if (is_file($dir.'/0')) {
                    throw new \RuntimeException('First writer ended before barrier: '.file_get_contents($dir.'/0'));
                } if (microtime(true) > $until) {
                    throw new \RuntimeException('First writer barrier timeout');
                } usleep(2000);
            }
            $wait($dir.'/attempted');
            usleep(200000);
            clearstatcache();
            if (is_file($dir.'/1')) {
                throw new \RuntimeException('Contender bypassed committed writer fence: '.file_get_contents($dir.'/1'));
            }
            touch($dir.'/release');
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                if (pcntl_wexitstatus($status) !== 0) {
                    throw new \RuntimeException('Race worker failed: '.implode(' ', array_map('file_get_contents', glob($dir.'/[01]'))));
                }
            }

            return array_map(fn ($i) => json_decode(file_get_contents($dir.'/'.$i), true), [0, 1]);
        } finally {
            touch($dir.'/release');
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            } rmdir($dir);
        }
    }
}
