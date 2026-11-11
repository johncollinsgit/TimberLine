<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingSegment;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use App\Services\Marketing\MarketingSegmentEvaluator;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\PaymentJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/PayMirrorAttributionFixture.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['pos_live.runtime_id' => 'pay_mirror_fixture', 'cache.default' => 'array']);
});

it('keeps imported guest Pay mirrors out of customer purchases and audiences until explicit canonical receipt attribution', function (string $sourceType, bool $hasProviderOrder): void {
    $fixture = payMirrorAttributionFixture($sourceType, $hasProviderOrder);
    extract($fixture);
    $commerce = app(CustomerCommerceSnapshotService::class);
    $segment = new MarketingSegment(['tenant_id' => $tenant->id, 'rules_json' => ['logic' => 'and', 'conditions' => [['field' => 'total_orders', 'operator' => 'gt', 'value' => 0]]]]);
    $before = $commerce->forProfile($profile, $tenant->id);
    expect($before['confirmed_purchase_count'])->toBe(0)->and($before['purchases'])->toBe([])
        ->and($before['issues'])->toContain('customer_identity_review_required')
        ->and(app(MarketingSegmentEvaluator::class)->evaluateProfile($segment, $profile)['matched'])->toBeFalse();

    $associations = app(StockLedger::class)->rows('receipt_associations', $tenant->id);
    $associations->insert(['tenant_id' => $tenant->id, 'runtime_id' => $store->runtime(), 'sale_id' => $saleId, 'actor_id' => $actor->id,
        'customer_profile_id' => null, 'contact' => $store->encrypt(['email' => 'receipt-only@example.invalid']), 'reward_context' => $store->encrypt(['enabled' => false]),
        'request_hash' => hash('sha256', 'fictional-contact-'.$saleId), 'input_hash' => hash('sha256', 'fictional-contact-'.$saleId), 'created_at' => now(), 'updated_at' => now()]);
    expect($commerce->forProfile($profile, $tenant->id)['confirmed_purchase_count'])->toBe(0);

    $alias = MarketingProfile::create(['tenant_id' => $tenant->id, 'merged_into_profile_id' => $profile->id, 'archived_at' => now()]);
    $associations->where('sale_id', $saleId)->update(['customer_profile_id' => $alias->id]);
    MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $alias->id, 'source_type' => 'pos_sale', 'source_id' => $store->runtime().':'.$saleId]);
    $this->travel(6)->seconds();
    $after = $commerce->forProfile($profile, $tenant->id);
    expect($after['confirmed_purchase_count'])->toBe(1)->and($after['purchases'])->toHaveCount(1)
        ->and($after['purchases'][0]['source'])->toBe('pos_sale')->and($after['purchases'][0]['source_id'])->toBe($saleId)
        ->and(app(MarketingSegmentEvaluator::class)->evaluateProfile($segment, $profile)['matched'])->toBeTrue()
        ->and(SquarePayment::forTenantId($tenant->id)->where('square_payment_id', $paymentId)->count())->toBe(1)
        ->and($store->rows('sales', $tenant->id)->where('id', $saleId)->value('snapshot_hash'))->toBe(Contract::hash($snapshot));
    Http::assertNothingSent();
})->with(['original order' => ['square_order', true], 'order identified through original payment' => ['square_order', false], 'original payment' => ['square_payment', true]]);

it('preserves unrelated external Square purchases and scopes Pay mirror checks to the original tenant', function (): void {
    $fixture = payMirrorAttributionFixture('square_order');
    extract($fixture);
    SquareOrder::create(['tenant_id' => $tenant->id, 'square_order_id' => 'unrelated-external-order', 'state' => 'COMPLETED', 'closed_at' => now()->subDay(),
        'location_id' => 'fictional-location', 'total_money_amount' => 2000, 'total_money_currency' => 'USD']);
    SquarePayment::create(['tenant_id' => $tenant->id, 'square_payment_id' => 'unrelated-external-payment', 'square_order_id' => 'unrelated-external-order', 'status' => 'COMPLETED',
        'amount_money' => 2000, 'currency' => 'USD', 'location_id' => 'fictional-location', 'source_type' => 'CASH', 'created_at_source' => now()->subDay(), 'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_order', 'source_id' => 'unrelated-external-order']);
    $read = app(CustomerCommerceSnapshotService::class)->forProfile($profile, $tenant->id);
    expect(collect($read['purchases'])->pluck('source_id')->all())->toBe(['unrelated-external-order']);
    $other = Tenant::create(['name' => 'Different policy merchant', 'slug' => 'pay-mirror-other-'.Str::uuid()]);
    $otherProfile = MarketingProfile::create(['tenant_id' => $other->id, 'first_name' => 'Independent customer']);
    SquarePayment::create(['tenant_id' => $other->id, 'square_payment_id' => $paymentId, 'status' => 'COMPLETED', 'amount_money' => 500, 'currency' => 'USD', 'source_type' => 'CASH']);
    MarketingProfileLink::create(['tenant_id' => $other->id, 'marketing_profile_id' => $otherProfile->id, 'source_type' => 'square_payment', 'source_id' => $paymentId]);
    expect(app(CustomerCommerceSnapshotService::class)->forProfile($otherProfile, $other->id)['confirmed_purchase_count'])->toBe(1);
    Http::assertNothingSent();
});

