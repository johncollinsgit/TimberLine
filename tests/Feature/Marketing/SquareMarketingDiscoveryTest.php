<?php

use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Marketing\SquareClient;
use App\Services\Marketing\SquareCustomerReferenceReconciliation;
use App\Services\Marketing\SquareMarketingSyncService;
use App\Services\Marketing\SquareSourceRefresh;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['pos_live.enabled' => false, 'pos_live.runtime_id' => null, 'marketing.square.enabled' => false]);
    $this->tenant = Tenant::create(['name' => 'Fictional history owner', 'slug' => 'discovery-'.Str::lower(Str::random(8))]);
    $this->owner = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $this->tenant->users()->attach($this->owner->id, ['role' => 'owner', 'membership_active' => true]);
    TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => 'square_config', 'value' => [
        'connection_id' => 'discovery-reader', 'generation' => 1, 'merchant_id' => 'DISCOVERYMERCHANT', 'environment' => 'sandbox',
        'location_ids' => ['CURRENT'], 'status' => 'active', 'access_token' => 'fictional-discovery-key']]);
});

function discoveryFake(array $paths = [], ?Closure $inspect = null): void
{
    Http::fake(function ($request) use ($paths, $inspect) {
        $inspect?->__invoke($request);
        expect($request->hasHeader('Authorization', 'Bearer fictional-discovery-key'))->toBeTrue();
        $path = parse_url($request->url(), PHP_URL_PATH);
        $defaults = ['/v2/merchants/DISCOVERYMERCHANT' => ['merchant' => ['id' => 'DISCOVERYMERCHANT']],
            '/v2/locations' => ['locations' => [['id' => 'CURRENT', 'merchant_id' => 'DISCOVERYMERCHANT', 'status' => 'ACTIVE'], ['id' => 'OLD', 'merchant_id' => 'DISCOVERYMERCHANT', 'status' => 'INACTIVE']]]];

        return Http::response($paths[$path] ?? $defaults[$path] ?? throw new LogicException('Unexpected discovery path '.$path));
    });
}

function discoveryEnable($test): array
{
    $service = app(SquareSourceRefresh::class);

    return $service->configure($test->tenant->id, $test->owner, ['enabled' => true, 'expected_version' => $service->status($test->tenant->id)['version'],
        'connection_id' => 'discovery-reader', 'lookback_days' => 14, 'create_unmatched' => false, 'all_locations' => true]);
}

function discoveryOptions($test, array $policy, array $extra = []): array
{
    return $extra + ['tenant_id' => $test->tenant->id, 'manual_refresh' => true, 'created_by' => $test->owner->id,
        'request_id' => (string) Str::uuid(), 'connection_id' => 'discovery-reader', 'source_refresh_policy_version' => $policy['version']];
}

it('discovers inactive merchant locations and keeps history writes separate from native configuration and rewards', function (): void {
    discoveryFake(['/v2/orders/search' => ['orders' => [['id' => 'OLDORDER', 'location_id' => 'OLD', 'state' => 'COMPLETED',
        'closed_at' => '2021-11-01T12:00:00Z', 'taxes' => [['name' => 'Old town']], 'total_money' => ['amount' => 1200, 'currency' => 'USD']]]]], function ($request): void {
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/orders/search')) {
                expect($request['location_ids'])->toBe(['CURRENT', 'OLD']);
            }
        });
    $policy = discoveryEnable($this);
    expect($policy['binding']['location_ids'])->toBe(['CURRENT', 'OLD'])->and($policy['reader_location_ids'])->toBe(['CURRENT']);
    $result = app(SquareMarketingSyncService::class)->syncOrders(discoveryOptions($this, $policy));
    expect($result['status'])->toBe('completed')->and($result['summary']['historical_source_only'])->toBeTrue()
        ->and(SquareOrder::sole()->location_id)->toBe('OLD')->and(SquareOrder::sole()->raw_payload['_source_binding']['location_ids'])->toBe(['CURRENT', 'OLD'])
        ->and(TenantMarketingSetting::where('key', 'square_config')->sole()->value['location_ids'])->toBe(['CURRENT'])
        ->and(\App\Models\CandleCashTransaction::count())->toBe(0)->and(\App\Models\MarketingCampaign::forTenantId($this->tenant->id)->count())->toBe(0);
});

