<?php

use App\Http\Controllers\SquareCustomerConnectionController;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Marketing\CandleCashRedemptionReconciliationService;
use App\Services\Marketing\MarketingConversionAttributionService;
use App\Services\Marketing\MarketingEventAttributionService;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Marketing\SquareCustomerIdentityService;
use App\Services\Marketing\SquareMarketingSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['marketing.square.enabled' => false, 'marketing.square.access_token' => 'foreign-global-token', 'pos_live.enabled' => false, 'pos_live.runtime_id' => null]);
    $this->tenant = Tenant::create(['name' => 'Fictional source shop', 'slug' => 'square-refresh-'.Str::lower(Str::random(8))]);
    $this->owner = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'membership_active' => true]);
    $this->setting = TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => 'square_config', 'value' => [
        'connection_id' => 'square-source:fixture', 'generation' => 1, 'merchant_id' => 'ORIGINALMERCHANT', 'environment' => 'sandbox', 'location_ids' => ['ORIGINALLOCATION'],
        'credentials' => app(\App\Services\Pos\PosStore::class)->encrypt(['access_token' => 'fictional-local-reader', 'expires_at' => now()->addDay()->toIso8601String()]), 'status' => 'active']]);
    $this->sync = app(SquareMarketingSyncService::class);
    $this->sourceOptions = ['tenant_id' => $this->tenant->id, 'created_by' => $this->owner->id, 'manual_refresh' => true, 'historical_source_only' => true,
        'connection_id' => 'square-source:fixture', 'expected_connection_generation' => '1', 'request_id' => (string) Str::uuid(), 'limit' => 100];
});

function squareRefreshFake(array $responses = [], ?Closure $afterRead = null): void
{
    Http::fake(function ($request) use ($responses, $afterRead) {
        expect($request->hasHeader('Authorization', 'Bearer fictional-local-reader'))->toBeTrue();
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v2/merchants/ORIGINALMERCHANT') {
            return Http::response(['merchant' => ['id' => 'ORIGINALMERCHANT']]);
        }
        if ($afterRead) {
            $afterRead($request);
        }

        return Http::response($responses[$path] ?? throw new RuntimeException('Unexpected source read '.$path));
    });
}

function squareRefreshFailure(Closure $call, int $code): void
{
    try {
        $call();
        test()->fail('The source action must fail closed.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe($code);
    }
}

function squareRefreshRequest($test, array $input = [], string $method = 'POST'): Request
{
    $request = Request::create('/workspace/customers/square', $method, $input);
    $request->attributes->set('current_tenant', $test->tenant);
    $request->setUserResolver(fn () => $test->owner);
    $request->headers->set('Accept', 'application/json');

    return $request;
}

test('Core owner global member can explicitly create unmatched source profiles with default gates closed and replay the original run', function () {
    squareRefreshFake(['/v2/customers' => ['customers' => [['id' => 'SOURCEONE', 'given_name' => 'Original', 'email_address' => 'source@example.invalid', 'preferences' => ['email_unsubscribed' => false]]]]]);
    $options = $this->sourceOptions + ['create_unmatched' => true];
    $first = $this->sync->syncCustomers($options);
    $second = $this->sync->syncCustomers($options);
    expect($first['status'])->toBe('completed')->and($first['summary']['profiles_created'])->toBe(1)
        ->and($second['run_id'])->toBe($first['run_id'])->and($second['replayed'])->toBeTrue()
        ->and(MarketingProfile::count())->toBe(1)->and(MarketingProfile::sole()->accepts_email_marketing)->toBeFalse()
        ->and(MarketingProfile::sole()->accepts_sms_marketing)->toBeFalse()->and(MarketingConsentEvent::count())->toBe(0)
        ->and(MarketingProfileLink::sole()->source_id)->toBe('SOURCEONE')->and(MarketingImportRun::count())->toBe(1);
    Http::assertSentCount(3); // Merchant is re-proved; the source page is not fetched twice.
    squareRefreshFailure(fn () => $this->sync->syncCustomers(array_replace($options, ['create_unmatched' => false])), 409);
    squareRefreshFailure(fn () => $this->sync->syncOrders($options), 409);
});

test('owner bulk import stages exact current tenant candidates and never silently matches foreign contacts', function () {
    $existing = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'email' => 'collision@example.invalid', 'normalized_email' => 'collision@example.invalid']);
    $foreign = Tenant::create(['name' => 'Other source shop', 'slug' => 'foreign-source-'.Str::random(8)]);
    MarketingProfile::create(['tenant_id' => $foreign->id, 'email' => 'separate@example.invalid', 'normalized_email' => 'separate@example.invalid']);
    squareRefreshFake(['/v2/customers' => ['customers' => [['id' => 'COLLISION', 'email_address' => 'collision@example.invalid'], ['id' => 'SEPARATE', 'email_address' => 'separate@example.invalid']]]]);
    $result = $this->sync->syncCustomers($this->sourceOptions + ['create_unmatched' => true]);
    expect($result['summary']['reviews_created'])->toBe(1)->and($result['summary']['profiles_created'])->toBe(1)
        ->and(MarketingIdentityReview::sole()->payload['candidate_profile_ids'])->toBe([$existing->id])
        ->and(MarketingProfileLink::sole()->source_id)->toBe('SEPARATE');
});

