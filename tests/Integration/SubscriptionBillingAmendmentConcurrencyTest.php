<?php

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingCycle;
use App\Services\Subscriptions\SubscriptionAmendmentQuote;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionBillingAmendmentService;
use App\Services\Subscriptions\SubscriptionBillingAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\AmendmentScenario;

uses(Tests\TestCase::class);

test('MySQL delivery amendments serialize with original requests current owner stops and collection claims', function (string $race) {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Disposable MySQL concurrency only.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Refusing amendment concurrency outside disposable local MySQL.');
    }
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $s = new AmendmentScenario;
    $row = $s->agreement->fresh();
    $input = $s->change();
    $approval = $s->approval();
    $quote = app(SubscriptionAmendmentQuote::class)->calculate($row, $input, $s->actor);
    $requests = [(string) Str::uuid(), (string) Str::uuid()];
    if ($race === 'same') {
        $requests[1] = $requests[0];
    }
    $marker = tempnam(sys_get_temp_dir(), 'subscription-amendment-race-');
    $children = [];
    DB::disconnect('mysql');
    try {
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not start amendment worker.');
            }
            if ($pid === 0) {
                pcntl_alarm(10);
                DB::purge('mysql');
                DB::statement('SET SESSION innodb_lock_wait_timeout=3');
                DB::beginTransaction();
                SubscriptionBillingAction::where('agreement_id', $row->id)->get();
                file_put_contents($marker.'.ready.'.$index, 'snapshot');
                while (! is_file($marker.'.start')) {
                    usleep(1000);
                }
                try {
                    $action = app(SubscriptionBillingAmendmentService::class)->apply($row->tenant_id, $s->actor, $row->id,
                        $input, $approval, $quote['quote_hash'], $requests[$index], $quote['subject']['revision']);
                    DB::commit();
                    $result = ['status' => 'accepted', 'id' => $action->id];
                } catch (HttpException $error) {
                    DB::rollBack();
                    $result = ['status' => (string) $error->getStatusCode(), 'id' => null];
                } catch (\Throwable $error) {
                    DB::rollBack();
                    $result = ['status' => 'unexpected_error', 'error' => get_class($error).': '.$error->getMessage()];
                }
                $result['sales'] = count($s->sales);
                $result['orders'] = count($s->orderCreates);
                file_put_contents($marker.'.result.'.$index, json_encode($result));
                exit(0);
            }
            $children[] = $pid;
        }
        $deadline = microtime(true) + 5;
        while ((! is_file($marker.'.ready.0') || ! is_file($marker.'.ready.1')) && microtime(true) < $deadline) {
            usleep(1000);
        }
        expect(is_file($marker.'.ready.0') && is_file($marker.'.ready.1'))->toBeTrue();
        DB::purge('mysql');
        if ($race === 'pause') {
            app(SubscriptionBillingAgreementService::class)->change($row->tenant_id, $s->actor, $row->id, 'pause', (string) Str::uuid(), $row->revision);
        } elseif ($race === 'owner') {
            $s->actor->tenants()->updateExistingPivot($row->tenant_id, ['role' => 'manager']);
        } elseif ($race === 'cycle') {
            SubscriptionBillingCycle::create(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'processor' => 'braintree',
                'merchant_fingerprint' => str_repeat('a', 64), 'cycle_date' => '2027-01-31', 'agreement_revision' => $row->revision,
                'amount_cents' => 2500, 'currency' => 'USD', 'terms' => $row->terms, 'method_binding' => $row->method_binding,
                'source_evidence' => [], 'provider_request' => [], 'request_uuid' => (string) Str::uuid(), 'payment_status' => 'prepared']);
        }
        file_put_contents($marker.'.start', 'start');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)->toBeTrue();
        }
        DB::purge('mysql');
        $results = [json_decode(file_get_contents($marker.'.result.0'), true), json_decode(file_get_contents($marker.'.result.1'), true)];
        $statuses = array_column($results, 'status');
        sort($statuses);
        $expected = match ($race) {
            'same' => ['accepted', 'accepted'], 'different' => ['409', 'accepted'], 'owner' => ['403', '403'], default => ['409', '409'],
        };
        $accepted = in_array($race, ['same', 'different'], true);
        expect($statuses)->toBe($expected)->and(array_sum(array_column($results, 'sales')))->toBe(0)->and(array_sum(array_column($results, 'orders')))->toBe(0)
            ->and(SubscriptionBillingAction::where('agreement_id', $row->id)->where('kind', 'terms_amended')->count())->toBe($accepted ? 1 : 0)
            ->and($row->fresh()->terms['amount_cents'])->toBe($accepted ? 2675 : 2500)
            ->and($row->fresh()->revision)->toBe($accepted || $race === 'pause' ? 3 : 2)
            ->and($row->fresh()->status)->toBe($race === 'pause' ? 'paused' : 'active');
        SubscriptionBillingAuthorization::assertCurrent($row->fresh());
        if ($race === 'same') {
            expect($results[0]['id'])->toBe($results[1]['id']);
        }
    } finally {
        DB::purge('mysql');
        foreach (glob($marker.'*') ?: [] as $file) {
            unlink($file);
        }
        foreach (['subscription_billing_actions', 'subscription_billing_cycles', 'subscription_billing_agreements', 'subscription_pilot_events',
            'subscription_pilot_members', 'integration_connections', 'subscription_recharge_connections', 'shopify_stores', 'tenant_module_entitlements'] as $table) {
            DB::table($table)->where('tenant_id', $s->tenant->id)->delete();
        }
        $s->actor->tenants()->detach($s->tenant->id);
        DB::table('users')->where('id', $s->actor->id)->delete();
        DB::table('tenants')->where('id', $s->tenant->id)->delete();
    }
})->with(['same original request' => 'same', 'competing requests' => 'different', 'pause after snapshot' => 'pause', 'owner revoked after snapshot' => 'owner', 'cycle claimed after snapshot' => 'cycle']);
