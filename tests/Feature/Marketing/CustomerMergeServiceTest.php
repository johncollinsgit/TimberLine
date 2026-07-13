<?php

use App\Models\CustomerMergeOperation;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Marketing\CustomerMergeCandidateService;
use App\Services\Marketing\CustomerMergeCoordinator;
use App\Services\Marketing\CustomerMergeException;
use App\Services\Marketing\CustomerMergeService;
use App\Services\Marketing\MarketingProfileMergeReferenceRegistry;
use App\Services\Mobile\ModernForestryMobileCustomerSessionService;
use App\Services\Shopify\ShopifyCustomerMergeApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Tenant::query()->create(['id' => 1, 'name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    Tenant::query()->create(['id' => 2, 'name' => 'Other Store', 'slug' => 'other-store']);
});

test('Megan fixture consolidates aliases and preserves exactly 332 candle cash', function (): void {
    $survivor = MarketingProfile::factory()->create([
        'tenant_id' => 1, 'first_name' => 'Megan', 'last_name' => 'Lawther',
        'email' => 'megan@example.com', 'normalized_email' => 'megan@example.com',
    ]);
    $donor = MarketingProfile::factory()->create([
        'tenant_id' => 1, 'first_name' => 'Megan', 'last_name' => 'Lawter',
        'email' => 'old-megan@example.com', 'normalized_email' => 'old-megan@example.com',
    ]);
    $legacyOne = MarketingProfile::factory()->create([
        'tenant_id' => null, 'first_name' => 'Megan', 'last_name' => 'Lawther',
        'email' => 'megan@example.com', 'normalized_email' => 'megan@example.com',
    ]);
    $legacyTwo = MarketingProfile::factory()->create([
        'tenant_id' => null, 'first_name' => 'Megan', 'last_name' => 'Lawter',
        'email' => 'megan@example.com', 'normalized_email' => 'megan@example.com',
    ]);

    DB::table('candle_cash_transactions')->insert([
        ['marketing_profile_id' => $survivor->id, 'type' => 'earn', 'points' => 300, 'candle_cash_delta' => 300, 'source' => 'import', 'source_id' => 'opening:megan', 'created_at' => now(), 'updated_at' => now()],
        ['marketing_profile_id' => $donor->id, 'type' => 'earn', 'points' => 300, 'candle_cash_delta' => 300, 'source' => 'import', 'source_id' => 'opening:megan', 'created_at' => now(), 'updated_at' => now()],
        ['marketing_profile_id' => $donor->id, 'type' => 'earn', 'points' => 32, 'candle_cash_delta' => 32, 'source' => 'order', 'source_id' => '32728', 'created_at' => now(), 'updated_at' => now()],
    ]);
    foreach ([$survivor->id => 300, $donor->id => 332, $legacyOne->id => 0, $legacyTwo->id => 0] as $profileId => $balance) {
        DB::table('candle_cash_balances')->insert(['marketing_profile_id' => $profileId, 'balance' => $balance, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('customer_external_profiles')->insert([
        ['tenant_id' => 1, 'marketing_profile_id' => $survivor->id, 'provider' => 'shopify', 'integration' => 'shopify_customer', 'store_key' => 'retail', 'external_customer_id' => '3794940854346', 'external_customer_gid' => 'gid://shopify/Customer/3794940854346', 'created_at' => now(), 'updated_at' => now()],
        ['tenant_id' => 1, 'marketing_profile_id' => $donor->id, 'provider' => 'shopify', 'integration' => 'shopify_customer', 'store_key' => 'retail', 'external_customer_id' => '727716560931', 'external_customer_gid' => 'gid://shopify/Customer/727716560931', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('marketing_profile_links')->insert([
        ['marketing_profile_id' => $legacyOne->id, 'source_type' => 'shopify_customer', 'source_id' => '727716560931', 'created_at' => now(), 'updated_at' => now()],
        ['marketing_profile_id' => $legacyTwo->id, 'source_type' => 'growave_customer', 'source_id' => '3794940854346', 'created_at' => now(), 'updated_at' => now()],
    ]);
    Order::factory()->count(98)->create(['tenant_id' => 1, 'shopify_customer_id' => '727716560931']);

    $service = app(CustomerMergeService::class);
    $operation = $service->createOperation(1, [$survivor->id, $donor->id, $legacyOne->id, $legacyTwo->id], $survivor->id, 'retail', 'megan-fixture');
    $completed = $service->apply($operation, 'gid://shopify/Customer/3794940854346', 'gid://shopify/Customer/727716560931');

    expect($completed->status)->toBe('completed')
        ->and((float) DB::table('candle_cash_balances')->where('marketing_profile_id', $survivor->id)->value('balance'))->toBe(332.0)
        ->and(DB::table('candle_cash_transactions')->where('marketing_profile_id', $survivor->id)->count())->toBe(2)
        ->and(Order::query()->where('tenant_id', 1)->where('shopify_customer_id', '3794940854346')->count())->toBe(98)
        ->and(MarketingProfile::query()->whereIn('id', [$donor->id, $legacyOne->id, $legacyTwo->id])->where('merged_into_profile_id', $survivor->id)->whereNotNull('merged_at')->count())->toBe(3);

    $replayed = $service->apply($completed);
    expect($replayed->status)->toBe('completed')
        ->and((float) DB::table('candle_cash_balances')->where('marketing_profile_id', $survivor->id)->value('balance'))->toBe(332.0);
});

test('balance without a supporting ledger blocks instead of creating a balancing adjustment', function (): void {
    $survivor = MarketingProfile::factory()->create(['tenant_id' => 1]);
    $donor = MarketingProfile::factory()->create(['tenant_id' => 1]);
    DB::table('candle_cash_balances')->insert(['marketing_profile_id' => $donor->id, 'balance' => 15, 'created_at' => now(), 'updated_at' => now()]);
    $service = app(CustomerMergeService::class);
    $operation = $service->createOperation(1, [$survivor->id, $donor->id], $survivor->id, 'retail', 'ambiguous-balance');

    expect(fn () => $service->apply($operation))->toThrow(CustomerMergeException::class, 'Choose how to handle a balance without supporting ledger entries.');
    expect(DB::table('candle_cash_transactions')->count())->toBe(0);
});

test('explicitly proven opening balances become auditable opening ledger entries', function (): void {
    $survivor = MarketingProfile::factory()->create(['tenant_id' => 1]);
    $donor = MarketingProfile::factory()->create(['tenant_id' => 1]);
    foreach ([$survivor->id => 10, $donor->id => 5] as $profileId => $balance) {
        DB::table('candle_cash_balances')->insert(['marketing_profile_id' => $profileId, 'balance' => $balance, 'created_at' => now(), 'updated_at' => now()]);
    }
    $service = app(CustomerMergeService::class);
    $operation = $service->createOperation(1, [$survivor->id, $donor->id], $survivor->id, 'retail', 'distinct-openings');
    $operation->forceFill(['reward_resolution' => array_merge($operation->reward_resolution, [
        'ambiguous_balances' => [(string) $survivor->id => 'include_as_opening', (string) $donor->id => 'include_as_opening'],
    ])])->save();

    $service->apply($operation);
    expect((float) DB::table('candle_cash_balances')->where('marketing_profile_id', $survivor->id)->value('balance'))->toBe(15.0)
        ->and(DB::table('candle_cash_transactions')->where('source', 'customer_merge_opening_balance')->count())->toBe(2);
});

test('three Shopify customers stop with an auditable partial failure after a later pair fails', function (): void {
    $approver = User::factory()->create(['role' => 'admin']);
    $profiles = collect(range(1, 3))->map(fn (int $number) => MarketingProfile::factory()->create(['tenant_id' => 1, 'email' => "merge{$number}@example.com", 'normalized_email' => "merge{$number}@example.com"]));
    foreach ($profiles as $index => $profile) {
        DB::table('customer_external_profiles')->insert([
            'tenant_id' => 1, 'marketing_profile_id' => $profile->id, 'provider' => 'shopify', 'integration' => 'shopify_customer', 'store_key' => 'retail',
            'external_customer_id' => (string) ($index + 1), 'external_customer_gid' => 'gid://shopify/Customer/'.($index + 1), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $api = Mockery::mock(ShopifyCustomerMergeApi::class);
    $api->shouldReceive('preview')->twice()->andReturnUsing(fn (array $store, string $one, string $two): array => [
        'resultingCustomerId' => $two, 'customerMergeErrors' => [], 'blockingFields' => ['note' => null, 'tags' => []],
    ]);
    $api->shouldReceive('merge')->once()->andReturn(['resultingCustomerId' => 'gid://shopify/Customer/2', 'job' => ['id' => 'job-1', 'done' => true], 'userErrors' => []]);
    $api->shouldReceive('merge')->once()->andReturn(['resultingCustomerId' => null, 'job' => null, 'userErrors' => [['code' => 'CUSTOMER_HAS_STORE_CREDIT', 'message' => 'Customer has store credit.']]]);
    app()->instance(ShopifyCustomerMergeApi::class, $api);
    $coordinator = app(CustomerMergeCoordinator::class);
    $prepared = $coordinator->prepare(1, 'retail', [], $profiles->pluck('id')->all(), $profiles->first()->id, [], [], 'pairwise-failure');
    $operation = $coordinator->execute($prepared['operation'], [], $approver->id);

    expect($operation->status)->toBe('partial_failure')
        ->and(data_get($operation->shopify_preview, 'sequence.0.status'))->toBe('completed')
        ->and(MarketingProfile::query()->whereIn('id', $profiles->pluck('id'))->whereNotNull('merged_at')->count())->toBe(0);
});

test('misspelled names are suggestions and tenant records remain isolated', function (): void {
    $megan = MarketingProfile::factory()->create(['tenant_id' => 1, 'first_name' => 'Megan', 'last_name' => 'Lawther']);
    MarketingProfile::factory()->create(['tenant_id' => 2, 'first_name' => 'Megan', 'last_name' => 'Lawter']);
    $results = app(CustomerMergeCandidateService::class)->search(1, 'Megan Lawter', 'retail');

    expect(collect($results)->pluck('id')->all())->toContain($megan->id)
        ->and(collect($results)->pluck('tenant_id')->unique()->all())->toBe([1])
        ->and(collect($results)->firstWhere('id', $megan->id)['evidence'])->toContain('probable misspelling');
});

test('candidate search finds a customer beyond the former in-memory caps', function (): void {
    MarketingProfile::factory()->count(760)->create(['tenant_id' => 1]);
    $faith = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'first_name' => 'Faith',
        'last_name' => 'Crocker',
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
    ]);

    $results = app(CustomerMergeCandidateService::class)->search(1, 'Faith Crocker', 'retail');

    expect(collect($results)->pluck('id'))->toContain($faith->id);
});

test('canonical profile resolution follows aliases and fails closed on ambiguous email', function (): void {
    $survivor = MarketingProfile::factory()->create(['tenant_id' => 1, 'email' => 'faith@example.com', 'normalized_email' => 'faith@example.com']);
    $alias = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
        'merged_into_profile_id' => $survivor->id,
        'merged_at' => now(),
    ]);
    $resolver = app(CanonicalMarketingProfileResolver::class);

    expect($resolver->byId(1, $alias->id)?->id)->toBe($survivor->id)
        ->and($resolver->byEmail(1, 'faith@example.com')?->id)->toBe($survivor->id)
        ->and(app(ModernForestryMobileCustomerSessionService::class)->resolveToken('mf-test-profile:'.$alias->id)?->profile->id)->toBe($survivor->id);

    MarketingProfile::factory()->create(['tenant_id' => 1, 'email' => 'faith@example.com', 'normalized_email' => 'faith@example.com']);
    expect($resolver->byEmail(1, 'faith@example.com'))->toBeNull()
        ->and(app(ModernForestryMobileCustomerSessionService::class)->resolveToken('mf-test-email:faith@example.com'))->toBeNull();
});

test('backfilled legacy links and birthday history merge without unique collisions or data loss', function (): void {
    $fixture = require base_path('tests/Fixtures/Marketing/mysql_backfilled_legacy_customer.php');
    $survivor = MarketingProfile::factory()->create(['tenant_id' => 1, ...$fixture['identity']]);
    $donor = MarketingProfile::factory()->create(['tenant_id' => 1, ...$fixture['identity']]);
    DB::table('marketing_profile_links')->insert([
        ['tenant_id' => 1, 'marketing_profile_id' => $survivor->id, 'source_type' => $fixture['shopify_link']['source_type'], 'source_id' => $fixture['shopify_link']['source_id'], 'source_meta' => json_encode($fixture['shopify_link']['canonical_meta']), 'created_at' => now(), 'updated_at' => now()],
        ['tenant_id' => null, 'marketing_profile_id' => $donor->id, 'source_type' => $fixture['shopify_link']['source_type'], 'source_id' => $fixture['shopify_link']['source_id'], 'source_meta' => json_encode($fixture['shopify_link']['legacy_meta']), 'created_at' => now(), 'updated_at' => now()],
    ]);
    $survivorBirthday = DB::table('customer_birthday_profiles')->insertGetId([
        'marketing_profile_id' => $survivor->id, ...$fixture['canonical_birthday'], 'created_at' => now(), 'updated_at' => now(),
    ]);
    $donorBirthday = DB::table('customer_birthday_profiles')->insertGetId([
        'marketing_profile_id' => $donor->id, ...$fixture['legacy_birthday'], 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('customer_birthday_audits')->insert([
        ['customer_birthday_profile_id' => $survivorBirthday, 'marketing_profile_id' => $survivor->id, 'action' => 'created', 'created_at' => now(), 'updated_at' => now()],
        ['customer_birthday_profile_id' => $donorBirthday, 'marketing_profile_id' => $donor->id, 'action' => 'imported', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $service = app(CustomerMergeService::class);
    $operation = $service->createOperation(1, [$survivor->id, $donor->id], $survivor->id, 'retail', 'faith-backfilled-collision');
    $completed = $service->apply($operation);

    expect($completed->status)->toBe('completed')
        ->and(DB::table('marketing_profile_links')->where('tenant_id', 1)->where('source_type', $fixture['shopify_link']['source_type'])->where('source_id', $fixture['shopify_link']['source_id'])->count())->toBe(1)
        ->and(json_decode((string) DB::table('marketing_profile_links')->where('tenant_id', 1)->where('source_id', $fixture['shopify_link']['source_id'])->value('source_meta'), true))->toMatchArray(['canonical' => true, 'legacy' => true])
        ->and(DB::table('customer_birthday_profiles')->where('marketing_profile_id', $survivor->id)->count())->toBe(1)
        ->and(DB::table('customer_birthday_audits')->where('marketing_profile_id', $survivor->id)->count())->toBe(2)
        ->and(DB::table('customer_birthday_audits')->where('customer_birthday_profile_id', $survivorBirthday)->count())->toBe(2);
});

test('every profile-owned foreign key has an explicit merge policy', function (): void {
    $registry = app(MarketingProfileMergeReferenceRegistry::class);
    $handled = collect($registry->directReferences())->flatMap(fn (array $columns, string $table) => collect($columns)->map(fn (string $column) => $table.'.'.$column))
        ->merge(collect($registry->conflictReferences())->map(fn (array $policy, string $table) => $table.'.'.$policy['column']))
        ->merge(['candle_cash_transactions.marketing_profile_id', 'candle_cash_balances.marketing_profile_id'])
        ->all();
    $excludedAuditReferences = ['customer_merge_operation_members.marketing_profile_id'];
    $unhandled = [];
    foreach (Schema::getTables() as $tableMeta) {
        $table = (string) ($tableMeta['name'] ?? '');
        foreach (Schema::getColumnListing($table) as $column) {
            if (str_contains($column, 'marketing_profile_id') && ! in_array($table.'.'.$column, [...$handled, ...$excludedAuditReferences], true)) {
                $unhandled[] = $table.'.'.$column;
            }
        }
    }
    expect($unhandled)->toBe([]);
});

test('failed Shopify merge webhook is HMAC verified and recorded as a blocked operation', function (): void {
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'retail-client');
    config()->set('services.shopify.stores.retail.client_secret', 'retail-secret');
    config()->set('services.shopify.allow_env_token_fallback', false);
    ShopifyStore::query()->create([
        'tenant_id' => 1, 'store_key' => 'retail', 'shop_domain' => 'retail-test.myshopify.com',
        'access_token' => 'token', 'scopes' => 'read_customer_merge,write_customer_merge', 'installed_at' => now(),
    ]);
    $payload = [
        'admin_graphql_api_customer_kept_id' => 'gid://shopify/Customer/1',
        'admin_graphql_api_customer_deleted_id' => 'gid://shopify/Customer/2',
        'admin_graphql_api_job_id' => 'gid://shopify/Job/failed-merge',
        'status' => 'failed',
        'errors' => [['field' => 'merge_in_progress', 'message' => 'Customer is currently being merged.']],
    ];
    $encoded = json_encode($payload);
    $hmac = base64_encode(hash_hmac('sha256', (string) $encoded, 'retail-secret', true));

    $this->call('POST', '/webhooks/shopify/customers/merge', [], [], [], [
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'retail-test.myshopify.com',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
        'CONTENT_TYPE' => 'application/json',
    ], (string) $encoded)->assertOk();

    expect(CustomerMergeOperation::query()->where('source', 'shopify_webhook')->where('status', 'blocked')->count())->toBe(1)
        ->and(data_get(CustomerMergeOperation::query()->first()?->errors, '0.field'))->toBe('merge_in_progress');
});