test('preview records an audit without source profile review consent or card writes', function () {
    squareRefreshFake(['/v2/customers' => ['customers' => [['id' => 'PREVIEW', 'preferences' => ['email_unsubscribed' => true]]]]]);
    $result = $this->sync->syncCustomers($this->sourceOptions + ['dry_run' => true, 'create_unmatched' => true]);
    expect($result['summary']['processed'])->toBe(1)->and(SquareCustomer::count())->toBe(0)->and(MarketingProfile::count())->toBe(0)
        ->and(MarketingIdentityReview::count())->toBe(0)->and(MarketingConsentEvent::count())->toBe(0)->and(CustomerExternalProfile::count())->toBe(0)->and(MarketingImportRun::count())->toBe(1);
});

test('late connection change or membership removal rejects source writes', function (string $change) {
    squareRefreshFake(['/v2/customers' => ['customers' => [['id' => 'LATE']]]], function () use ($change) {
        if ($change === 'generation') {
            $this->setting->update(['value' => array_replace($this->setting->value, ['generation' => 2])]);
        } else {
            $this->tenant->users()->updateExistingPivot($this->owner, ['membership_active' => false]);
        }
    });
    squareRefreshFailure(fn () => $this->sync->syncCustomers($this->sourceOptions), $change === 'generation' ? 409 : 403);
    expect(SquareCustomer::count())->toBe(0)->and(MarketingProfileLink::count())->toBe(0)->and(MarketingImportRun::sole()->status)->toBe('failed');
})->with(['generation', 'membership']);

test('resume retains original query and complete page checkpoint without skipping excess page rows', function () {
    squareRefreshFake(['/v2/customers/search' => ['customers' => [['id' => 'FIRST'], ['id' => 'SECOND']], 'cursor' => 'opaque-next']]);
    $options = array_replace($this->sourceOptions, ['limit' => 1, 'since' => '2026-01-01T00:00:00Z']);
    $first = $this->sync->syncCustomers($options);
    expect($first['status'])->toBe('partial')->and(SquareCustomer::count())->toBe(2)->and(MarketingImportRun::sole()->summary['checkpoint']['cursor'])->toBe('opaque-next');
    Http::swap(new \Illuminate\Http\Client\Factory);
    squareRefreshFake(['/v2/customers/search' => ['customers' => [['id' => 'THIRD']]]], function ($request) {
        expect($request['cursor'])->toBe('opaque-next')->and(data_get($request->data(), 'query.filter.updated_at.start_at'))->toBe('2026-01-01T00:00:00+00:00');
    });
    $next = $this->sync->syncCustomers(array_replace($options, ['resume_run_id' => $first['run_id'], 'request_id' => (string) Str::uuid(), 'since' => '2026-10-01']));
    expect($next['status'])->toBe('completed')->and(SquareCustomer::count())->toBe(3);
});

