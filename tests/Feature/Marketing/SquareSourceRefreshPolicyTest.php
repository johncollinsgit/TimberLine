<?php

use App\Models\MarketingConsentEvent;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Marketing\SquareClient;
use App\Services\Marketing\SquareSourceRefresh;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['pos_live.enabled' => false, 'pos_live.runtime_id' => null, 'marketing.square.enabled' => false]);
    $this->tenant = Tenant::create(['name' => 'Fictional original merchant', 'slug' => 'source-policy-'.Str::lower(Str::random(8))]);
    $this->owner = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $this->tenant->users()->attach($this->owner->id, ['role' => 'owner', 'membership_active' => true]);
    $this->setting = TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => 'square_config', 'value' => [
        'connection_id' => 'policy-original-reader', 'generation' => 1, 'merchant_id' => 'POLICYMERCHANT', 'environment' => 'sandbox',
        'location_ids' => ['POLICYLOCATION'], 'status' => 'active', 'credentials' => app(\App\Services\Pos\PosStore::class)->encrypt(['access_token' => 'fictional-source-key', 'expires_at' => now()->addDay()->toIso8601String()])]]);
    $this->policy = app(SquareSourceRefresh::class);
});

function sourcePolicyFake(array $rows = [], ?Closure $inspect = null): void
{
    Http::fake(function ($request) use ($rows, $inspect) {
        if ($inspect) {
            $inspect($request);
        }
        expect($request->hasHeader('Authorization', 'Bearer fictional-source-key'))->toBeTrue();
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v2/merchants/POLICYMERCHANT') {
            return Http::response(['merchant' => ['id' => 'POLICYMERCHANT']]);
        }

        return Http::response($rows[$path] ?? throw new RuntimeException('Unexpected source path '.$path));
    });
}

function sourcePolicyEnable($test): array
{
    return $test->policy->configure($test->tenant->id, $test->owner, ['enabled' => true, 'expected_version' => $test->policy->status($test->tenant->id)['version'],
        'connection_id' => 'policy-original-reader', 'lookback_days' => 14, 'create_unmatched' => true]);
}

function sourcePolicyObjects(): array
{
    return ['/v2/customers/SOURCECUSTOMER' => ['customer' => ['id' => 'SOURCECUSTOMER', 'version' => 2, 'updated_at' => '2026-11-11T12:00:00Z',
        'given_name' => 'Source customer', 'email_address' => 'source-customer@example.invalid', 'preferences' => ['email_unsubscribed' => false]]],
        '/v2/orders/SOURCEORDER' => ['order' => ['id' => 'SOURCEORDER', 'version' => 5, 'updated_at' => '2026-11-11T12:00:00Z', 'closed_at' => '2026-11-07T12:00:00Z',
            'customer_id' => 'SOURCECUSTOMER', 'location_id' => 'POLICYLOCATION', 'state' => 'COMPLETED', 'total_money' => ['amount' => 1080, 'currency' => 'USD']]],
        '/v2/payments/SOURCEPAYMENT' => ['payment' => ['id' => 'SOURCEPAYMENT', 'order_id' => 'SOURCEORDER', 'customer_id' => 'SOURCECUSTOMER', 'location_id' => 'POLICYLOCATION',
            'created_at' => '2026-11-07T12:00:00Z', 'updated_at' => '2026-11-11T12:00:00Z', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 1080, 'currency' => 'USD'], 'refunded_money' => ['amount' => 500, 'currency' => 'USD']]]];
}