it('rejects foreign location discovery and unreviewed provider rows without writing source records', function (): void {
    discoveryFake(['/v2/locations' => ['locations' => [['id' => 'FOREIGN', 'merchant_id' => 'ANOTHERMERCHANT']]]]);
    expect(fn () => discoveryEnable($this))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(TenantMarketingSetting::where('key', SquareSourceRefresh::KEY)->count())->toBe(0);
    Http::swap(new \Illuminate\Http\Client\Factory);
    discoveryFake(['/v2/orders/search' => ['orders' => [['id' => 'WRONGORDER', 'location_id' => 'UNREVIEWED', 'state' => 'COMPLETED']]]]);
    $policy = discoveryEnable($this);
    expect(fn () => app(SquareMarketingSyncService::class)->syncOrders(discoveryOptions($this, $policy)))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SquareOrder::count())->toBe(0)->and(MarketingImportRun::where('type', 'square_orders_sync')->sole()->status)->toBe('failed');
});

it('keeps every order-location partition resumable and rejects changed query or page size', function (): void {
    $client = new SquareClient('fictional-discovery-key', 'https://connect.squareupsandbox.com');
    $calls = [];
    Http::fake(function ($r) use (&$calls) {
        $calls[] = $r['location_ids'];

        return Http::response(['orders' => [], 'cursor' => count($calls) === 1 ? 'first-partition-next' : null]);
    });
    $ids = array_map(fn ($i) => 'LOCATION'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 21));
    $since = \Carbon\CarbonImmutable::parse('2019-01-01T00:00:00Z');
    $one = $client->searchLocationOrders(null, 100, $since, array_reverse($ids));
    $two = $client->searchLocationOrders($one['cursor'], 100, $since, $ids);
    $three = $client->searchLocationOrders($two['cursor'], 100, $since, $ids);
    $four = $client->searchLocationOrders($three['cursor'], 100, $since, $ids);
    expect(array_map('count', $calls))->toBe([10, 10, 10, 1])->and($calls[0])->toBe($calls[1])->and($four['cursor'])->toBeNull();
    $before = count($calls);
    expect(fn () => $client->searchLocationOrders($one['cursor'], 99, $since, $ids))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $client->searchLocationOrders($one['cursor'], 100, $since, $ids, 'updated_at'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($calls)->toHaveCount($before);
});