test('manual history import preserves amounts and never invokes reward redemption event or conversion attribution', function () {
    $this->mock(CandleCashRedemptionReconciliationService::class)->shouldNotReceive('reconcileSquareOrder');
    $this->mock(MarketingEventAttributionService::class)->shouldNotReceive('refreshForSquareOrder');
    $this->mock(MarketingConversionAttributionService::class)->shouldNotReceive('attributeForSquareOrder');
    $sync = app(SquareMarketingSyncService::class);
    squareRefreshFake(['/v2/orders/search' => ['orders' => [['id' => 'ORIGINALORDER', 'customer_id' => 'UNLINKED', 'location_id' => 'ORIGINALLOCATION', 'state' => 'COMPLETED',
        'total_money' => ['amount' => 12345, 'currency' => 'USD'], 'card_details' => ['card' => ['last_4' => '4242', 'bin' => 'strip', 'cardholder_name' => 'strip']]]]]]);
    $result = $sync->syncOrders($this->sourceOptions);
    expect($result['status'])->toBe('completed')->and(SquareOrder::sole()->total_money_amount)->toBe(12345)
        ->and(data_get(SquareOrder::sole()->raw_payload, 'card_details.card'))->toBe(['last_4' => '4242'])
        ->and(MarketingProfile::count())->toBe(0)->and(MarketingConsentEvent::count())->toBe(0);
});

test('cards retain merchant scoped references with no payment authority or protected card fields', function () {
    squareRefreshFake(['/v2/cards' => ['cards' => [['id' => 'OPAQUECARD', 'customer_id' => 'ORIGINALCUSTOMER', 'merchant_id' => 'ORIGINALMERCHANT', 'card_brand' => 'VISA', 'last_4' => '4242', 'fingerprint' => 'same-provider-fingerprint', 'bin' => 'strip', 'exp_year' => 2030],
        ['id' => 'FOREIGNCARD', 'customer_id' => 'OTHER', 'merchant_id' => 'FOREIGNMERCHANT']]]]);
    $result = $this->sync->syncCards($this->sourceOptions);
    expect($result['status'])->toBe('completed')->and(CustomerExternalProfile::count())->toBe(1)
        ->and(CustomerExternalProfile::sole()->marketing_profile_id)->toBeNull()
        ->and(data_get(CustomerExternalProfile::sole()->raw_metafields, 'square.cards.0'))->toHaveKeys(['id', 'last_4', 'fingerprint'])->not->toHaveKeys(['bin', 'exp_year'])
        ->and(MarketingProfile::count())->toBe(0)->and(MarketingProfileLink::count())->toBe(0);
});

test('refresh controller requires fresh owner authorization immutable generation and valid explicit bulk choice', function () {
    $controller = app(SquareCustomerConnectionController::class);
    $request = squareRefreshRequest($this, ['request_id' => (string) Str::uuid(), 'connection_id' => 'square-source:fixture', 'expected_connection_generation' => '1', 'sync_type' => 'customers', 'mode' => 'import', 'limit' => 1]);
    squareRefreshFake(['/v2/customers' => ['customers' => [['id' => 'CONTROLLER']]]]);
    $response = $controller->refresh($request, $this->sync, app(SquareCustomerIdentityService::class));
    expect($response->getStatusCode())->toBe(200)->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->tenant->users()->updateExistingPivot($this->owner, ['role' => 'member']);
    squareRefreshFailure(fn () => $controller->refresh($request, $this->sync, app(SquareCustomerIdentityService::class)), 403);
});

