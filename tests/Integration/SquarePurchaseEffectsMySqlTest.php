<?php

use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CustomerMergeService;
use App\Services\Marketing\SquarePurchaseEffects;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PosProcessRace;

uses(Tests\TestCase::class);
require_once __DIR__.'/../Support/SquarePurchaseFixtures.php';

beforeEach(function () {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Explicit disposable MySQL only.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Unsafe database.');
    }
    squarePurchaseFixture($this);
});

test('concurrent original purchase retries award once under the shared tenant fence', function () {
    $tenant = $this->tenant->id;
    $results = PosProcessRace::run(
        fn () => app(SquarePurchaseEffects::class)->reconcile($tenant, 'original-order')['status'],
        fn () => app(SquarePurchaseEffects::class)->reconcile($tenant, 'original-order')['status'],
        fn ($query) => str_contains($query->sql, 'customer_account_fences') && str_contains($query->sql, 'for update')
    );
    expect($results)->toBe(['processed', 'processed'])
        ->and(CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'square_purchase')->count())->toBe(1)
        ->and(app(CandleCashService::class)->currentBalance($this->profile))->toBe(1.8);
});

test('a merge waiting behind purchase earning retains the receipt and ledger on the canonical survivor', function () {
    $tenant = $this->tenant->id;
    $survivor = MarketingProfile::create(['tenant_id' => $tenant, 'first_name' => 'Reviewed survivor']);
    $operation = app(CustomerMergeService::class)->createOperation($tenant, [$this->profile->id, $survivor->id], $survivor->id, 'core', (string) Str::uuid());
    PosProcessRace::run(
        fn () => app(SquarePurchaseEffects::class)->reconcile($tenant, 'original-order')['status'],
        fn () => app(CustomerMergeService::class)->apply($operation)->status,
        fn ($query) => str_contains($query->sql, 'customer_account_fences') && str_contains($query->sql, 'for update')
    );
    app(SquarePurchaseEffects::class)->reconcile($tenant, 'original-order');
    expect(MarketingProfileLink::forTenantId($tenant)->where('source_type', 'square_order')->sole()->marketing_profile_id)->toBe($survivor->id)
        ->and(CandleCashTransaction::where('marketing_profile_id', $survivor->id)->where('source', 'square_purchase')->count())->toBe(1)
        ->and(app(CandleCashService::class)->currentBalance($survivor))->toBe(1.8);
});

test('purchase processing waiting behind a merge resolves current links and earns only on the survivor', function () {
    $tenant = $this->tenant->id;
    $survivor = MarketingProfile::create(['tenant_id' => $tenant, 'first_name' => 'Reviewed survivor']);
    $operation = app(CustomerMergeService::class)->createOperation($tenant, [$this->profile->id, $survivor->id], $survivor->id, 'core', (string) Str::uuid());
    PosProcessRace::run(
        fn () => app(CustomerMergeService::class)->apply($operation)->status,
        fn () => app(SquarePurchaseEffects::class)->reconcile($tenant, 'original-order')['status'],
        fn ($query) => str_contains($query->sql, 'customer_account_fences') && str_contains($query->sql, 'for update')
    );
    app(SquarePurchaseEffects::class)->reconcile($tenant, 'original-order');
    expect(MarketingProfileLink::forTenantId($tenant)->where('source_type', 'square_order')->sole()->marketing_profile_id)->toBe($survivor->id)
        ->and(CandleCashTransaction::where('marketing_profile_id', $survivor->id)->where('source', 'square_purchase')->count())->toBe(1)
        ->and(app(CandleCashService::class)->currentBalance($survivor))->toBe(1.8);
});