it('retains original payment customer IDs while reconnecting a provider-confirmed customer redirect exactly once', function (): void {
    discoveryFake(['/v2/customers/bulk-retrieve' => ['responses' => ['OLDCUSTOMER' => ['customer' => ['id' => 'CURRENTCUSTOMER', 'version' => 3,
        'email_address' => 'known@example.invalid', 'preferences' => ['email_unsubscribed' => false]]]]]]);
    $policy = discoveryEnable($this);
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'email' => 'known@example.invalid', 'normalized_email' => 'known@example.invalid', 'accepts_sms_marketing' => false, 'accepts_email_marketing' => false]);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_customer', 'source_id' => 'CURRENTCUSTOMER']);
    SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'ORIGINALPAYMENT', 'square_customer_id' => 'OLDCUSTOMER', 'location_id' => 'OLD', 'status' => 'COMPLETED',
        'raw_payload' => ['_source_binding' => ['merchant_id' => 'DISCOVERYMERCHANT', 'environment' => 'sandbox']]]);
    $options = discoveryOptions($this, $policy, ['customer_ids' => ['OLDCUSTOMER']]);
    $service = app(SquareMarketingSyncService::class);
    $result = $service->reconcileCustomerReferences($options);
    expect($result['status'])->toBe('completed')->and(data_get($result, 'summary.provider_customer_redirects.0.requested_customer_id'))->toBe('OLDCUSTOMER')
        ->and(SquarePayment::sole()->square_customer_id)->toBe('OLDCUSTOMER')
        ->and(MarketingProfileLink::where('source_type', 'square_payment')->sole()->marketing_profile_id)->toBe($profile->id)
        ->and(MarketingProfileLink::where('source_id', 'OLDCUSTOMER')->sole()->source_meta['square_customer_redirect']['current_customer_id'])->toBe('CURRENTCUSTOMER')
        ->and($profile->fresh()->accepts_email_marketing)->toBeFalse()->and($profile->fresh()->accepts_sms_marketing)->toBeFalse()
        ->and(\App\Models\MarketingConsentEvent::count())->toBe(0)->and(\App\Models\CandleCashTransaction::count())->toBe(0);
    expect($service->reconcileCustomerReferences($options)['replayed'])->toBeTrue();
    // Original merchant verification is still allowed on replay; the bulk provider read is not repeated.
    expect(Http::recorded(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/customers/bulk-retrieve'))->count())->toBe(1);
    expect(MarketingProfileLink::where('source_type', 'square_payment')->count())->toBe(1);
});

it('stages conflicting old and current canonical customers for review and preserves their original claims', function (): void {
    discoveryFake(['/v2/customers/bulk-retrieve' => ['responses' => ['OLDCUSTOMER' => ['customer' => ['id' => 'CURRENTCUSTOMER']]]]]);
    $policy = discoveryEnable($this);
    $a = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    $b = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    foreach (['OLDCUSTOMER' => $a->id, 'CURRENTCUSTOMER' => $b->id] as $id => $profile) {
        MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile, 'source_type' => 'square_customer', 'source_id' => $id]);
    }
    $result = app(SquareMarketingSyncService::class)->reconcileCustomerReferences(discoveryOptions($this, $policy, ['customer_ids' => ['OLDCUSTOMER']]));
    expect($result['summary']['reviews_created'])->toBe(1)->and($a->fresh()->merged_into_profile_id)->toBeNull()->and($b->fresh()->merged_into_profile_id)->toBeNull()
        ->and(MarketingProfileLink::where('source_id', 'OLDCUSTOMER')->sole()->marketing_profile_id)->toBe($a->id)
        ->and(MarketingProfileLink::where('source_id', 'CURRENTCUSTOMER')->sole()->marketing_profile_id)->toBe($b->id);
});