test('Core connection HTML displays bounded source coverage and review controls with escaped provider fields', function () {
    $this->actingAs($this->owner);
    app('request')->setLaravelSession(app('session.store'));
    view()->share('errors', new \Illuminate\Support\ViewErrorBag);
    SquareCustomer::create(['tenant_id' => $this->tenant->id, 'square_customer_id' => 'ESCAPED', 'raw_payload' => ['id' => 'ESCAPED']]);
    app(SquareCustomerIdentityService::class)->sync(['first_name' => '<script>alert(1)</script>', 'primary_source' => ['source_type' => 'square_customer', 'source_id' => 'ESCAPED']], $this->tenant->id, false);
    $response = app(SquareCustomerConnectionController::class)->index(squareRefreshRequest($this, [], 'GET'), app(SquareConnectionContext::class), app(SquareCustomerIdentityService::class));
    $html = $response->getContent();
    expect($html)->toContain('Square customers and source refresh', 'Cached coverage is separate', 'create_unmatched', 'expected_connection_generation', '&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('fictional-local-reader', 'foreign-global-token', '<script>alert(1)</script>');
    Http::assertNothingSent();
});

test('original order namespace collision fails before financial source facts are overwritten', function () {
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'COLLIDING', 'total_money_amount' => 111,
        'raw_payload' => ['_source_binding' => ['merchant_id' => 'OTHERORIGINAL', 'environment' => 'production']]]);
    squareRefreshFake(['/v2/orders/search' => ['orders' => [['id' => 'COLLIDING', 'total_money' => ['amount' => 9999, 'currency' => 'USD']]]]]);
    squareRefreshFailure(fn () => $this->sync->syncOrders($this->sourceOptions), 409);
    expect(SquareOrder::sole()->total_money_amount)->toBe(111)->and(MarketingProfileLink::count())->toBe(0);
});

test('stale generation tenant foreign merchant and missing local connection never read customers through a global token', function () {
    squareRefreshFake();
    squareRefreshFailure(fn () => $this->sync->syncCustomers(array_replace($this->sourceOptions, ['expected_connection_generation' => '0'])), 409);
    Http::assertSentCount(1);
    $this->setting->delete();
    $result = $this->sync->syncCustomers(array_replace($this->sourceOptions, ['connection_id' => 'tenant-config']));
    expect($result['status'])->toBe('blocked')->and($result['reason'])->toBe('tenant_square_config_missing')->and(SquareCustomer::count())->toBe(0);
    Http::assertSentCount(1);
});

test('hourly cache never uses a foreign eager customer relation to propose contact matches', function () {
    $foreign = Tenant::create(['name' => 'Foreign cached shop', 'slug' => 'foreign-cache-'.Str::random(8)]);
    SquareCustomer::create(['tenant_id' => $foreign->id, 'square_customer_id' => 'SHAREDID', 'email' => 'foreign@example.invalid']);
    SquareCustomer::create(['tenant_id' => $this->tenant->id, 'square_customer_id' => 'SHAREDID', 'email' => 'own@example.invalid']);
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'OWNORDER', 'square_customer_id' => 'SHAREDID']);
    $this->artisan('marketing:sync-profiles --source=square')->assertExitCode(0);
    $review = MarketingIdentityReview::where('source_type', 'square_order')->sole();
    expect($review->raw_email)->toBe('own@example.invalid')->and(data_get($review->payload, 'source_context.tenant_id'))->toBe($this->tenant->id);
    Http::assertNothingSent();
});

test('failed source page retries its original first page and never skips a failed item', function () {
    $fail = true;
    $this->partialMock(\App\Services\Marketing\SquareCustomerSource::class, function ($mock) use (&$fail) {
        $mock->shouldReceive('customer')->andReturnUsing(function ($payload) use (&$fail) {
            if ($fail) {
                $fail = false;
                throw new RuntimeException('Fictional transient source persistence failure');
            }

            return $payload;
        });
    });
    squareRefreshFake(['/v2/customers' => ['customers' => [['id' => 'RETRYFIRST'], ['id' => 'RETRYSECOND']], 'cursor' => 'next-page']]);
    expect(fn () => $this->sync->syncCustomers($this->sourceOptions))->toThrow(RuntimeException::class);
    $original = MarketingImportRun::sole();
    expect($original->summary['retry_page'])->toBeTrue()->and($original->summary['checkpoint']['cursor'])->toBeNull()->and(SquareCustomer::count())->toBe(0);
    $result = $this->sync->syncCustomers(array_replace($this->sourceOptions, ['request_id' => (string) Str::uuid(), 'resume_run_id' => $original->id, 'limit' => 1]));
    expect($result['status'])->toBe('partial')->and(SquareCustomer::count())->toBe(2);
});

