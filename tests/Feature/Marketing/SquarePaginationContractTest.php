<?php

use App\Models\CandleCashTransaction;
use App\Models\MarketingImportRun;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\SquareCustomer;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Marketing\SquareMarketingSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['pos_live.enabled' => false, 'pos_live.runtime_id' => null, 'marketing.square.enabled' => false]);
    $this->tenant = Tenant::create(['name' => 'Fictional paging shop', 'slug' => 'paging-'.Str::random(10)]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'membership_active' => true]);
    TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => 'square_config', 'value' => [
        'connection_id' => 'paging-source', 'generation' => 1, 'merchant_id' => 'PAGINGMERCHANT', 'environment' => 'sandbox', 'location_ids' => ['PAGINGLOCATION'],
        'credentials' => app(\App\Services\Pos\PosStore::class)->encrypt(['access_token' => 'fictional-paging-reader']), 'status' => 'active']]);
    $this->sourceOptions = ['tenant_id' => $this->tenant->id, 'created_by' => $this->owner->id, 'manual_refresh' => true, 'historical_source_only' => true,
        'connection_id' => 'paging-source', 'expected_connection_generation' => '1', 'request_id' => (string) Str::uuid(), 'limit' => 500];
    $this->sync = app(SquareMarketingSyncService::class);
});

function squarePagingFake(Closure $page): void
{
    Http::fake(function ($request) use ($page) {
        expect($request->hasHeader('Authorization', 'Bearer fictional-paging-reader'))->toBeTrue();
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v2/merchants/PAGINGMERCHANT') {
            return Http::response(['merchant' => ['id' => 'PAGINGMERCHANT']]);
        }
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $page($path, $query + $request->data());
    });
}

function squarePagingRows(string $kind, int $start, int $count): array
{
    return array_map(fn ($i) => ['id' => strtoupper($kind).'-'.$i, 'location_id' => 'PAGINGLOCATION', 'state' => 'COMPLETED', 'status' => 'COMPLETED',
        'created_at' => '2026-01-01T12:00:00Z', 'closed_at' => '2026-01-01T12:00:00Z',
        'total_money' => ['amount' => 100, 'currency' => 'USD'], 'amount_money' => ['amount' => 100, 'currency' => 'USD']], range($start, $start + $count - 1));
}

function squarePagingLegacyRun($test, int $limit, string $cursor, ?int $parent = null, string $status = 'partial'): MarketingImportRun
{
    $binding = app(SquareConnectionContext::class)->publicBinding(app(SquareConnectionContext::class)->resolve($test->tenant->id, 'paging-source'));
    $summary = ['source_binding' => $binding, 'request_context' => [], 'create_unmatched' => false, 'historical_source_only' => true,
        'mode' => 'live-sync', 'limit' => $limit, 'since' => null, 'cursor' => $cursor,
        'checkpoint' => ['cursor' => $cursor, 'processed' => min(491, $limit)], 'processed' => min(491, $limit), 'errors' => 0,
        'request_id' => (string) Str::uuid(), 'retry_page' => $status === 'failed'];
    $summary['intent_hash'] = hash('sha256', json_encode(['square_customers_sync', $binding, $limit, null, $parent ? $cursor : null, false, false, true], JSON_THROW_ON_ERROR));
    if ($parent !== null) {
        $summary['resumed_from_run_id'] = $parent;
    }
    if ($status === 'failed') {
        $summary['provider_error'] = ['http_status' => 400, 'codes' => ['INVALID_VALUE']];
    }

    return MarketingImportRun::create(['tenant_id' => $test->tenant->id, 'created_by' => $test->owner->id, 'type' => 'square_customers_sync',
        'source_label' => 'square_customers', 'status' => $status, 'started_at' => now(), 'finished_at' => now(), 'summary' => $summary]);
}