it('rejects late alias evidence when the source policy is disabled while the provider read is in flight', function (): void {
    $policy = null;
    discoveryFake(['/v2/customers/bulk-retrieve' => ['responses' => ['OLD' => ['customer' => ['id' => 'NEW']]]]], function ($request) use (&$policy): void {
        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/customers/bulk-retrieve')) {
            app(SquareSourceRefresh::class)->configure($this->tenant->id, $this->owner, ['enabled' => false, 'expected_version' => $policy['version'], 'lookback_days' => 14, 'create_unmatched' => false]);
        }
    });
    $policy = discoveryEnable($this);
    expect(fn () => app(SquareMarketingSyncService::class)->reconcileCustomerReferences(discoveryOptions($this, $policy, ['customer_ids' => ['OLD']])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(\App\Models\SquareCustomer::count())->toBe(0)
        ->and(MarketingImportRun::where('type', 'square_customer_references_sync')->sole()->status)->toBe('failed');
});

it('resumes scheduled original reference checks without repeating a batch or crossing tenants and rejects revoked policy replays', function (): void {
    $responses = [];
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    foreach (['A', 'B', 'C'] as $id) {
        $responses[$id] = ['customer' => ['id' => $id]];
        MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_customer', 'source_id' => $id]);
        SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'scheduled-'.$id, 'square_customer_id' => $id,
            'location_id' => 'OLD', 'status' => 'COMPLETED', 'raw_payload' => ['_source_binding' => ['merchant_id' => 'DISCOVERYMERCHANT', 'environment' => 'sandbox']]]);
    }
    // The same original customer in orders and payments consumes one reference slot.
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'scheduled-order-A', 'square_customer_id' => 'A', 'location_id' => 'OLD']);
    $foreign = Tenant::create(['name' => 'Foreign scheduled source', 'slug' => 'foreign-scheduled-'.Str::lower(Str::random(8))]);
    SquarePayment::create(['tenant_id' => $foreign->id, 'square_payment_id' => 'foreign-scheduled', 'square_customer_id' => 'FOREIGN', 'location_id' => 'FOREIGN']);
    discoveryFake(['/v2/customers/bulk-retrieve' => ['responses' => $responses]]);
    $policy = discoveryEnable($this);
    $service = app(SquareCustomerReferenceReconciliation::class);
    $request = (string) Str::uuid();
    $one = $service->scheduled($this->tenant->id, $this->owner, $policy['version'], $request, 1);
    expect($one['status'])->toBe('partial')->and($one['summary']['after'])->toBe('A')->and($one['summary']['processed'])->toBe(1);
    $calls = Http::recorded()->count();
    expect($service->scheduled($this->tenant->id, $this->owner, $policy['version'], $request, 1)['replayed'])->toBeTrue();
    expect(Http::recorded()->count())->toBe($calls);
    $two = $service->scheduled($this->tenant->id, $this->owner, $policy['version'], (string) Str::uuid(), 1);
    expect($two['summary']['after'])->toBe('B')->and($two['status'])->toBe('partial');
    $lastRequest = (string) Str::uuid();
    $three = $service->scheduled($this->tenant->id, $this->owner, $policy['version'], $lastRequest, 1);
    expect($three['summary']['after'])->toBe('C')->and($three['status'])->toBe('completed')
        ->and(TenantMarketingSetting::forTenantId($this->tenant->id)->where('key', $service::CHECKPOINT_KEY)->sole()->value['after'])->toBeNull()
        ->and(\App\Models\SquareCustomer::forTenantId($foreign->id)->count())->toBe(0);
    app(SquareSourceRefresh::class)->configure($this->tenant->id, $this->owner, ['enabled' => false, 'expected_version' => $policy['version'], 'lookback_days' => 14, 'create_unmatched' => false]);
    expect(fn () => $service->scheduled($this->tenant->id, $this->owner, $policy['version'], $lastRequest, 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('exposes original reference preview and apply only to the current workspace owner', function (): void {
    discoveryFake(['/v2/customers/bulk-retrieve' => ['responses' => ['ORIGINAL' => ['customer' => ['id' => 'ORIGINAL']]]]]);
    $policy = discoveryEnable($this);
    SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'route-original', 'square_customer_id' => 'ORIGINAL', 'location_id' => 'OLD',
        'raw_payload' => ['_source_binding' => ['merchant_id' => 'DISCOVERYMERCHANT', 'environment' => 'sandbox']]]);
    $url = '/workspace/customers/square/reconcile-references?tenant='.$this->tenant->slug;
    $input = ['request_id' => (string) Str::uuid(), 'policy_version' => $policy['version'], 'apply' => false];
    $this->actingAs($this->owner)->postJson($url, $input)->assertOk()->assertJsonPath('processed', 1)->assertJsonPath('mode', 'preview');
    expect(\App\Models\SquareCustomer::count())->toBe(0);
    $this->postJson($url, ['request_id' => (string) Str::uuid(), 'apply' => true] + $input)->assertOk()->assertJsonPath('mode', 'import');
    expect(\App\Models\SquareCustomer::count())->toBe(1);
    $viewer = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $this->tenant->users()->attach($viewer->id, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($viewer)->postJson($url, $input)->assertForbidden();
});