test('historical import marker survives later scheduled refresh and independent redemption reconciliation skips it', function () {
    squareRefreshFake(['/v2/orders/search' => ['orders' => [['id' => 'HISTORICAL', 'source' => ['name' => 'CANDLE-FAKE-REWARD'], 'total_money' => ['amount' => 123, 'currency' => 'USD']]]]]);
    $this->sync->syncOrders($this->sourceOptions);
    expect(data_get(SquareOrder::sole()->raw_payload, '_everbranch_source_import.historical_only'))->toBeTrue();
    $staleModel = SquareOrder::sole();
    $staleModel->raw_payload = ['source' => ['name' => 'CANDLE-FAKE-REWARD']];
    config(['marketing.square.enabled' => true, 'marketing.square.sync_orders_enabled' => true]);
    $this->sync->syncOrders(['tenant_id' => $this->tenant->id]);
    $summary = app(CandleCashRedemptionReconciliationService::class)->reconcileSquareOrder($staleModel, ['tenant_id' => $this->tenant->id, 'codes' => ['CANDLE-FAKE-REWARD']]);
    expect($summary['historical_source_skipped'])->toBe(1)->and($summary['reconciled'])->toBe(0)->and(data_get(SquareOrder::sole()->raw_payload, '_everbranch_source_import.historical_only'))->toBeTrue();
    $partial = SquareOrder::query()->first(['id', 'square_order_id', 'raw_payload']);
    $partial->raw_payload = ['source' => ['name' => 'CANDLE-FAKE-REWARD']];
    expect(app(CandleCashRedemptionReconciliationService::class)->reconcileSquareOrder($partial, ['tenant_id' => $this->tenant->id])['historical_source_skipped'])->toBe(1);
});

test('Square source mutations respect isolated Soccer workspace and a late Customers capability removal', function (string $change) {
    if ($change === 'soccer') {
        \App\Models\TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'metadata' => ['workspace_focus' => 'soccer']]);
    } else {
        squareRefreshFake(['/v2/customers' => ['customers' => [['id' => 'REVOKEDCAPABILITY']]]], function () {
            \App\Models\TenantModuleState::create(['tenant_id' => $this->tenant->id, 'module_key' => 'customers', 'enabled_override' => false]);
        });
    }
    squareRefreshFailure(fn () => $this->sync->syncCustomers($this->sourceOptions), 403);
    expect(SquareCustomer::count())->toBe(0)->and(MarketingProfile::count())->toBe(0);
    if ($change === 'soccer') {
        Http::assertNothingSent();
    }
})->with(['soccer', 'late_capability']);

test('merchant scoped provider card fingerprint collisions stage bulk customer review without wallet linking', function () {
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_customer', 'source_id' => 'PREVIOUSCUSTOMER']);
    $binding = ['merchant_id' => 'ORIGINALMERCHANT', 'environment' => 'sandbox'];
    foreach (['PREVIOUSCUSTOMER', 'NEWCUSTOMER'] as $id) {
        CustomerExternalProfile::create(['tenant_id' => $this->tenant->id, 'provider' => 'square', 'integration' => 'square_customer', 'store_key' => 'same-original-namespace', 'external_customer_id' => $id,
            'raw_metafields' => ['square' => ['source_binding' => $binding, 'cards' => [['id' => 'provider-card-'.$id, 'customer_id' => $id, 'fingerprint' => 'shared-provider-fingerprint', 'card_brand' => 'VISA', 'last_4' => '4242']]]]]);
    }
    squareRefreshFake(['/v2/customers' => ['customers' => [['id' => 'NEWCUSTOMER']]]]);
    $result = $this->sync->syncCustomers($this->sourceOptions + ['create_unmatched' => true]);
    expect($result['summary']['reviews_created'])->toBe(1)->and($result['summary']['profiles_created'])->toBe(0)
        ->and(MarketingIdentityReview::sole()->payload['candidate_profile_ids'])->toBe([$profile->id])->and(MarketingProfile::count())->toBe(1)
        ->and(MarketingProfileLink::count())->toBe(1);
});