test('a 491 row customer page boundary keeps its original 100 limit and resumes EOF without dropping or repeating rows', function (): void {
    $calls = [];
    squarePagingFake(function ($path, $data) use (&$calls) {
        expect($path)->toBe('/v2/customers');
        $calls[] = (int) $data['limit'];
        if ((int) $data['limit'] !== 100) {
            return Http::response(['errors' => [['category' => 'INVALID_REQUEST_ERROR', 'code' => 'INVALID_VALUE', 'field' => 'cursor']]], 400);
        }
        $page = isset($data['cursor']) ? (int) substr($data['cursor'], 5) : 0;
        if ($page === 5) {
            return Http::response(['customers' => []]);
        }

        return Http::response(['customers' => squarePagingRows('customers', $page * 100, $page === 4 ? 91 : 100), 'cursor' => 'page-'.($page + 1)]);
    });
    $first = $this->sync->syncCustomers($this->sourceOptions);
    expect($first['status'])->toBe('partial')->and($first['summary']['processed'])->toBe(491)->and($first['summary']['provider_page_limit'])->toBe(100)
        ->and(SquareCustomer::count())->toBe(491)->and($calls)->toBe([100, 100, 100, 100, 100]);
    $original = MarketingImportRun::findOrFail($first['run_id'])->getRawOriginal('summary');
    expect($this->sync->syncCustomers($this->sourceOptions)['replayed'])->toBeTrue()->and($calls)->toHaveCount(5);
    $last = $this->sync->syncCustomers(array_replace($this->sourceOptions, ['resume_run_id' => $first['run_id'], 'request_id' => (string) Str::uuid()]));
    expect($last['status'])->toBe('completed')->and($last['summary']['processed'])->toBe(0)->and($calls)->toHaveCount(6)
        ->and(SquareCustomer::count())->toBe(491)->and(SquareCustomer::distinct()->count('square_customer_id'))->toBe(491)
        ->and(MarketingImportRun::findOrFail($first['run_id'])->getRawOriginal('summary'))->toBe($original)
        ->and(MarketingProfile::count())->toBe(0)->and(CandleCashTransaction::count())->toBe(0)->and(MarketingMessageDelivery::count())->toBe(0);
});

test('customer order and payment cursor chains freeze the initial provider page size across larger resume budgets', function (string $kind, string $table): void {
    $calls = [];
    squarePagingFake(function ($path, $data) use ($kind, &$calls) {
        $calls[] = (int) $data['limit'];
        expect((int) $data['limit'])->toBe(5);

        return Http::response([$kind => squarePagingRows($kind, isset($data['cursor']) ? 4 : 0, isset($data['cursor']) ? 3 : 4)]
            + (isset($data['cursor']) ? [] : ['cursor' => 'same-five-query']));
    });
    $method = 'sync'.ucfirst($kind);
    $options = array_replace($this->sourceOptions, ['limit' => 5]);
    $first = $this->sync->$method($options);
    expect($first['status'])->toBe('partial')->and($first['summary']['processed'])->toBe(4)->and($calls)->toBe([5]);
    $next = $this->sync->$method(array_replace($options, ['limit' => 10, 'resume_run_id' => $first['run_id'], 'request_id' => (string) Str::uuid()]));
    expect($next['status'])->toBe('completed')->and($next['summary']['provider_page_limit'])->toBe(5)->and($calls)->toBe([5, 5])
        ->and(DB::table($table)->count())->toBe(7)->and(CandleCashTransaction::count())->toBe(0)->and(MarketingMessageDelivery::count())->toBe(0);
})->with([['customers', 'square_customers'], ['orders', 'square_orders'], ['payments', 'square_payments']]);

