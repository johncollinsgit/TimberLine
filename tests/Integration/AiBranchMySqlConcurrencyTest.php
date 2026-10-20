<?php

use App\Models\AiBranchAccount;
use App\Models\AiBranchAction;
use App\Models\AiBranchConversation;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use App\Services\AiBranch\AiBranchActions;
use App\Services\AiBranch\AiBranchChat;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(Tests\TestCase::class);

it('serializes independent MySQL processes at the final quota slot and at note approval', function (string $mode): void {
    if (DB::connection()->getDriverName() !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/', DB::connection()->getDatabaseName())) {
        $this->markTestSkipped('Requires a complete disposable MySQL baseline.');
    }
    $slug = 'ai-concurrency-'.bin2hex(random_bytes(8));
    $tenant = Tenant::query()->create(['name' => 'Fictional concurrency fixture', 'slug' => $slug]);
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantAccessAddon::query()->create(['tenant_id' => $tenant->id, 'addon_key' => 'ai_branch_growth', 'enabled' => true, 'source' => 'stripe_fulfillment']);
    TenantBillingSubscription::query()->create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_subscription_reference' => 'sub_'.$slug, 'purchase_key' => 'addon.ai_branch_growth', 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
    AiBranchAccount::query()->create(['tenant_id' => $tenant->id, 'enabled' => true, 'budget_cents' => 1500]);
    config()->set(['ai_branch.enabled' => true, 'ai_branch.actions_enabled' => true, 'ai_branch.tenant_ids' => [$tenant->id]]);
    if ($mode === 'wallet') {
        \App\Models\TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'ai_branch', 'enabled_status' => 'enabled',
            'availability_status' => 'available', 'billing_status' => 'included', 'entitlement_source' => \App\Services\AiBranch\AiBranchOffer::INCLUDED_SOURCE]);
        TenantAccessProfile::where('tenant_id', $tenant->id)->firstOrFail()->update(['metadata' => ['ai_branch' => ['model' => 'gpt-6-luna', 'usage_terms' => ['multiplier' => 2, 'version' => config('ai_branch.rates_version'),
            'rates_digest' => hash('sha256', json_encode(config('ai_branch.models')['gpt-6-luna'], JSON_THROW_ON_ERROR))]]]]);
        config(['ai_branch.standard_enabled' => true, 'ai_branch.existing_tenants' => [$tenant->id => $tenant->slug]]);
        app(\App\Services\Marketing\Messaging\TenantMessagingUsageService::class)->fund($tenant->id, 6000, 'fictional-wallet-race');
    }
    $dir = sys_get_temp_dir().'/'.$slug;
    mkdir($dir);
    $processes = [];
    try {
        $requests = [];
        if (in_array($mode, ['quota', 'wallet'], true)) {
            foreach ([1, 2] as $number) {
                $conversation = app(AiBranchChat::class)->start($tenant, $user);
                $requests[] = ['conversation' => $conversation->id];
            }
        } elseif (str_starts_with($mode, 'briefing')) {
            $uuid = (string) Illuminate\Support\Str::uuid();
            $requests = [['uuid' => $uuid], ['uuid' => $mode === 'briefing_replay' ? $uuid : (string) Illuminate\Support\Str::uuid()]];
        } else {
            $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Fictional job', 'status' => 'open']);
            $action = app(AiBranchActions::class)->propose($tenant, $user, $job->id, 'Reviewed fictional note', (string) Illuminate\Support\Str::uuid());
            $requests = array_fill(0, 2, ['action' => $action->id, 'digest' => $action->digest]);
        }
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d = json_decode(stream_get_contents(STDIN), true);
config()->set(['ai_branch.enabled' => true, 'ai_branch.actions_enabled' => true, 'ai_branch.tenant_ids' => [$d['tenant']], 'ai_branch.api_key' => 'fake', 'ai_branch.model' => 'fake', 'ai_branch.input_micros_per_million' => 1000000, 'ai_branch.output_micros_per_million' => 4000000, 'module_catalog.addons.ai_branch_growth.ai_limits.responses' => 1]);
config()->set(['ai_branch.workspace_context_enabled' => str_starts_with($d['mode'], 'briefing'), 'ai_branch.workspace_context_tenant_ids' => [$d['tenant']]]);
if ($d['mode'] === 'wallet') {
    config(['ai_branch.standard_enabled' => true, 'ai_branch.existing_tenants' => [$d['tenant'] => App\Models\Tenant::findOrFail($d['tenant'])->slug], 'ai_branch.rates_valid_until' => now()->addDay()->toIso8601String()]);
}
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function () use ($d) {
    file_put_contents($d['calls'], "dispatch\n", FILE_APPEND | LOCK_EX);
    usleep(500000);
    return Illuminate\Support\Facades\Http::response(['id' => 'fake', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Fictional answer']]]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 30]]);
});
for ($n = 0; ! file_exists($d['barrier']) && $n < 500; $n++) { usleep(10000); }
$tenant = App\Models\Tenant::findOrFail($d['tenant']);
$user = App\Models\User::findOrFail($d['user']);
try {
    if (in_array($d['mode'], ['quota', 'wallet'], true)) {
        app(App\Services\AiBranch\AiBranchChat::class)->ask($tenant, $user, $d['conversation'], 'Fictional question', (string) Illuminate\Support\Str::uuid());
    } elseif (str_starts_with($d['mode'], 'briefing')) {
        app(App\Services\AiBranch\AiBranchChat::class)->briefing($tenant, $user, $d['uuid']);
    } else {
        app(App\Services\AiBranch\AiBranchActions::class)->decide($tenant, $user, $d['action'], $d['digest'], true);
    }
    echo 'ok';
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
    if ($e->getStatusCode() === 409 && $d['mode'] === 'briefing_replay') { echo 'pending'; }
    elseif ($e->getStatusCode() === 429) { echo 'quota_blocked'; }
    else { throw $e; }
}
PHP;
        $cfg = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $cfg['host'], 'DB_PORT' => (string) $cfg['port'], 'DB_DATABASE' => $cfg['database'], 'DB_USERNAME' => $cfg['username'], 'DB_PASSWORD' => $cfg['password'], 'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'null'];
        foreach ($requests as $request) {
            $process = new Process([PHP_BINARY, '-r', $code], base_path(), $env, json_encode([...$request, 'mode' => $mode, 'tenant' => $tenant->id, 'user' => $user->id, 'barrier' => $dir.'/go', 'calls' => $dir.'/calls']), 20);
            $process->start();
            $processes[] = $process;
        }
        touch($dir.'/go');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = $process->getOutput();
        }
        sort($results);
        if (in_array($mode, ['quota', 'briefing_quota', 'wallet'], true)) {
            expect($results)->toBe(['ok', 'quota_blocked'])
                ->and(TenantAiUsageEvent::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
            if ($mode === 'wallet') {
                expect(\App\Models\TenantMessagingCreditAccount::forAllTenants()->where('tenant_id', $tenant->id)->value('reserved_micros'))->toBe(0)
                    ->and(\App\Models\TenantMessagingLedgerEntry::forAllTenants()->where('tenant_id', $tenant->id)->where('entry_type', 'usage_settlement')->count())->toBe(1)
                    ->and(file($dir.'/calls'))->toHaveCount(1);
            }
        } elseif ($mode === 'briefing_replay') {
            expect($results)->toBeIn([['ok', 'ok'], ['ok', 'pending']])
                ->and(TenantAiUsageEvent::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
        } else {
            expect($results)->toBe(['ok', 'ok'])
                ->and(FieldServiceJobNote::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
        }
        if (str_starts_with($mode, 'briefing')) {
            expect(AiBranchConversation::query()->where('tenant_id', $tenant->id)->count())->toBe(1)
                ->and(file($dir.'/calls'))->toHaveCount(1);
        }
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        DB::table('tenant_messaging_ledger_entries')->where('tenant_id', $tenant->id)->delete();
        DB::table('tenant_messaging_credit_accounts')->where('tenant_id', $tenant->id)->delete();
        \App\Models\TenantModuleEntitlement::where('tenant_id', $tenant->id)->delete();
        foreach ([TenantAiUsageEvent::class, AiBranchAction::class, AiBranchConversation::class, AiBranchAccount::class, FieldServiceJobNote::class, FieldServiceJob::class, TenantBillingSubscription::class, TenantAccessAddon::class, TenantAccessProfile::class] as $model) {
            $model::query()->where('tenant_id', $tenant->id)->delete();
        }
        $user->delete();
        $tenant->delete();
        if (file_exists($dir.'/go')) {
            unlink($dir.'/go');
        }
        if (file_exists($dir.'/calls')) {
            unlink($dir.'/calls');
        }
        rmdir($dir);
    }
})->with(['quota', 'approval', 'briefing_quota', 'briefing_replay', 'wallet']);
