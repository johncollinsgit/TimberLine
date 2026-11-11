<?php

use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Pos\PosCustomerDirectory;
use App\Services\Pos\PosException;
use App\Services\Pos\PosStore;
use App\Services\Pos\StockPosCommerce;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Fictional customer checkout', 'slug' => 'customer-scope-'.Str::random(8)]);
    $this->shop = ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'counter_%!',
        'shop_domain' => 'fictional-checkout.myshopify.com', 'access_token' => 'fixture-not-used']);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional', 'email' => 'checkout@example.invalid']);
    $this->scope = ['tenant_id' => $this->tenant->id, 'register' => (object) ['catalog_source' => 'shopify',
        'configuration' => app(PosStore::class)->encrypt(['commerce_adapter' => StockPosCommerce::VERSION,
            'shopify' => ['store_id' => $this->shop->id, 'shop_domain' => $this->shop->shop_domain]])]];
});

function directoryScopeLink(int $tenant, int $profile, string $source, string $type = 'shopify_customer'): int
{
    // Fixture insertion also permits deliberately inconsistent legacy evidence.
    return DB::table('marketing_profile_links')->insertGetId(['tenant_id' => $tenant, 'marketing_profile_id' => $profile,
        'source_type' => $type, 'source_id' => $source, 'created_at' => now(), 'updated_at' => now()]);
}

function directoryScopeMeasuredFreeze(array $scope, int $id): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $result = app(PosCustomerDirectory::class)->freeze($scope, $id);

        return [$result, DB::getQueryLog()];
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

it('bounds customer checkout queries to the selected alias family despite a large unrelated directory', function (): void {
    $alias = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $this->profile->id, 'archived_at' => now()]);
    $older = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $alias->id, 'archived_at' => now()]);
    directoryScopeLink($this->tenant->id, $older->id, $this->shop->store_key.':12345');
    [$initial, $initialQueries] = directoryScopeMeasuredFreeze($this->scope, $older->id);

    $start = (int) DB::table('marketing_profiles')->max('id') + 1;
    $profiles = $links = [];
    for ($i = 0; $i < 600; $i++) {
        $profiles[] = ['id' => $start + $i, 'tenant_id' => $this->tenant->id, 'first_name' => 'Unrelated fixture'];
        $links[] = ['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $start + $i,
            'source_type' => 'shopify_customer', 'source_id' => $this->shop->store_key.':'.(20000 + $i)];
    }
    DB::table('marketing_profiles')->insert($profiles);
    DB::table('marketing_profile_links')->insert($links);
    [$expanded, $expandedQueries] = directoryScopeMeasuredFreeze($this->scope, $older->id);

    expect($expanded)->toBe($initial)
        ->and($expanded['id'])->toBe($this->profile->id)
        ->and($expanded['shopify_customer_id'])->toBe('gid://shopify/Customer/12345')
        ->and(count($expandedQueries))->toBe(count($initialQueries))->toBeLessThanOrEqual(10);
    $linkQueries = collect($expandedQueries)->filter(fn ($query) => str_contains($query['query'], 'marketing_profile_links'));
    expect($linkQueries)->toHaveCount(2);
    foreach ($linkQueries as $query) {
        expect($query['query'])->toContain('marketing_profile_id', ' in ', 'limit');
    }
});

it('preserves duplicate source conflict checks outside the alias family and rereads changed aliases', function (): void {
    $alias = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $this->profile->id]);
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Other fixture']);
    directoryScopeLink($this->tenant->id, $this->profile->id, $this->shop->store_key.':12345');
    // Simulate legacy/corrupted duplicate rows in this disposable test database.
    Schema::table('marketing_profile_links', fn (Blueprint $table) => $table->dropUnique('mpl_tenant_source_unique_idx'));
    $duplicate = null;
    try {
        $duplicate = directoryScopeLink($this->tenant->id, $alias->id, $this->shop->store_key.':12345');
        expect(app(PosCustomerDirectory::class)->freeze($this->scope, $alias->id)['shopify_customer_id'])->toBe('gid://shopify/Customer/12345');
        $alias->update(['merged_into_profile_id' => $other->id]);
        expect(fn () => app(PosCustomerDirectory::class)->freeze($this->scope, $this->profile->id))
            ->toThrow(PosException::class, 'customer_identity_review_required');
        $alias->update(['merged_into_profile_id' => $this->profile->id]);
        expect(app(PosCustomerDirectory::class)->freeze($this->scope, $this->profile->id)['shopify_customer_id'])->toBe('gid://shopify/Customer/12345');
        DB::table('marketing_profile_links')->where('id', $duplicate)->update(['marketing_profile_id' => $other->id]);
        expect(fn () => app(PosCustomerDirectory::class)->freeze($this->scope, $this->profile->id))
            ->toThrow(PosException::class, 'customer_identity_review_required');
    } finally {
        if ($duplicate !== null) {
            DB::table('marketing_profile_links')->where('id', $duplicate)->delete();
        }
        Schema::table('marketing_profile_links', fn (Blueprint $table) => $table->unique(['tenant_id', 'source_type', 'source_id'], 'mpl_tenant_source_unique_idx'));
    }
});