test('missing provider read scope records a safe failure without leaking provider payload or credentials', function () {
    Http::fake(['https://connect.squareupsandbox.com/v2/merchants/ORIGINALMERCHANT' => Http::response(['merchant' => ['id' => 'ORIGINALMERCHANT']]),
        'https://connect.squareupsandbox.com/v2/customers*' => Http::response(['errors' => [['code' => 'INSUFFICIENT_SCOPES', 'detail' => 'private-provider-message@example.invalid']]], 403)]);
    $request = squareRefreshRequest($this, ['request_id' => (string) Str::uuid(), 'connection_id' => 'square-source:fixture', 'expected_connection_generation' => '1', 'sync_type' => 'customers', 'mode' => 'preview', 'limit' => 1]);
    $response = app(SquareCustomerConnectionController::class)->refresh($request, $this->sync, app(SquareCustomerIdentityService::class));
    expect($response->getStatusCode())->toBe(409)->and($response->getContent())->toContain('CUSTOMERS_READ')->not->toContain('private-provider-message', 'fictional-local-reader')
        ->and(MarketingImportRun::sole()->status)->toBe('failed')->and(MarketingImportRun::sole()->summary['retry_page'])->toBeTrue()->and(SquareCustomer::count())->toBe(0);
});

test('zero recorded money remains a known original amount rather than an invented missing value', function () {
    squareRefreshFake(['/v2/orders/search' => ['orders' => [['id' => 'ZEROORDER', 'state' => 'COMPLETED', 'total_money' => ['amount' => 0, 'currency' => 'USD']]]],
        '/v2/payments' => ['payments' => [['id' => 'ZEROPAYMENT', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 0, 'currency' => 'USD']]]]]);
    $this->sync->syncOrders($this->sourceOptions);
    $this->sync->syncPayments(array_replace($this->sourceOptions, ['request_id' => (string) Str::uuid()]));
    expect(SquareOrder::sole()->total_money_amount)->toBe(0)->and(\App\Models\SquarePayment::sole()->amount_money)->toBe(0)->and(MarketingProfile::count())->toBe(0);
});

test('Core source OAuth does not enroll in old import schedules and owner import cannot downgrade source purpose', function () {
    $this->setting->update(['value' => $this->setting->value + ['credential_flow' => 'core_source_authorization_code', 'credential_issued_at' => now()->toIso8601String(), 'expires_at' => now()->addDay()->toIso8601String()]]);
    config(['marketing.square.enabled' => true, 'marketing.square.sync_customers_enabled' => true, 'marketing.square.sync_orders_enabled' => true, 'marketing.square.sync_payments_enabled' => true]);
    foreach (['syncCustomers', 'syncOrders', 'syncPayments'] as $method) {
        expect($this->sync->$method(['tenant_id' => $this->tenant->id])['reason'])->toBe('core_source_requires_explicit_owner_refresh');
    }
    Http::assertNothingSent();
    $this->mock(CandleCashRedemptionReconciliationService::class)->shouldNotReceive('reconcileSquareOrder');
    squareRefreshFake(['/v2/orders/search' => ['orders' => [['id' => 'COREHISTORY', 'total_money' => ['amount' => 1000, 'currency' => 'USD']]]]]);
    $result = app(SquareMarketingSyncService::class)->syncOrders(array_replace($this->sourceOptions, ['historical_source_only' => false]));
    expect($result['summary']['historical_source_only'])->toBeTrue()->and($result['summary']['source_binding']['source_purpose'])->toBe('customer_history')
        ->and(data_get(SquareOrder::sole()->raw_payload, '_everbranch_source_import.historical_only'))->toBeTrue();
});
