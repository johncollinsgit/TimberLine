<?php

use App\Models\SubscriptionBillingCycle;
use App\Services\Subscriptions\SubscriptionEvidence;
use App\Services\Subscriptions\SubscriptionPilotSourceGuard;
use App\Services\Subscriptions\SubscriptionShopifyBillingProtocol;
use App\Services\Subscriptions\SubscriptionShopifyBillingTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Subscriptions\ShopifyExecutionScenario;

uses(Tests\TestCase::class);

test('two independent MySQL workers submit at most one original Shopify billing attempt', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Disposable MySQL concurrency only.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Refusing billing dispatch test outside disposable local MySQL.');
    }
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $s = new ShopifyExecutionScenario;
    $s->activate();
    $this->travelTo(CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    $row = $s->agreement->fresh();
    $transport = app(SubscriptionShopifyBillingTransport::class);
    $context = $transport->context($row);
    $proof = app(SubscriptionPilotSourceGuard::class)->stoppedForShopify($row, '2027-01-31', $s->actor);
    $cycle = new SubscriptionBillingCycle(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'processor' => 'shopify',
        'merchant_fingerprint' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical($context)),
        'cycle_date' => '2027-01-31', 'agreement_revision' => $row->revision, 'amount_cents' => 2500, 'currency' => 'USD',
        'terms' => $row->terms + ['terms_hash' => $row->terms_hash, 'original_terms_hash' => $row->terms_hash, 'method_type' => 'shop_pay',
            'shopify_contract_id' => data_get($row->authorization_evidence, 'shopify_adoption.contract_id')],
        'method_binding' => $row->method_binding, 'source_evidence' => $proof + ['processor_context' => $context], 'request_uuid' => (string) Str::uuid()]);
    $cycle->provider_request = SubscriptionShopifyBillingProtocol::payload($cycle);
    $cycle->save();
    $marker = tempnam(sys_get_temp_dir(), 'shopify-billing-');
    $s->billingMarkerPath = $marker;
    $children = [];
    DB::disconnect('mysql');
    try {
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not start isolated billing dispatcher.');
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
                    $transport->bill($cycle->fresh(), $proof);
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
            ->and(data_get($cycle->fresh()->reconciliation, 'dispatch_started_at'))->not->toBeNull()
            ->and(data_get($cycle->fresh()->reconciliation, 'dispatch_source_proof'))->toBe($proof)
            ->and(SubscriptionBillingCycle::where('agreement_id', $row->id)->count())->toBe(1);
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
});