it('keeps tenant store prefix and source type fences and ignores malformed Shopify identifiers', function (): void {
    directoryScopeLink($this->tenant->id, $this->profile->id, $this->shop->store_key.':12345');
    foreach (['counterXab!:777', 'other:12345', 'COUNTER_%!:999', $this->shop->store_key.':001', $this->shop->store_key.':0', $this->shop->store_key.':123oops'] as $source) {
        directoryScopeLink($this->tenant->id, $this->profile->id, $source);
    }
    directoryScopeLink($this->tenant->id, $this->profile->id, $this->shop->store_key.':777', 'square_customer');
    $foreign = Tenant::create(['name' => 'Other tenant', 'slug' => 'other-customer-'.Str::random(8)]);
    $foreignProfile = MarketingProfile::create(['tenant_id' => $foreign->id, 'merged_into_profile_id' => $this->profile->id]);
    directoryScopeLink($foreign->id, $foreignProfile->id, $this->shop->store_key.':12345');
    expect(app(PosCustomerDirectory::class)->freeze($this->scope, $this->profile->id)['shopify_customer_id'])->toBe('gid://shopify/Customer/12345');
    expect(fn () => app(PosCustomerDirectory::class)->freeze($this->scope, $foreignProfile->id))->toThrow(PosException::class, 'customer_unavailable');
    $this->scope['register']->configuration = app(PosStore::class)->encrypt(['shopify' => ['store_id' => $this->shop->id, 'shop_domain' => 'different.myshopify.com']]);
    expect(fn () => app(PosCustomerDirectory::class)->freeze($this->scope, $this->profile->id))->toThrow(PosException::class, 'shopify_binding_unverified');
    $foreignStore = ShopifyStore::create(['tenant_id' => $foreign->id, 'store_key' => 'foreign-counter',
        'shop_domain' => 'foreign-counter.myshopify.com', 'access_token' => 'fixture-not-used']);
    $this->scope['register']->configuration = app(PosStore::class)->encrypt(['shopify' => ['store_id' => $foreignStore->id, 'shop_domain' => $foreignStore->shop_domain]]);
    expect(fn () => app(PosCustomerDirectory::class)->freeze($this->scope, $this->profile->id))->toThrow(PosException::class, 'shopify_binding_unverified');
});

it('rejects multiple valid Shopify identities across explicit aliases', function (): void {
    $alias = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $this->profile->id]);
    directoryScopeLink($this->tenant->id, $this->profile->id, $this->shop->store_key.':12345');
    directoryScopeLink($this->tenant->id, $alias->id, $this->shop->store_key.':67890');
    expect(fn () => app(PosCustomerDirectory::class)->freeze($this->scope, $alias->id))
        ->toThrow(PosException::class, 'customer_identity_review_required');
});

it('fails closed when the selected explicit family exceeds the bounded review size', function (): void {
    $start = (int) DB::table('marketing_profiles')->max('id') + 1;
    $rows = [];
    for ($i = 0; $i < 1000; $i++) {
        $rows[] = ['id' => $start + $i, 'tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $this->profile->id];
    }
    DB::table('marketing_profiles')->insert($rows);
    expect(fn () => app(PosCustomerDirectory::class)->freeze($this->scope, $this->profile->id))
        ->toThrow(PosException::class, 'customer_identity_review_required');
    DB::table('marketing_profiles')->where('tenant_id', $this->tenant->id)->where('id', '>=', $start)->delete();
    $parent = $this->profile;
    for ($depth = 0; $depth < 32; $depth++) {
        $parent = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $parent->id]);
    }
    expect(fn () => app(PosCustomerDirectory::class)->freeze($this->scope, $this->profile->id))
        ->toThrow(PosException::class, 'customer_identity_review_required');
});
