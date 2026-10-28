<?php

use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Disposable local MySQL required.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName()) || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Refusing birthday recovery outside disposable local MySQL.');
    }
});

test('birthday coverage recovers retained rows before unique DDL and survives code rollback', function () {
    $migration = require database_path('migrations/2026_10_28_210000_create_birthday_cycle_coverage.php');
    $migration->up();
    $id = DB::table('birthday_cycle_coverage')->insertGetId(['tenant_id' => 900001, 'canonical_profile_id' => 900001, 'cycle_year' => 2026, 'legacy_covered' => true]);
    $before = (array) DB::table('birthday_cycle_coverage')->where('id', $id)->first();
    Schema::table('birthday_cycle_coverage', fn ($table) => $table->dropUnique('bcc_customer_year_uq'));
    $migration->up();
    $migration->up();
    $migration->down();
    expect(Schema::hasIndex('birthday_cycle_coverage', 'bcc_customer_year_uq'))->toBeTrue()
        ->and((array) DB::table('birthday_cycle_coverage')->where('id', $id)->first())->toBe($before);
    DB::table('birthday_cycle_coverage')->where('id', $id)->delete();
});

test('two real MySQL issuance processes reserve one annual gift and code', function () {
    $tenant = Tenant::create(['name' => 'Fictional birthday concurrency', 'slug' => 'birthday-concurrent-'.bin2hex(random_bytes(6))]);
    TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'birthday_club_config', 'value' => ['enabled' => true, 'signup_bonus_enabled' => false]]);
    TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'birthday_reward_config', 'value' => ['enabled' => true, 'reward_type' => 'discount_code', 'reward_value' => 10, 'claim_window_days_after' => 30]]);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'email' => 'concurrency@example.test']);
    $birthday = CustomerBirthdayProfile::create(['marketing_profile_id' => $profile->id, 'birth_month' => now()->month, 'birth_day' => now()->day]);
    $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => (string) config('database.connections.mysql.password'), 'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'stderr']);
    $gate = sys_get_temp_dir().'/birthday-club-'.bin2hex(random_bytes(8));
    $workers = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, base_path('tests/Support/birthday-club-mysql-worker.php'), (string) $birthday->id, $gate], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 10;
        while (count(glob($gate.'.*.ready')) < 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Both issuance workers must reach the barrier.');
            }
            usleep(1000);
        }
        touch($gate);
        $ids = [];
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($process), $errors)->toBe(0);
            $ids[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        }
        expect($ids[0])->toBe($ids[1])
            ->and(BirthdayRewardIssuance::where('marketing_profile_id', $profile->id)->count())->toBe(1)
            ->and(DB::table('birthday_cycle_coverage')->where('tenant_id', $tenant->id)->count())->toBe(1);
    } finally {
        @unlink($gate);
        foreach (glob($gate.'.*.ready') as $ready) {
            @unlink($ready);
        }
        DB::table('birthday_cycle_coverage')->where('tenant_id', $tenant->id)->delete();
        $profile->delete();
        $tenant->delete();
    }
});