test('an undersized legacy resume budget rejects clearly and the original failed EOF run remains unchanged after a valid resume', function (): void {
    $calls = [];
    squarePagingFake(function ($path, $data) use (&$calls) {
        $calls[] = (int) $data['limit'];
        expect($data['cursor'])->toBe('legacy-final-100')->and((int) $data['limit'])->toBe(100);

        return Http::response(['customers' => []]);
    });
    $old = squarePagingLegacyRun($this, 500, 'legacy-final-100', null, 'failed');
    // Reflect finalSummary's advanced cursor and MySQL's reordered JSON maps;
    // the original intent hash still records the trusted initial request.
    $old->update(['summary' => array_replace($old->summary, ['source_binding' => array_reverse($old->summary['source_binding'], true)])]);
    $raw = $old->fresh()->getRawOriginal('summary');
    $options = array_replace($this->sourceOptions, ['resume_run_id' => $old->id, 'limit' => 99]);
    try {
        $this->sync->syncCustomers($options);
        $this->fail('An undersized resume must not return a zero-progress page.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409)->and($error->getMessage())->toContain('at least one original Square provider page');
    }
    expect($calls)->toBe([])->and(MarketingImportRun::count())->toBe(1);
    $next = $this->sync->syncCustomers(array_replace($options, ['limit' => 500, 'request_id' => (string) Str::uuid()]));
    expect($next['status'])->toBe('completed')->and($next['summary']['processed'])->toBe(0)->and($calls)->toBe([100])
        ->and($old->fresh()->status)->toBe('failed')->and($old->fresh()->summary['processed'])->toBe(491)
        ->and($old->fresh()->summary['provider_error']['http_status'])->toBe(400)->and($old->fresh()->getRawOriginal('summary'))->toBe($raw);
});

test('a legacy failed resume with a larger budget follows the original fifty row query rather than guessing one hundred', function (): void {
    $calls = [];
    squarePagingFake(function ($path, $data) use (&$calls) {
        $calls[] = (int) $data['limit'];
        expect((int) $data['limit'])->toBe(50);

        return Http::response(['customers' => []]);
    });
    $origin = squarePagingLegacyRun($this, 50, 'legacy-fifty');
    $failed = squarePagingLegacyRun($this, 500, 'legacy-fifty', $origin->id, 'failed');
    $failed->update(['summary' => array_replace($failed->summary, ['processed' => 0, 'checkpoint' => ['cursor' => 'legacy-fifty', 'processed' => 0]])]);
    $raw = $failed->fresh()->getRawOriginal('summary');
    $result = $this->sync->syncCustomers(array_replace($this->sourceOptions, ['resume_run_id' => $failed->id]));
    expect($result['status'])->toBe('completed')->and($result['summary']['provider_page_limit'])->toBe(50)->and($calls)->toBe([50])
        ->and($failed->fresh()->status)->toBe('failed')->and($failed->fresh()->getRawOriginal('summary'))->toBe($raw);
});

test('an unprovable or cyclic legacy cursor lineage fails clearly before a source read', function (string $problem): void {
    $calls = 0;
    squarePagingFake(function () use (&$calls) {
        $calls++;
        throw new RuntimeException('Unproved cursor parameters must not reach Square.');
    });
    $old = squarePagingLegacyRun($this, 50, 'unproved-cursor');
    $summary = $old->summary;
    if ($problem === 'missing-intent') {
        unset($summary['intent_hash']);
    } else {
        $summary['resumed_from_run_id'] = $old->id;
        $summary['intent_hash'] = hash('sha256', json_encode(['square_customers_sync', $summary['source_binding'], 50, null, 'unproved-cursor', false, false, true], JSON_THROW_ON_ERROR));
    }
    $old->update(['summary' => $summary]);
    try {
        $this->sync->syncCustomers(array_replace($this->sourceOptions, ['resume_run_id' => $old->id]));
        $this->fail('An unproved legacy cursor must require review.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409)->and($error->getMessage())->toContain('Review the original Square cursor page size');
    }
    expect($calls)->toBe(0)->and(MarketingImportRun::count())->toBe(1)->and(SquareCustomer::count())->toBe(0);
})->with(['missing-intent', 'cycle']);

test('legacy request replay keeps the original intent fingerprint and never starts another provider page', function (): void {
    squarePagingFake(fn () => throw new RuntimeException('A legacy replay must not fetch source data.'));
    $old = squarePagingLegacyRun($this, 500, 'not-used');
    $summary = $old->summary;
    $summary['request_id'] = $this->sourceOptions['request_id'];
    $summary['intent_hash'] = hash('sha256', json_encode(['square_customers_sync', $summary['source_binding'], 500, null, null, false, false, true], JSON_THROW_ON_ERROR));
    $old->update(['status' => 'completed', 'summary' => $summary]);
    $raw = $old->fresh()->getRawOriginal('summary');
    $result = $this->sync->syncCustomers($this->sourceOptions);
    expect($result['replayed'])->toBeTrue()->and($result['run_id'])->toBe($old->id)
        ->and($old->fresh()->getRawOriginal('summary'))->toBe($raw)->and(MarketingImportRun::count())->toBe(1);
});

test('cards retain their existing complete manual page behavior because their adapter has no page limit parameter', function (): void {
    squarePagingFake(fn ($path) => Http::response(['cards' => [
        ['id' => 'CARDONE', 'customer_id' => 'CUSTOMERONE', 'merchant_id' => 'PAGINGMERCHANT'],
        ['id' => 'CARDTWO', 'customer_id' => 'CUSTOMERONE', 'merchant_id' => 'PAGINGMERCHANT'],
    ]]));
    $result = $this->sync->syncCards(array_replace($this->sourceOptions, ['limit' => 1]));
    expect($result['status'])->toBe('completed')->and($result['summary']['processed'])->toBe(2)->and($result['summary'])->not->toHaveKey('provider_page_limit')
        ->and(MarketingProfile::count())->toBe(0)->and(CandleCashTransaction::count())->toBe(0);
});

test('legacy multi-location payment cursors without a recorded page size require review before any payment read', function (): void {
    $setting = TenantMarketingSetting::where('tenant_id', $this->tenant->id)->where('key', 'square_config')->firstOrFail();
    $setting->update(['value' => array_replace($setting->value, ['location_ids' => ['PAGINGLOCATION', 'SECONDLOCATION']])]);
    $calls = 0;
    squarePagingFake(function () use (&$calls) {
        $calls++;
        throw new RuntimeException('An ambiguous legacy location cursor must not be fetched.');
    });
    $old = squarePagingLegacyRun($this, 500, 'legacy-location-cursor');
    $summary = $old->summary;
    $summary['intent_hash'] = hash('sha256', json_encode(['square_payments_sync', $summary['source_binding'], 500, null, null, false, false, true], JSON_THROW_ON_ERROR));
    $old->update(['type' => 'square_payments_sync', 'summary' => $summary]);
    try {
        $this->sync->syncPayments(array_replace($this->sourceOptions, ['resume_run_id' => $old->id]));
        $this->fail('An unrecorded legacy location page size must require review.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409)->and($error->getMessage())->toContain('legacy multi-location');
    }
    expect($calls)->toBe(0)->and(MarketingImportRun::count())->toBe(1)->and(SquarePayment::count())->toBe(0);
});

test('recorded page sizes resume new multi-location payment chains without changing location or query parameters', function (): void {
    $setting = TenantMarketingSetting::where('tenant_id', $this->tenant->id)->where('key', 'square_config')->firstOrFail();
    $setting->update(['value' => array_replace($setting->value, ['location_ids' => ['PAGINGLOCATION', 'SECONDLOCATION']])]);
    $calls = [];
    squarePagingFake(function ($path, $data) use (&$calls) {
        expect($path)->toBe('/v2/payments')->and((int) $data['limit'])->toBe(5);
        $calls[] = [$data['location_id'], (int) $data['limit']];
        $first = $data['location_id'] === 'PAGINGLOCATION';
        $rows = squarePagingRows('payments', $first ? 0 : 4, $first ? 4 : 1);
        foreach ($rows as &$row) {
            $row['location_id'] = $data['location_id'];
        }
        unset($row);

        return Http::response(['payments' => $rows]);
    });
    $first = $this->sync->syncPayments(array_replace($this->sourceOptions, ['limit' => 5]));
    expect($first['status'])->toBe('partial')->and($first['summary']['processed'])->toBe(4)->and($first['summary']['provider_page_limit'])->toBe(5);
    $last = $this->sync->syncPayments(array_replace($this->sourceOptions, ['limit' => 10, 'resume_run_id' => $first['run_id'], 'request_id' => (string) Str::uuid()]));
    expect($last['status'])->toBe('completed')->and($last['summary']['provider_page_limit'])->toBe(5)
        ->and($calls)->toBe([['PAGINGLOCATION', 5], ['SECONDLOCATION', 5]])->and(SquarePayment::count())->toBe(5)
        ->and(CandleCashTransaction::count())->toBe(0);
});