it('waits for original Pay recovery even when Square identifies a customer on a completed provider record first', function (string $sourceType, string $state): void {
    $fixture = payMirrorAttributionFixture($sourceType, true, $state, true);
    extract($fixture);
    $commerce = app(CustomerCommerceSnapshotService::class);
    MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'pos_sale', 'source_id' => $store->runtime().':'.$saleId]);
    expect($commerce->forProfile($profile, $tenant->id)['confirmed_purchase_count'])->toBe(0)
        ->and($store->rows('sales', $tenant->id)->where('id', $saleId)->value('state'))->toBe($state);
    // Only the original Pay reconciliation may confirm its financial state; a read never does so.
    $store->rows('sales', $tenant->id)->where('id', $saleId)->update(['state' => 'succeeded']);
    DB::table('payment_core_operations')->where('id', $operationId)->update(['state' => 'succeeded', 'provider_id' => $paymentId]);
    $this->travel(6)->seconds();
    $after = $commerce->forProfile($profile, $tenant->id);
    expect($after['confirmed_purchase_count'])->toBe(1)->and($after['purchases'])->toHaveCount(1)
        ->and($after['purchases'][0]['source'])->toBe('pos_sale');
    Http::assertNothingSent();
})->with([['square_order', 'processing'], ['square_order', 'unknown'], ['square_payment', 'processing'], ['square_payment', 'unknown']]);

it('does not let an imported provider record override a different explicitly associated Pay customer', function (): void {
    $fixture = payMirrorAttributionFixture('square_order');
    extract($fixture);
    $explicit = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Staff confirmed customer']);
    app(StockLedger::class)->rows('receipt_associations', $tenant->id)->insert(['tenant_id' => $tenant->id, 'runtime_id' => $store->runtime(), 'sale_id' => $saleId, 'actor_id' => $actor->id,
        'customer_profile_id' => $explicit->id, 'contact' => $store->encrypt([]), 'reward_context' => $store->encrypt(['enabled' => false]),
        'request_hash' => hash('sha256', 'fictional-explicit-'.$saleId), 'input_hash' => hash('sha256', 'fictional-explicit-'.$saleId), 'created_at' => now(), 'updated_at' => now()]);
    expect(app(CustomerCommerceSnapshotService::class)->forProfile($profile, $tenant->id)['confirmed_purchase_count'])->toBe(0)
        ->and(app(StockLedger::class)->rows('receipt_associations', $tenant->id)->where('sale_id', $saleId)->value('customer_profile_id'))->toBe($explicit->id)
        ->and(MarketingProfileLink::forTenantId($tenant->id)->where('source_type', 'square_order')->where('source_id', $orderId)->value('marketing_profile_id'))->toBe($profile->id);
    Http::assertNothingSent();
});

it('requires original confirmed payment evidence as well as a succeeded Pay row and an explicit customer', function (): void {
    $fixture = payMirrorAttributionFixture('square_order', true, 'succeeded', true);
    extract($fixture);
    $journal = Mockery::mock(PaymentJournal::class);
    $journal->shouldReceive('get')->with($tenant->id, $operationId)->andReturn([...$operation, 'state' => 'unknown']);
    app()->instance(PaymentJournal::class, $journal);
    $read = app(CustomerCommerceSnapshotService::class)->forProfile($profile, $tenant->id);
    expect($read['confirmed_purchase_count'])->toBe(0)->and($read['issues'])->toContain('customer_identity_review_required')
        ->and($store->rows('sales', $tenant->id)->where('id', $saleId)->value('state'))->toBe('succeeded');
    Http::assertNothingSent();
});

it('cannot exclude another payment or tenant through the server-internal receipt history reader', function (): void {
    $fixture = payMirrorAttributionFixture('square_order');
    extract($fixture);
    $sale = $store->rows('sales', $tenant->id)->where('id', $saleId)->firstOrFail();
    $commerce = app(CustomerCommerceSnapshotService::class);
    expect(fn () => $commerce->forReceiptAttributionHistory($profile, $tenant->id, $sale, [...$operation, 'provider_id' => 'unrelated-provider-payment']))
        ->toThrow(\App\Services\Pos\PosException::class, 'paid_evidence_required');
    $other = Tenant::create(['name' => 'Other receipt owner', 'slug' => 'other-receipt-'.Str::uuid()]);
    $otherProfile = MarketingProfile::create(['tenant_id' => $other->id, 'first_name' => 'Different customer']);
    expect(fn () => $commerce->forReceiptAttributionHistory($otherProfile, $other->id, $sale, $operation))
        ->toThrow(\App\Services\Pos\PosException::class, 'paid_evidence_required');
});
