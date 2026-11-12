<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use App\Services\Marketing\EventCustomerCohort;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/PayMirrorAttributionFixture.php';

beforeEach(function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    config(['pos_live.runtime_id' => 'cohort_projection_fixture', 'cache.default' => 'array']);
});

it('does not query empty source identities when a customer has no original purchase links', function (): void {
    $fixture = payMirrorAttributionFixture('square_order');
    $empty = MarketingProfile::create(['tenant_id' => $fixture['tenant']->id, 'first_name' => 'No linked history']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $history = app(CustomerCommerceSnapshotService::class)->forReportingProfile($empty, $fixture['tenant']->id);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($history['purchases'])->toBe([])->and($history['confirmed_purchase_count'])->toBe(0)
        ->and(collect($queries)->contains(fn ($query) => str_contains($query['query'], '0 = 1')))->toBeFalse();
    Http::assertNothingSent();
});

it('rechecks retained aliases after batch loading before returning an admitted captured contact', function (): void {
    $fixture = payMirrorAttributionFixture('square_order');
    extract($fixture);
    app(StockLedger::class)->rows('event_links', $tenant->id)->insert(['tenant_id' => $tenant->id, 'event_id' => 123, 'provider' => 'manual', 'external_id' => 'capture-alias-race',
        'metadata' => json_encode(['name' => 'Reviewed show', 'timezone' => 'UTC']), 'reviewed_by' => $actor->id]);
    $other = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Different current root']);
    $alias = MarketingProfile::create(['tenant_id' => $tenant->id, 'merged_into_profile_id' => $profile->id, 'archived_at' => now()]);
    MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $alias->id, 'source_type' => 'pos_event_capture', 'source_id' => 'reviewed-contact-capture', 'source_meta' => ['event_id' => 123]]);
    $changed = false;
    DB::listen(function ($query) use (&$changed, $alias, $other): void {
        $sql = str_replace(['"', '`'], '', $query->sql);
        if (! $changed && str_contains($sql, 'from marketing_profiles') && str_contains($sql, 'id in (') && in_array($alias->id, $query->bindings, true)) {
            $changed = true;
            MarketingProfile::whereKey($alias->id)->update(['merged_into_profile_id' => $other->id]);
        }
    });
    try {
        app(EventCustomerCohort::class)->read($tenant->id, 123);
        test()->fail('A current alias change must not return the previous root.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(409)->and($changed)->toBeTrue();
    }
    Http::assertNothingSent();
});

it('keeps guest pending and differently selected Pay mirrors out of reporting cohorts until original explicit attribution is confirmed', function (string $kind): void {
    $fixture = payMirrorAttributionFixture('square_order', true, $kind === 'pending' ? 'unknown' : 'succeeded', $kind === 'pending');
    extract($fixture);
    $stock = app(StockLedger::class);
    $pool = $stock->createPool($tenant->id, 'cohort-reviewed-pool', $actor->id, 'Reviewed show', 'show', 'event:123')['pool_id'];
    $stock->rows('event_links', $tenant->id)->insert(['tenant_id' => $tenant->id, 'event_id' => 123, 'provider' => 'manual', 'external_id' => 'cohort-reviewed-show',
        'metadata' => json_encode(['name' => 'Reviewed show', 'timezone' => 'UTC']), 'reviewed_by' => $actor->id]);
    $snapshot['terms']['stock_pool_id'] = $pool;
    $store->rows('sales', $tenant->id)->where('id', $saleId)->update(['snapshot' => $store->encrypt($snapshot), 'snapshot_hash' => Contract::hash($snapshot)]);
    MarketingProfileLink::forTenantId($tenant->id)->where('source_type', 'square_order')->where('source_id', $orderId)->update(['source_meta' => json_encode([
        'event_attribution' => ['event_id' => 123, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->toIso8601String(), 'evidence_reference' => 'Original reviewed show allocation'],
    ])]);
    if ($kind === 'different_explicit_customer') {
        $explicit = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Explicit receipt customer']);
        $snapshot['customer'] = ['id' => $explicit->id];
        $store->rows('sales', $tenant->id)->where('id', $saleId)->update(['snapshot' => $store->encrypt($snapshot), 'snapshot_hash' => Contract::hash($snapshot)]);
    }
    $cohorts = app(EventCustomerCohort::class);
    expect($cohorts->read($tenant->id, 123)['profile_ids'])->not->toContain($profile->id);
    if ($kind === 'different_explicit_customer') {
        $this->travel(6)->seconds();
        expect($cohorts->read($tenant->id, 123)['profile_ids'])->not->toContain($profile->id)
            ->and(app(\App\Services\Pos\PosService::class)->snapshot($store->rows('sales', $tenant->id)->where('id', $saleId)->first())['customer']['id'])->toBe($explicit->id);
    } else {
        $alias = MarketingProfile::create(['tenant_id' => $tenant->id, 'merged_into_profile_id' => $profile->id, 'archived_at' => now()]);
        $stock->rows('receipt_associations', $tenant->id)->insert(['tenant_id' => $tenant->id, 'runtime_id' => $store->runtime(), 'sale_id' => $saleId, 'actor_id' => $actor->id,
            'customer_profile_id' => $alias->id, 'contact' => $store->encrypt([]), 'reward_context' => $store->encrypt(['enabled' => false]),
            'request_hash' => hash('sha256', 'cohort-association-'.$saleId), 'input_hash' => hash('sha256', 'cohort-association-'.$saleId), 'created_at' => now(), 'updated_at' => now()]);
        MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $alias->id, 'source_type' => 'pos_sale', 'source_id' => $store->runtime().':'.$saleId]);
        if ($kind === 'pending') {
            // Provider auto-identification alone cannot complete the original pending Pay payment.
            expect($cohorts->read($tenant->id, 123)['profile_ids'])->not->toContain($profile->id);
            $store->rows('sales', $tenant->id)->where('id', $saleId)->update(['state' => 'succeeded']);
            DB::table('payment_core_operations')->where('id', $operationId)->update(['state' => 'succeeded', 'provider_id' => $paymentId]);
        }
        $this->travel(6)->seconds();
        expect($cohorts->read($tenant->id, 123)['profile_ids'])->toBe([$profile->id]);
        // A later explicit source-link conflict is visible on the next bounded projection refresh.
        $conflict = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Conflicting retained link']);
        MarketingProfileLink::forTenantId($tenant->id)->where('source_type', 'square_order')->where('source_id', $orderId)->update(['marketing_profile_id' => $conflict->id]);
        $this->travel(6)->seconds();
        expect($cohorts->read($tenant->id, 123)['profile_ids'])->not->toContain($profile->id);
    }
    expect($store->rows('sales', $tenant->id)->where('id', $saleId)->value('snapshot_hash'))->toBe(Contract::hash($snapshot));
    Http::assertNothingSent();
})->with(['guest', 'pending', 'different_explicit_customer']);
