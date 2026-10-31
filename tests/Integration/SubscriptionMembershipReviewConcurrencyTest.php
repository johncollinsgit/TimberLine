<?php

use App\Models\SubscriptionBillingAction;
use App\Services\Subscriptions\SubscriptionMembershipReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\BraintreeScenario;

uses(Tests\TestCase::class);

test('concurrent membership supplements retain one original review despite older MySQL snapshots', function (bool $sameRequest) {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Disposable MySQL concurrency only.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Refusing membership review concurrency outside disposable local MySQL.');
    }
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $s = new BraintreeScenario(candleClub: false);
    $s->activate();
    $row = $s->agreement->fresh();
    $original = (array) DB::table('subscription_billing_agreements')->where('id', $row->id)->first();
    $input = ['membership_program' => 'candle_club', 'membership_reference' => 'original-club:789', 'source_membership_reviewed' => true,
        'customer_gid' => 'gid://shopify/Customer/456', 'source_subscription_ids' => ['789']];
    $review = app(SubscriptionMembershipReviewService::class)->preview($row->tenant_id, $s->actor, $row->id, $input);
    $requests = [(string) Str::uuid(), (string) Str::uuid()];
    if ($sameRequest) {
        $requests[1] = $requests[0];
    }
    $marker = tempnam(sys_get_temp_dir(), 'club-membership-concurrency-');
    $children = [];
    DB::disconnect('mysql');
    try {
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not start membership review worker.');
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
                $before = Http::recorded()->count();
                try {
                    $action = app(SubscriptionMembershipReviewService::class)->record($row->tenant_id, $s->actor, $row->id,
                        $input, $review['review_hash'], $requests[$index], $review['revision']);
                    DB::commit();
                    $result = ['status' => 'recorded', 'id' => $action->id];
                } catch (HttpException $error) {
                    DB::rollBack();
                    $result = ['status' => $error->getStatusCode() === 409 ? 'review_conflict' : 'unexpected_http', 'id' => null];
                } catch (\Throwable $error) {
                    DB::rollBack();
                    $result = ['status' => 'unexpected_error', 'error' => get_class($error).': '.$error->getMessage()];
                }
                $result['provider_requests'] = Http::recorded()->count() - $before;
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
        file_put_contents($marker.'.start', 'start');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)->toBeTrue();
        }
        DB::purge('mysql');
        $results = [json_decode(file_get_contents($marker.'.result.0'), true), json_decode(file_get_contents($marker.'.result.1'), true)];
        $statuses = array_column($results, 'status');
        sort($statuses);
        expect($statuses)->toBe($sameRequest ? ['recorded', 'recorded'] : ['recorded', 'review_conflict'])
            ->and(array_sum(array_column($results, 'provider_requests')))->toBe(0)
            ->and(SubscriptionBillingAction::where('agreement_id', $row->id)->where('kind', 'membership_review')->count())->toBe(1)
            ->and((array) DB::table('subscription_billing_agreements')->where('id', $row->id)->first())->toBe($original)
            ->and(app(SubscriptionMembershipReviewService::class)->program($row->fresh()))->toBe('candle_club');
        if ($sameRequest) {
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
})->with(['same original request' => true, 'different competing requests' => false]);
