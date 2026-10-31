<?php

use App\Models\SubscriptionBillingCycle;
use App\Services\Subscriptions\SubscriptionPilotSourceGuard;
use App\Services\Subscriptions\SubscriptionReplacementAccess;
use App\Services\Subscriptions\SubscriptionShopifyBillingTransport;
use App\Services\Subscriptions\SubscriptionShopifyContractProtocol;
use App\Services\Subscriptions\SubscriptionShopifyContractService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Subscriptions\ShopifyAdoptionScenario;

uses(Tests\TestCase::class);

test('two independent MySQL workers dispatch one original Shopify adoption operation', function (string $operation) {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Disposable MySQL concurrency only.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Refusing contract dispatch test outside disposable local MySQL.');
    }
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $s = new ShopifyAdoptionScenario;
    $transport = app(SubscriptionShopifyBillingTransport::class);
    if ($operation === 'commit') {
        $quote = app(SubscriptionShopifyContractService::class)->calculate($s->tenant->id, $s->actor, $s->agreement->id, $s->agreement->terms_hash, 1);
        $s->sourceStopped = true;
        $row = $s->agreement->fresh();
        $proof = app(SubscriptionPilotSourceGuard::class)->stopped(app(SubscriptionReplacementAccess::class)->member($row), $s->source->id, '2027-01-31', $s->actor);
        $evidence = $row->authorization_evidence;
        $state = $evidence['shopify_adoption'];
        $state['commit_original'] = ['calculation_id' => $state['calculation_id'], 'review_hash' => $quote['quote']['review_hash'], 'actor_id' => $s->actor->id,
            'processor_context' => $transport->context($row), 'source_stop_proof' => $proof, 'prepared_at' => now()->toISOString()];
        $state['commit_send_proof'] = $proof;
    } else {
        $row = $s->agreement->fresh();
        $evidence = $row->authorization_evidence;
        $uuid = (string) Str::uuid();
        $state = ['status' => 'calculation_prepared', 'original' => ['request_uuid' => $uuid, 'actor_id' => $s->actor->id, 'terms_hash' => $row->terms_hash,
            'agreement_revision' => 1, 'processor_context' => $transport->context($row), 'source_context' => $evidence['provider_proof']['source']['credential_context'],
            'input' => SubscriptionShopifyContractProtocol::input($row, $uuid), 'created_at' => now()->toISOString()]];
    }
    $evidence['shopify_adoption'] = $state;
    $row->update(['authorization_evidence' => $evidence]);
    $marker = tempnam(sys_get_temp_dir(), 'shopify-adoption-');
    $s->dispatchMarkerPath = $marker;
    $children = [];
    DB::disconnect('mysql');
    try {
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not start isolated adoption dispatcher.');
            }
            if ($pid === 0) {
                pcntl_alarm(10);
                DB::purge('mysql');
                DB::statement('SET SESSION innodb_lock_wait_timeout=3');
                file_put_contents($marker.'.ready.'.$index, 'ready');
                while (! is_file($marker.'.start')) {
                    usleep(1000);
                }
                try {
                    $transport->contractMutation($row->fresh(), $operation);
                    $result = 'sent';
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                    $result = $error->getStatusCode() === 409 ? 'blocked' : 'unexpected_http';
                } catch (\Throwable) {
                    $result = 'unexpected_error';
                }
                file_put_contents($marker.'.result.'.$index, $result);
                exit(0);
            }
            $children[] = $pid;
        }
        $deadline = microtime(true) + 5;
        while ((! is_file($marker.'.ready.0') || ! is_file($marker.'.ready.1')) && microtime(true) < $deadline) {
            usleep(1000);
        }
        file_put_contents($marker.'.start', 'start');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)->toBeTrue();
        }
        DB::purge('mysql');
        $results = [file_get_contents($marker.'.result.0'), file_get_contents($marker.'.result.1')];
        sort($results);
        expect($results)->toBe(['blocked', 'sent'])->and(array_filter(explode("\n", file_get_contents($marker))))->toHaveCount(1)
            ->and(data_get($row->fresh()->authorization_evidence, 'shopify_adoption.'.$operation.'_dispatched_at'))->not->toBeNull()
            ->and(SubscriptionBillingCycle::where('agreement_id', $row->id)->count())->toBe(0);
    } finally {
        DB::purge('mysql');
        foreach (glob($marker.'*') ?: [] as $file) {
            unlink($file);
        }
        DB::table('subscription_recharge_connections')->where('tenant_id', $s->tenant->id)->update(['active_credential_revision_id' => null]);
        DB::table('subscription_installations')->where('tenant_id', $s->tenant->id)->update(['active_credential_revision_id' => null]);
        foreach (['subscription_billing_actions', 'subscription_billing_cycles', 'subscription_billing_agreements', 'subscription_pilot_events',
            'subscription_pilot_members', 'subscription_recharge_credential_revisions', 'subscription_recharge_connections', 'subscription_credential_revisions',
            'subscription_installations', 'shopify_stores', 'tenant_module_entitlements'] as $table) {
            DB::table($table)->where('tenant_id', $s->tenant->id)->delete();
        }
        $s->actor->tenants()->detach($s->tenant->id);
        DB::table('users')->where('id', $s->actor->id)->delete();
        DB::table('tenants')->where('id', $s->tenant->id)->delete();
    }
})->with(['calculate', 'commit']);
