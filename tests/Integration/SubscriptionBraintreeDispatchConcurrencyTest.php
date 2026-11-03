<?php

use App\Models\SubscriptionBillingCycle;
use App\Services\Subscriptions\SubscriptionBraintreeTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Subscriptions\BraintreeScenario;

uses(Tests\TestCase::class);

test('two independent MySQL dispatchers submit at most one original Braintree sale', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Disposable MySQL concurrency only.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Refusing Braintree dispatch test outside disposable local MySQL.');
    }
    expect(function_exists('pcntl_fork'))->toBeTrue();
    Mail::fake();
    Queue::fake();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 15)->setTime(10, 0)->utc());
    $scenario = new BraintreeScenario;
    $agreement = $scenario->activate();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $marker = tempnam(sys_get_temp_dir(), 'braintree-dispatch-');
    $scenario->saleMarkerPath = $marker;
    $transport = app(SubscriptionBraintreeTransport::class);
    $context = $transport->context($agreement->tenant_id, $agreement->method_binding);
    $cycle = new SubscriptionBillingCycle(['tenant_id' => $agreement->tenant_id, 'agreement_id' => $agreement->id,
        'public_id' => (string) Str::uuid(), 'processor' => 'braintree', 'merchant_fingerprint' => $context['merchant_fingerprint'],
        'cycle_date' => '2027-01-31', 'agreement_revision' => $agreement->revision, 'amount_cents' => 2500, 'currency' => 'USD',
        'terms' => $agreement->terms + ['terms_hash' => $agreement->terms_hash, 'method_type' => 'paypal'], 'method_binding' => $agreement->method_binding,
        'source_evidence' => ['checked_at' => now()->toISOString()], 'request_uuid' => (string) Str::uuid(),
        'payment_started_at' => now(), 'payment_status' => 'sending']);
    $cycle->provider_request = SubscriptionBraintreeTransport::payload($cycle);
    $cycle->save();
    $children = [];
    // No PDO socket or open transaction is inherited by either child.
    DB::disconnect('mysql');
    try {
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not start disposable dispatch worker.');
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
                    $transport->sale($cycle->fresh());
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
        expect($results)->toBe(['blocked', 'sent'])
            ->and(array_filter(explode("\n", file_get_contents($marker))))->toHaveCount(1)
            ->and(data_get($cycle->fresh()->reconciliation, 'dispatch_started_at'))->not->toBeNull();
    } finally {
        DB::purge('mysql');
        foreach (glob($marker.'*') ?: [] as $file) {
            unlink($file);
        }
        foreach (['subscription_billing_actions', 'subscription_billing_cycles', 'subscription_billing_agreements',
            'subscription_pilot_events', 'subscription_pilot_members', 'subscription_recharge_connections', 'integration_connections',
            'shopify_stores', 'tenant_module_entitlements'] as $table) {
            DB::table($table)->where('tenant_id', $scenario->tenant->id)->delete();
        }
        $scenario->actor->tenants()->detach($scenario->tenant->id);
        DB::table('users')->where('id', $scenario->actor->id)->delete();
        DB::table('tenants')->where('id', $scenario->tenant->id)->delete();
    }
});
