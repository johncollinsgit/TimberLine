<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantBudSetting;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(Tests\TestCase::class);

it('serializes shared AI budget reservations and identical requests in real MySQL', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())) {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    foreach (['different', 'same', 'mixed'] as $scenario) {
        $tenant = Tenant::create(['name' => 'Fictional concurrency', 'slug' => 'md-test-'.Str::lower((string) Str::ulid())]);
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $actor->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => true]);
        TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'growth', 'operating_mode' => 'direct', 'metadata' => ['marketing_desk' => ['ai_budget' => ['cap_cents' => 4, 'approved_by' => $actor->id, 'approved_at' => now()->toIso8601String()]]]]);
        TenantBudSetting::create(['tenant_id' => $tenant->id, 'status' => 'approved', 'ai_status' => 'approved', 'ai_monthly_budget_cents' => 4]);
        foreach (['messaging', 'ai'] as $module) {
            TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => $module, 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included_in_plan', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
        }
        $barrier = sys_get_temp_dir().'/md-concurrency-'.Str::uuid();
        $counter = $barrier.'.calls';
        $uuid = (string) Str::uuid();
        $processes = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/MarketingDeskAiConcurrencyWorker.php'), (string) $tenant->id, (string) $actor->id, $scenario === 'same' ? $uuid : (string) Str::uuid(), $barrier, $counter, $scenario === 'mixed' ? ($i === 0 ? 'marketing_wait' : 'voice') : 'owner'], base_path(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_HOST' => config('database.connections.mysql.host'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password')]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($barrier.'.ready.*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            file_put_contents($barrier, 'go');
            $states = [];
            foreach ($processes as $process) {
                $process->wait();
                expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
                $states[] = trim($process->getOutput());
            }
            sort($states);
            expect($states)->toBe($scenario === 'same' ? ['completed', 'reserved'] : ($scenario === 'mixed' ? ['blocked', 'reserved'] : ['blocked', 'completed']));
            expect(is_file($counter) ? substr_count(file_get_contents($counter), 'call') : 0)->toBe($scenario === 'mixed' ? 0 : 1)
                ->and(TenantAiUsageEvent::forTenantId($tenant->id)->count())->toBe(1)
                ->and((int) TenantAiUsageEvent::forTenantId($tenant->id)->sum('buyer_charge_micros'))->toBeLessThanOrEqual(40000);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($barrier.'*') as $file) {
                unlink($file);
            }
            DB::table('marketing_assistant_turns')->where('tenant_id', $tenant->id)->delete();
            TenantAiUsageEvent::forTenantId($tenant->id)->delete();
            $tenant->delete();
            $actor->delete();
        }
    }
});
