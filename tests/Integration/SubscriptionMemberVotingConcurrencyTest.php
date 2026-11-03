<?php

use App\Models\MarketingProfile;
use App\Models\SubscriptionMemberVote;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionModuleService;
use App\Services\Subscriptions\SubscriptionReplacementCommunityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Subscriptions\BraintreeScenario;

uses(Tests\TestCase::class);

test('independent MySQL member requests preserve ballots and notes across concurrent changes', function (string $race) {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Disposable MySQL concurrency only.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Refusing member voting concurrency outside disposable local MySQL.');
    }
    expect(function_exists('pcntl_fork'))->toBeTrue();
    Mail::fake();
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $s = new BraintreeScenario;
    $s->store->update(['store_key' => 'retail']);
    $s->activate();
    $profile = MarketingProfile::factory()->create(['tenant_id' => $s->tenant->id]);
    $session = new ModernForestryMobileCustomerSession($profile, 'fictional', ['source_type' => 'shopify_customer', 'shopify_customer_id' => '456']);
    $poll = DB::table('subscription_polls')->insertGetId(['tenant_id' => $s->tenant->id, 'poll_type' => SubscriptionModuleService::CANDLE_CLUB_TYPE,
        'title' => 'Fictional concurrency poll', 'status' => 'open', 'share_token' => Str::random(40), 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay()]);
    $options = [];
    foreach (['Forest', 'Meadow'] as $label) {
        $options[] = DB::table('subscription_poll_options')->insertGetId(['tenant_id' => $s->tenant->id, 'subscription_poll_id' => $poll, 'label' => $label]);
    }
    $scent = DB::table('subscription_candle_club_monthly_scents')->insertGetId(['tenant_id' => $s->tenant->id, 'year' => 2027, 'month' => 1,
        'title' => 'Fictional concurrent scent', 'photo_url' => 'https://images.example.test/forest.jpg']);
    $comment = ['request_id' => (string) Str::uuid(), 'body' => 'The original accepted scent note.', 'rating' => 5];
    if ($race === 'legacy_cutover') {
        config(['services.shopify.stores.retail.shop' => $s->store->shop_domain,
            'services.shopify.stores.retail.client_id' => 'fictional-club-subscription-app']);
        $binding = app(\App\Services\Subscriptions\SubscriptionAccessService::class)->bindStore($s->tenant->id,
            \App\Services\Shopify\ShopifyStores::find('retail', true), $s->actor);
        expect(app(\App\Services\Subscriptions\SubscriptionAccessService::class)->assertBinding($s->tenant->id))->toEqual($binding);
        expect(app(\App\Services\Subscriptions\SubscriptionAccessService::class)->bindStore($s->tenant->id,
            \App\Services\Shopify\ShopifyStores::find('retail', true), $s->actor))->toBe($binding);
        app(SubscriptionModuleService::class)->saveCandleClubSettings($s->tenant->id, [], $s->actor->id);
        DB::table('subscription_billing_agreements')->where('id', $s->agreement->id)->update(['activated_at' => null]);
        DB::table('subscription_contracts')->insert(['tenant_id' => $s->tenant->id, 'marketing_profile_id' => $profile->id,
            'shopify_subscription_contract_gid' => 'gid://shopify/SubscriptionContract/1234', 'recharge_subscription_id' => '789',
            'shopify_customer_gid' => 'gid://shopify/Customer/456', 'is_candle_club' => true, 'status' => 'active']);
    }
    $marker = tempnam(sys_get_temp_dir(), 'member-voting-');
    $children = [];
    DB::disconnect('mysql');
    try {
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not start member voting worker.');
            }
            if ($pid === 0) {
                pcntl_alarm(10);
                DB::purge('mysql');
                DB::statement('SET SESSION innodb_lock_wait_timeout=3');
                file_put_contents($marker.'.ready.'.$index, 'ready');
                while (! is_file($marker.'.start')) {
                    usleep(1000);
                }
                if ($race !== 'competing' && $race !== 'legacy_cutover') {
                    $base = new SubscriptionReplacementCommunityService;
                    $first = true;
                    $service = Mockery::mock(SubscriptionReplacementCommunityService::class)->makePartial();
                    $service->shouldReceive('membership')->andReturnUsing(function ($session, $activeOnly = false) use ($base, &$first, $marker, $index) {
                        $membership = $base->membership($session, $activeOnly);
                        if ($first) {
                            $first = false;
                            file_put_contents($marker.'.snapshot.'.$index, 'read');
                            while (! is_file($marker.'.continue')) {
                                usleep(1000);
                            }
                        }

                        return $membership;
                    });
                    app()->instance(SubscriptionReplacementCommunityService::class, $service);
                }
                if ($race === 'legacy_cutover') {
                    DB::beginTransaction();
                    DB::table('subscription_billing_agreements')->where('id', $s->agreement->id)->first();
                    file_put_contents($marker.'.snapshot.'.$index, 'read');
                    while (! is_file($marker.'.continue')) {
                        usleep(1000);
                    }
                }
                $before = Http::recorded()->count();
                $error = null;
                try {
                    $result = match ($race) {
                        'comment_retries' => app(SubscriptionModuleService::class)->submitReplacementScentFeedback($session, $scent, $comment),
                        'legacy_cutover' => app(SubscriptionModuleService::class)->recordCustomerCandleClubAction($profile, 'vote', ['poll_id' => $poll, 'option_id' => $options[$index]]),
                        default => app(SubscriptionReplacementCommunityService::class)->vote($session, ['poll_id' => $poll, 'option_id' => $options[$index]]),
                    };
                    $status = $result['status'];
                    if ($race === 'legacy_cutover') {
                        DB::commit();
                    }
                } catch (\Throwable $exception) {
                    $status = 'unexpected_error';
                    $error = get_class($exception).': '.$exception->getMessage();
                }
                file_put_contents($marker.'.result.'.$index, json_encode(['status' => $status, 'id' => $result['id'] ?? null, 'error' => $error,
                    'provider_requests' => Http::recorded()->count() - $before]));
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
        if ($race !== 'competing') {
            $deadline = microtime(true) + 5;
            while ((! is_file($marker.'.snapshot.0') || ! is_file($marker.'.snapshot.1')) && microtime(true) < $deadline) {
                usleep(1000);
            }
            expect(is_file($marker.'.snapshot.0') && is_file($marker.'.snapshot.1'))->toBeTrue();
            DB::purge('mysql');
            if ($race === 'pause') {
                app(SubscriptionBillingAgreementService::class)->change($s->tenant->id, $s->actor, $s->agreement->id, 'pause', (string) Str::uuid(), 2);
            } elseif ($race === 'poll_closed') {
                DB::table('subscription_polls')->where('tenant_id', $s->tenant->id)->where('id', $poll)->update(['status' => 'closed']);
            } elseif ($race === 'legacy_cutover') {
                DB::table('subscription_billing_agreements')->where('id', $s->agreement->id)->update(['activated_at' => now()]);
            }
            file_put_contents($marker.'.continue', 'continue');
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)->toBeTrue();
        }
        DB::purge('mysql');
        $results = [json_decode(file_get_contents($marker.'.result.0'), true), json_decode(file_get_contents($marker.'.result.1'), true)];
        expect(array_column($results, 'error'))->toBe([null, null]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $expected = match ($race) {
            'pause', 'legacy_cutover' => ['not_eligible', 'not_eligible'],
            'poll_closed' => ['poll_not_open', 'poll_not_open'],
            'comment_retries' => ['feedback_recorded', 'feedback_recorded'],
            default => ['already_voted', 'vote_recorded'],
        };
        expect($statuses)->toBe($expected)
            ->and(array_sum(array_column($results, 'provider_requests')))->toBe(0)
            ->and(SubscriptionMemberVote::where('member_id', $s->agreement->member_id)->count())->toBe($race === 'competing' ? 1 : 0)
            ->and(array_sum(array_column(app(SubscriptionModuleService::class)->pollPayload($poll)['options'], 'votes')))->toBe($race === 'competing' ? 1 : 0)
            ->and($s->agreement->fresh()->revision)->toBe($race === 'pause' ? 3 : 2)->and($s->sales)->toBe([]);
        expect(DB::table('subscription_candle_club_scent_feedback')->where('tenant_id', $s->tenant->id)->count())->toBe($race === 'comment_retries' ? 1 : 0);
        expect(DB::table('subscription_votes')->where('tenant_id', $s->tenant->id)->count())->toBe(0);
        if ($race === 'comment_retries') {
            expect($results[0]['id'])->toBeInt()->toBe($results[1]['id']);
        }
    } finally {
        DB::purge('mysql');
        foreach (glob($marker.'*') ?: [] as $file) {
            unlink($file);
        }
        foreach (['subscription_votes', 'subscription_contracts', 'subscription_candle_club_scent_feedback', 'subscription_candle_club_monthly_scents', 'subscription_member_votes', 'subscription_billing_actions', 'subscription_billing_cycles', 'subscription_billing_agreements',
            'subscription_pilot_events', 'subscription_pilot_members', 'subscription_poll_options', 'subscription_polls', 'subscription_lifecycle_events', 'subscription_candle_club_settings', 'subscription_module_settings', 'marketing_profiles',
            'integration_connections', 'subscription_recharge_connections', 'shopify_stores', 'tenant_module_entitlements'] as $table) {
            DB::table($table)->where('tenant_id', $s->tenant->id)->delete();
        }
        $s->actor->tenants()->detach($s->tenant->id);
        DB::table('users')->where('id', $s->actor->id)->delete();
        DB::table('tenants')->where('id', $s->tenant->id)->delete();
    }
})->with(['competing votes' => 'competing', 'pause after initial read' => 'pause', 'poll closes after initial read' => 'poll_closed',
    'same scent-note retries' => 'comment_retries', 'legacy request crosses cutover' => 'legacy_cutover']);