it('requires explicit source refresh enrollment and routes only exact current local original bindings', function (): void {
    expect($this->policy->status($this->tenant->id)['enabled'])->toBeFalse()->and($this->policy->webhookTargets('POLICYMERCHANT', 'sandbox', null))->toBe([]);
    Http::assertNothingSent();
    sourcePolicyFake();
    $policy = sourcePolicyEnable($this);
    Http::assertSentCount(1);
    $targets = $this->policy->webhookTargets('POLICYMERCHANT', 'sandbox', 'POLICYLOCATION');
    expect($targets)->toHaveCount(1)->and($targets[0]['binding']['policy_version'])->toBe($policy['version'])
        ->and($this->policy->webhookTargets('POLICYMERCHANT', 'production', 'POLICYLOCATION'))->toBe([])
        ->and($this->policy->webhookTargets('POLICYMERCHANT', 'sandbox', 'OTHERLOCATION'))->toBe([]);
    Http::assertSentCount(1);
    $bad = [...$targets[0]['binding'], 'connection_id' => 'wrong-reader'];
    expect(fn () => $this->policy->refreshObject($this->tenant->id, $bad, 'payment', 'SOURCEPAYMENT', (string) Str::uuid()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $value = $this->setting->value;
    $value['status'] = 'disconnected';
    $this->setting->update(['value' => $value]);
    expect($this->policy->webhookTargets('POLICYMERCHANT', 'sandbox', 'POLICYLOCATION'))->toBe([]);
});

it('refreshes targeted recent original customer order and payment records once without rewards or permission grants', function (): void {
    sourcePolicyFake(sourcePolicyObjects());
    sourcePolicyEnable($this);
    $binding = $this->policy->webhookTargets('POLICYMERCHANT', 'sandbox', 'POLICYLOCATION')[0]['binding'];
    $request = (string) Str::uuid();
    $result = $this->policy->refreshObject($this->tenant->id, $binding, 'payment', 'SOURCEPAYMENT', $request);
    expect($result['status'])->toBe('completed')->and($result['summary']['processed'])->toBe(3)
        ->and(SquareCustomer::forTenantId($this->tenant->id)->count())->toBe(1)->and(SquareOrder::forTenantId($this->tenant->id)->count())->toBe(1)
        ->and(SquarePayment::forTenantId($this->tenant->id)->count())->toBe(1)->and(MarketingProfile::forTenantId($this->tenant->id)->count())->toBe(1)
        ->and(MarketingProfile::forTenantId($this->tenant->id)->sole()->accepts_email_marketing)->toBeFalse()->and(MarketingConsentEvent::count())->toBe(0);
    $calls = Http::recorded()->count();
    expect($this->policy->refreshObject($this->tenant->id, $binding, 'payment', 'SOURCEPAYMENT', $request)['replayed'])->toBeTrue();
    Http::assertSentCount($calls);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    expect(\App\Models\CandleCashTransaction::count())->toBe(0);
});

it('retains newer completed and refunded financial snapshots when an older provider response finishes later', function (): void {
    $objects = sourcePolicyObjects();
    sourcePolicyFake($objects);
    sourcePolicyEnable($this);
    $binding = $this->policy->webhookTargets('POLICYMERCHANT', 'sandbox', null)[0]['binding'];
    $this->policy->refreshObject($this->tenant->id, $binding, 'payment', 'SOURCEPAYMENT', (string) Str::uuid());
    Http::swap(new \Illuminate\Http\Client\Factory);
    $objects['/v2/payments/SOURCEPAYMENT']['payment']['updated_at'] = '2026-11-10T12:00:00Z';
    $objects['/v2/payments/SOURCEPAYMENT']['payment']['status'] = 'APPROVED';
    $objects['/v2/payments/SOURCEPAYMENT']['payment']['refunded_money']['amount'] = 0;
    $objects['/v2/orders/SOURCEORDER']['order']['version'] = 4;
    sourcePolicyFake($objects);
    $result = $this->policy->refreshObject($this->tenant->id, $binding, 'payment', 'SOURCEPAYMENT', (string) Str::uuid());
    expect($result['summary']['records_skipped'])->toBe(2)->and(SquarePayment::sole()->status)->toBe('COMPLETED')
        ->and(SquarePayment::sole()->raw_payload['refunded_money']['amount'])->toBe(500)->and(SquareOrder::sole()->raw_payload['version'])->toBe(5);
    $this->policy->configure($this->tenant->id, $this->owner, ['enabled' => false, 'expected_version' => $this->policy->status($this->tenant->id)['version'], 'lookback_days' => 14, 'create_unmatched' => true]);
    expect(fn () => $this->policy->refreshObject($this->tenant->id, $binding, 'payment', 'SOURCEPAYMENT', (string) Str::uuid()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('reads each reviewed location through resumable provider cursors and matching original query fields', function (): void {
    $client = new SquareClient('fictional-source-key', 'https://connect.squareupsandbox.com');
    $locations = [];
    Http::fake(function ($request) use (&$locations) {
        $locations[] = $request['location_id'];

        return Http::response(['payments' => [], 'cursor' => count($locations) === 1 ? 'provider-next' : null]);
    });
    $since = \Carbon\CarbonImmutable::parse('2026-11-01T00:00:00Z');
    $first = $client->fetchLocationPayments(null, 100, $since, ['B', 'A'], true);
    $second = $client->fetchLocationPayments($first['cursor'], 100, $since, ['A', 'B'], true);
    $third = $client->fetchLocationPayments($second['cursor'], 100, $since, ['A', 'B'], true);
    expect($locations)->toBe(['A', 'A', 'B'])->and($third['cursor'])->toBeNull();
    Http::assertSent(fn ($request) => $request['begin_time'] === '2013-01-01T00:00:00Z' && $request['updated_at_begin_time'] === $since->toIso8601String() && $request['sort_field'] === 'UPDATED_AT');
    expect(fn () => $client->fetchLocationPayments($first['cursor'], 100, $since, ['A', 'C'], true))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('retains a failed original read and safely resumes that same webhook object without creating duplicates', function (): void {
    sourcePolicyFake();
    sourcePolicyEnable($this);
    $binding = $this->policy->webhookTargets('POLICYMERCHANT', 'sandbox', null)[0]['binding'];
    $request = (string) Str::uuid();
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(fn ($http) => str_contains($http->url(), '/merchants/') ? Http::response(['merchant' => ['id' => 'POLICYMERCHANT']]) : Http::response(['errors' => [['code' => 'TEMPORARY_ERROR']]], 503));
    expect(fn () => $this->policy->refreshObject($this->tenant->id, $binding, 'payment', 'SOURCEPAYMENT', $request))->toThrow(\Illuminate\Http\Client\RequestException::class);
    $failed = MarketingImportRun::where('type', 'square_source_object_sync')->sole();
    expect($failed->status)->toBe('failed')->and($failed->summary['provider_error']['http_status'])->toBe(503)->and(SquarePayment::count())->toBe(0);
    Http::swap(new \Illuminate\Http\Client\Factory);
    sourcePolicyFake(sourcePolicyObjects());
    $result = $this->policy->refreshObject($this->tenant->id, $binding, 'payment', 'SOURCEPAYMENT', $request);
    expect($result['status'])->toBe('completed')->and(MarketingImportRun::where('type', 'square_source_object_sync')->count())->toBe(2)
        ->and($failed->fresh()->status)->toBe('failed')->and(SquarePayment::count())->toBe(1)->and(MarketingProfile::count())->toBe(1);
    expect(fn () => $this->policy->refreshObject($this->tenant->id, $binding, 'customer', 'SOURCECUSTOMER', $request))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('rechecks an owner policy after the provider response and admits no source writes when access is revoked in flight', function (): void {
    sourcePolicyFake();
    sourcePolicyEnable($this);
    $binding = $this->policy->webhookTargets('POLICYMERCHANT', 'sandbox', null)[0]['binding'];
    Http::swap(new \Illuminate\Http\Client\Factory);
    sourcePolicyFake(sourcePolicyObjects(), function ($request): void {
        if (str_contains($request->url(), '/payments/')) {
            $this->policy->configure($this->tenant->id, $this->owner, ['enabled' => false, 'expected_version' => $this->policy->status($this->tenant->id)['version'], 'lookback_days' => 14, 'create_unmatched' => true]);
        }
    });
    expect(fn () => $this->policy->refreshObject($this->tenant->id, $binding, 'payment', 'SOURCEPAYMENT', (string) Str::uuid()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SquareCustomer::count())->toBe(0)->and(SquareOrder::count())->toBe(0)->and(SquarePayment::count())->toBe(0)->and(MarketingProfile::count())->toBe(0);
});

it('imports only the saved Customer API address through an exact owned source link and retains conflicting canonical locations for review', function (): void {
    $objects = sourcePolicyObjects();
    $objects['/v2/customers/SOURCECUSTOMER']['customer']['address'] = ['address_line_1' => '1 Fictional Way', 'locality' => 'Columbia', 'administrative_district_level_1' => 'SC', 'postal_code' => '29201', 'country' => 'US'];
    $objects['/v2/customers/SOURCECUSTOMER']['customer']['cards'] = [['id' => 'card-reference', 'billing_address' => ['postal_code' => '99999', 'country' => 'US']]];
    sourcePolicyFake($objects);
    sourcePolicyEnable($this);
    $binding = $this->policy->webhookTargets('POLICYMERCHANT', 'sandbox', null)[0]['binding'];
    $this->policy->refreshObject($this->tenant->id, $binding, 'customer', 'SOURCECUSTOMER', (string) Str::uuid());
    $profile = MarketingProfile::sole();
    expect($profile->postal_code)->toBe('29201')->and($profile->country)->toBe('US')->and($profile->city)->toBe('Columbia')
        ->and(data_get(SquareCustomer::sole()->raw_payload, 'cards.0.billing_address'))->toBeNull()
        ->and(\App\Models\MarketingProfileLink::sole()->source_meta['square_saved_location']['basis'])->toBe('original_customer_api_saved_address');
    $profile->update(['postal_code' => '29640', 'city' => 'Easley']);
    $this->policy->refreshObject($this->tenant->id, $binding, 'customer', 'SOURCECUSTOMER', (string) Str::uuid());
    expect($profile->fresh()->postal_code)->toBe('29640')->and($profile->fresh()->city)->toBe('Easley')
        ->and(\App\Models\MarketingProfileLink::sole()->source_meta['square_saved_location']['status'])->toBe('review_required')
        ->and(app(\App\Services\Marketing\SquareCustomerSource::class)->forStaff($profile, $this->tenant->id)['issues'])->toContain('square_saved_location_review_required');
});

it('keeps opted-in ongoing history refresh bounded, replayable and source-only with current API date fields', function (): void {
    $requests = [];
    $campaignsBefore = \App\Models\MarketingCampaign::count();
    sourcePolicyFake(['/v2/customers/search' => ['customers' => []], '/v2/orders/search' => ['orders' => []], '/v2/payments' => ['payments' => []]], function ($request) use (&$requests): void {
        $requests[] = $request;
    });
    sourcePolicyEnable($this);
    $result = $this->policy->runTenant($this->tenant->id, 50);
    expect(array_keys($result))->toBe(['customers', 'orders', 'payments']);
    foreach ($result as $kind => $run) {
        expect($run['status'])->toBe('completed')->and($run['summary']['historical_source_only'])->toBeTrue();
    }
    $count = Http::recorded()->count();
    $replay = $this->policy->runTenant($this->tenant->id, 50);
    foreach ($replay as $run) {
        expect($run['replayed'])->toBeTrue();
    }
    Http::assertSentCount($count);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/orders/search') && data_get($request->data(), 'query.sort.sort_field') === 'UPDATED_AT' && isset($request['query']['filter']['date_time_filter']['updated_at']));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/payments') && $request['location_id'] === 'POLICYLOCATION' && $request['sort_field'] === 'UPDATED_AT');
    expect(\App\Models\CandleCashTransaction::count())->toBe(0)->and(\App\Models\MarketingCampaign::count())->toBe($campaignsBefore);
});

it('retains a partial old-generation checkpoint and restarts the approved recent window after same-merchant credential renewal', function (): void {
    sourcePolicyFake(['/v2/customers/search' => ['customers' => []], '/v2/orders/search' => ['orders' => []], '/v2/payments' => ['payments' => []]]);
    $policy = sourcePolicyEnable($this);
    $prior = MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'type' => 'square_customers_sync', 'status' => 'partial', 'source_label' => 'square_customers',
        'created_by' => $this->owner->id, 'started_at' => now()->subMinute(), 'finished_at' => now()->subMinute(), 'summary' => [
            'source_refresh_policy_version' => $policy['version'], 'request_id' => (string) Str::uuid(), 'source_binding' => [...$policy['binding'], 'connection_generation' => 1],
            'checkpoint' => ['cursor' => 'old-generation-source-cursor'], 'create_unmatched' => true, 'mode' => 'live-sync', 'since' => now()->subDays(14)->toIso8601String()]]);
    $value = $this->setting->value;
    $value['generation'] = 2;
    $this->setting->update(['value' => $value]);
    $result = $this->policy->runTenant($this->tenant->id, 50);
    expect($result['customers']['status'])->toBe('completed')->and($result['customers']['summary']['generation_restart_from_run_id'])->toBe($prior->id)
        ->and($result['customers']['summary']['source_binding']['connection_generation'])->toBe(2)->and($prior->fresh()->summary['checkpoint']['cursor'])->toBe('old-generation-source-cursor');
    Http::assertNotSent(fn ($request) => ($request['cursor'] ?? null) === 'old-generation-source-cursor');
});
