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

test('Core merge HTTP review survives native MySQL JSON storage and preserves rewards on replay', function () {
    $donor = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Reviewed duplicate']);
    app(CandleCashService::class)->addRewardAmountIdempotent($donor, 5, 'gift', 'reviewed-gift');
    $base = '/workspace/customers/merges';
    $preview = $this->actingAs($this->owner)->postJson($base.'/preview?tenant='.$this->tenant->slug, [
        'profile_ids' => [$this->profile->id, $donor->id], 'survivor_profile_id' => $this->profile->id,
        'request_id' => (string) Str::uuid(),
    ])->assertOk()->json();
    $url = $base.'/'.$preview['operation_id'].'/apply?tenant='.$this->tenant->slug;
    $approval = ['expected_version' => $preview['version'], 'identity_confirmed' => true];
    $this->postJson($url, $approval)->assertOk()->assertJsonPath('status', 'completed');
    $this->postJson($url, $approval)->assertOk()->assertJsonPath('profile_id', $this->profile->id);
    expect($donor->fresh()->merged_into_profile_id)->toBe($this->profile->id)
        ->and(app(CandleCashService::class)->currentBalance($this->profile))->toBe(5.0)
        ->and(CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'gift')->count())->toBe(1);
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

test('merge approval reads current wallet state after waiting for an earning writer', function () {
    $tenant = $this->tenant->id;
    $other = MarketingProfile::create(['tenant_id' => $tenant, 'first_name' => 'Reviewed duplicate']);
    $operation = app(CustomerMergeService::class)->createOperation($tenant, [$this->profile->id, $other->id], $this->profile->id, 'core', (string) Str::uuid(), [], ['source' => 'core_customers']);
    $profile = $this->profile;
    $results = PosProcessRace::run(
        fn () => app(\App\Services\Marketing\CustomerAccountFence::class)->forTenant($tenant,
            fn () => app(CandleCashService::class)->addRewardAmountIdempotent($profile, 5, 'gift', 'concurrent-preview-gift')['balance']),
        function () use ($tenant, $operation) {
            try {
                app(\App\Services\Marketing\CustomerAccountFence::class)->forTenant($tenant,
                    fn () => app(CustomerMergeService::class)->assertCurrentPreview($operation));

                return 'accepted';
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                if ($error->getStatusCode() !== 409) {
                    throw $error;
                }

                return 'review';
            }
        },
        fn ($query) => str_contains($query->sql, 'customer_account_fences') && str_contains($query->sql, 'for update')
    );
    expect($results[1])->toBe('review')->and(app(CandleCashService::class)->currentBalance($profile))->toBe(5.0);
});
