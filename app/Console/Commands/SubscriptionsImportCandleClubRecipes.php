<?php

namespace App\Console\Commands;

use App\Models\BaseOil;
use App\Models\CandleClubScent;
use App\Models\Scent;
use App\Models\ScentRecipe;
use App\Models\ScentRecipeComponent;
use App\Services\Media\FreeStockPhotoService;
use App\Services\Shopify\ShopifyCliAdminClient;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SubscriptionsImportCandleClubRecipes extends Command
{
    protected $signature = 'subscriptions:import-candle-club-recipes
        {--tenant=1 : Tenant ID that owns the Candle Club module}
        {--store=retail : Shopify store key used for product lookup/creation}
        {--apply : Write local scent, recipe, and monthly Candle Club records}
        {--sync-shopify : Lookup existing Shopify products and create missing draft products}
        {--photos : Fill empty monthly scent photos from configured stock photo providers}
        {--allow-nonstandard-tenant : Permit a non-tenant-1 Modern Forestry import for local diagnostics only}
        {--limit= : Optional number of rows to process for testing}
        {--from= : Optional YYYY-MM lower bound}
        {--to= : Optional YYYY-MM upper bound}';

    protected $description = 'Import Candle Club scent history as internal recipes plus member-safe monthly scent cards.';

    protected ?string $resolvedShopDomain = null;

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $apply = (bool) $this->option('apply') || (bool) $this->option('sync-shopify');
        $syncShopify = (bool) $this->option('sync-shopify');
        $withPhotos = (bool) $this->option('photos') || $syncShopify;
        $limit = $this->positiveInt($this->option('limit'));

        if ($tenantId < 1) {
            $this->error('Use a valid tenant id.');

            return self::FAILURE;
        }

        if (! $this->tenantIsAllowed($tenantId)) {
            return self::FAILURE;
        }

        if (! $this->requiredTablesExist()) {
            $this->error('Subscription and scent tables are not migrated yet.');

            return self::FAILURE;
        }

        $rows = collect($this->recipeRows())
            ->filter(fn (array $row): bool => $this->withinWindow($row))
            ->when($limit !== null, fn ($collection) => $collection->take($limit))
            ->values();

        $this->line('tenant_id='.$tenantId);
        $this->line('mode='.($apply ? 'apply' : 'dry-run'));
        $this->line('shopify='.($syncShopify ? 'sync' : 'skip'));
        $this->line('photos='.($withPhotos ? 'enabled' : 'skip'));
        $this->line('rows='.$rows->count());

        $shopify = null;
        $shopDomain = null;
        if ($syncShopify) {
            try {
                [$shopDomain, $shopify] = $this->shopifyClient((string) $this->option('store'));
                $this->line('shopify_store='.$shopDomain);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        $summary = [
            'processed' => 0,
            'local_upserts' => 0,
            'shopify_matched' => 0,
            'shopify_created' => 0,
            'photos_attached' => 0,
            'errors' => 0,
        ];

        foreach ($rows as $row) {
            $summary['processed']++;

            try {
                $photo = $withPhotos ? $this->photoFor($row) : null;
                $shopifyProduct = null;

                if ($syncShopify && is_object($shopify)) {
                    $shopifyProduct = $this->findShopifyProduct($shopify, $row);

                    if ($shopifyProduct === null) {
                        $shopifyProduct = $this->createDraftShopifyProduct($shopify, $row, $photo);
                        $summary['shopify_created']++;
                    } else {
                        $summary['shopify_matched']++;
                    }
                }

                if ($apply) {
                    $this->upsertLocalRecords($tenantId, $row, $photo, $shopifyProduct);
                    $summary['local_upserts']++;
                    if ($photo !== null) {
                        $summary['photos_attached']++;
                    }
                }

                $this->line(sprintf(
                    '%s|%s|%s|%s',
                    $row['period'],
                    $row['scent_name'],
                    $shopifyProduct ? 'shopify='.($shopifyProduct['handle'] ?? $shopifyProduct['id']) : 'shopify=not_synced',
                    $photo ? 'photo=yes' : 'photo=no'
                ));
            } catch (Throwable $exception) {
                $summary['errors']++;
                $this->warn($row['period'].'|'.$row['scent_name'].'|error='.$exception->getMessage());
            }
        }

        foreach ($summary as $key => $value) {
            $this->line($key.'='.$value);
        }

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function requiredTablesExist(): bool
    {
        return Schema::hasTable('scents')
            && Schema::hasTable('base_oils')
            && Schema::hasTable('scent_recipes')
            && Schema::hasTable('scent_recipe_components')
            && Schema::hasTable('candle_club_scents')
            && Schema::hasTable('subscription_candle_club_monthly_scents');
    }

    protected function tenantIsAllowed(int $tenantId): bool
    {
        if (! Schema::hasTable('tenants')) {
            $this->error('Tenants table is not available.');

            return false;
        }

        $tenant = DB::table('tenants')
            ->where('id', $tenantId)
            ->first(['id', 'slug', 'name']);

        if (! $tenant) {
            $this->error("Tenant {$tenantId} does not exist. Candle Club import is tenant-id strict.");

            return false;
        }

        $slug = (string) ($tenant->slug ?? '');
        if ($tenantId === 1 && $slug === 'modern-forestry') {
            return true;
        }

        if ($slug === 'modern-forestry' && (bool) $this->option('allow-nonstandard-tenant')) {
            $this->warn("Using Modern Forestry tenant {$tenantId} for local diagnostics only. Production Candle Club import must use tenant 1.");

            return true;
        }

        $this->error(sprintf(
            'Candle Club import must run against Modern Forestry tenant 1. Resolved tenant %d (%s).',
            $tenantId,
            $slug !== '' ? $slug : 'missing-slug'
        ));

        return false;
    }

    protected function withinWindow(array $row): bool
    {
        $period = (string) $row['period'];
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));

        return ($from === '' || $period >= $from)
            && ($to === '' || $period <= $to);
    }

    protected function upsertLocalRecords(int $tenantId, array $row, ?array $photo, ?array $shopifyProduct): void
    {
        DB::transaction(function () use ($tenantId, $row, $photo, $shopifyProduct): void {
            $scent = $this->upsertScent($row);
            $recipe = $this->upsertRecipe($scent, $row);

            $scent->forceFill([
                'current_scent_recipe_id' => $recipe->id,
            ])->save();

            $candleClubScent = CandleClubScent::query()->updateOrCreate(
                [
                    'month' => (int) $row['month'],
                    'year' => (int) $row['year'],
                ],
                [
                    'scent_id' => $scent->id,
                ]
            );

            DB::table('subscription_candle_club_monthly_scents')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'year' => (int) $row['year'],
                    'month' => (int) $row['month'],
                ],
                [
                    'candle_club_scent_id' => $candleClubScent->id,
                    'scent_id' => $scent->id,
                    'title' => (string) $row['scent_name'],
                    'description' => $this->memberDescription($row),
                    'status' => 'chosen',
                    'shopify_product_gid' => $shopifyProduct['id'] ?? null,
                    'shopify_product_handle' => $shopifyProduct['handle'] ?? null,
                    'shopify_product_status' => strtolower((string) ($shopifyProduct['status'] ?? 'draft')),
                    'photo_url' => $photo['url'] ?? $shopifyProduct['image_url'] ?? null,
                    'photo_source' => $photo['source'] ?? ($shopifyProduct['image_url'] ?? null ? 'shopify' : null),
                    'photo_author' => $photo['author'] ?? null,
                    'photo_query' => $photo['query'] ?? null,
                    'photo_metadata' => isset($photo['metadata'])
                        ? json_encode($photo['metadata'], JSON_THROW_ON_ERROR)
                        : null,
                    'selected_at' => CarbonImmutable::create((int) $row['year'], (int) $row['month'], 1)->endOfMonth(),
                    'metadata' => json_encode([
                        'source' => 'candle_club_recipe_import',
                        'internal_recipe_oils' => $row['oils'],
                        'internal_abbreviation' => $row['abbreviation'],
                        'internal_notes' => $row['notes'],
                        'member_safe' => true,
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        });
    }

    protected function upsertScent(array $row): Scent
    {
        $displayName = (string) $row['scent_name'];
        $scent = Scent::query()
            ->whereRaw('lower(name) = ?', [strtolower($displayName)])
            ->orWhereRaw('lower(coalesce(display_name, name)) = ?', [strtolower($displayName)])
            ->first();

        if (! $scent) {
            $scent = new Scent(['name' => $displayName]);
        }

        $scent->fill([
            'display_name' => $displayName,
            'oil_reference_name' => $this->internalOilSummary($row),
            'notes' => trim((string) $row['notes']) ?: null,
            'abbreviation' => $row['abbreviation'] ?: null,
            'is_blend' => count($row['oils']) > 1,
            'blend_oil_count' => count($row['oils']),
            'recipe_components_json' => $row['oils'],
            'availability_json' => [
                'channels' => ['candle_club'],
                'member_safe_name' => $displayName,
            ],
            'lifecycle_status' => 'active',
            'is_candle_club' => true,
            'is_active' => true,
        ]);
        $scent->save();

        return $scent;
    }

    protected function upsertRecipe(Scent $scent, array $row): ScentRecipe
    {
        $recipe = ScentRecipe::query()
            ->where('scent_id', $scent->id)
            ->where('source_context', 'candle_club_recipe_import')
            ->first();

        if (! $recipe) {
            $nextVersion = ((int) ScentRecipe::query()
                ->where('scent_id', $scent->id)
                ->max('version')) + 1;

            $recipe = new ScentRecipe([
                'scent_id' => $scent->id,
                'source_context' => 'candle_club_recipe_import',
                'version' => max(1, $nextVersion),
            ]);
        }

        $recipe->fill([
            'status' => 'active',
            'is_active' => true,
            'activated_at' => CarbonImmutable::create((int) $row['year'], (int) $row['month'], 1),
            'notes' => trim((string) $row['notes']) ?: null,
        ]);
        $recipe->save();

        $recipe->components()->delete();
        $totalParts = max(1.0, array_sum(array_map(
            static fn (array $oil): float => (float) ($oil['parts'] ?? 1),
            $row['oils']
        )));

        foreach (array_values($row['oils']) as $index => $oil) {
            $baseOil = BaseOil::query()->firstOrCreate(
                ['name' => (string) $oil['name']],
                [
                    'grams_on_hand' => 0,
                    'reorder_threshold' => 0,
                    'jug_size_grams' => 2268,
                    'supplier' => 'CandleScience',
                    'active' => true,
                ]
            );

            ScentRecipeComponent::query()->create([
                'scent_recipe_id' => $recipe->id,
                'component_type' => ScentRecipeComponent::TYPE_OIL,
                'base_oil_id' => $baseOil->id,
                'parts' => (float) ($oil['parts'] ?? 1),
                'percentage' => round(((float) ($oil['parts'] ?? 1) / $totalParts) * 100, 4),
                'sort_order' => $index + 1,
            ]);
        }

        return $recipe;
    }

    protected function internalOilSummary(array $row): string
    {
        return collect($row['oils'])
            ->map(fn (array $oil): string => (string) $oil['name'].' ('.(string) $oil['parts'].' part'.(((float) $oil['parts']) === 1.0 ? '' : 's').')')
            ->implode(' + ');
    }

    protected function memberDescription(array $row): string
    {
        $name = (string) $row['scent_name'];
        $period = CarbonImmutable::create((int) $row['year'], (int) $row['month'], 1)->format('F Y');
        $mood = $this->memberMood($name);

        return "{$period} Candle Club exclusive. {$mood} Members can review it, request it again, and admins can later publish the draft product if it earns a permanent spot.";
    }

    protected function memberMood(string $name): string
    {
        $normalized = strtolower($name);

        $rules = [
            'coffee|chai|latte|pipe|tobacco|campfire|embers|cabin' => 'Cozy, warm, and comfort-forward without revealing the internal recipe.',
            'rose|jasmine|honeysuckle|iris|thistle|blossom|garden' => 'Soft floral character with a polished seasonal finish.',
            'citrus|orange|lemon|yuzu|margarita|splash|fizz|champagne|bubbly' => 'Bright, sparkling, and fresh for an easy seasonal burn.',
            'sea|coast|beach|waters|rain|mist|fog|linen|calm|breeze' => 'Clean, airy, and coastal with a relaxed Candle Club feel.',
            'pumpkin|apple|autumn|leaves|oakmoss|woodland|cedar|laurel|fig|mountain' => 'Grounded, woodsy, and seasonal with a Modern Forestry finish.',
            'christmas|snow|holiday|garland|calm' => 'Wintery, peaceful, and festive without feeling overly sweet.',
            'berry|berries|raspberry|pomegranate|cherry|sangria|poison' => 'Juicy, fruit-forward, and balanced for a limited monthly feature.',
        ];

        foreach ($rules as $pattern => $description) {
            if (preg_match('/('.$pattern.')/i', $normalized) === 1) {
                return $description;
            }
        }

        return 'A limited monthly scent built for Candle Club discovery, feedback, and future member requests.';
    }

    /**
     * @return array{0:string,1:object}
     */
    protected function shopifyClient(string $storeKey): array
    {
        $store = ShopifyStores::find($storeKey);
        if (! is_array($store)) {
            $domain = $this->normalizeShopDomain($storeKey);
            if ($domain === null) {
                throw new RuntimeException("Shopify store '{$storeKey}' is not configured.");
            }

            $this->resolvedShopDomain = $domain;

            return [$domain, app(ShopifyCliAdminClient::class)];
        }

        $shop = trim((string) ($store['shop'] ?? ''));
        $token = trim((string) ($store['legacy_token'] ?? $store['access_token'] ?? ''));
        $apiVersion = trim((string) ($store['api_version'] ?? config('services.shopify.api_version', '2026-01')));

        if ($shop === '') {
            throw new RuntimeException("Shopify store '{$storeKey}' is missing a shop domain.");
        }

        if ($token !== '') {
            $this->resolvedShopDomain = $shop;

            return [$shop, new ShopifyGraphqlClient($shop, $token, $apiVersion)];
        }

        $this->resolvedShopDomain = $shop;

        return [$shop, app(ShopifyCliAdminClient::class)];
    }

    protected function normalizeShopDomain(string $value): ?string
    {
        $domain = strtolower(trim($value));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = explode('/', (string) $domain)[0] ?? '';
        $domain = trim($domain, '.');

        return str_ends_with($domain, '.myshopify.com') ? $domain : null;
    }

    protected function findShopifyProduct(object $shopify, array $row): ?array
    {
        $query = <<<'GRAPHQL'
query CandleClubProductSearch($first: Int!, $query: String!) {
  products(first: $first, query: $query) {
    nodes {
      id
      title
      handle
      status
      featuredImage {
        url
      }
    }
  }
}
GRAPHQL;

        $title = (string) $row['scent_name'];
        $handle = Str::slug($title);
        $searches = [
            'title:"'.str_replace('"', '\"', $title).'"',
            'handle:'.$handle,
            $title,
        ];

        foreach ($searches as $search) {
            $data = $this->shopifyQuery($shopify, $query, [
                'first' => 10,
                'query' => $search,
            ]);

            $products = data_get($data, 'products.nodes', []);
            if (! is_array($products)) {
                continue;
            }

            $match = collect($products)
                ->map(fn ($product): array => is_array($product) ? $product : [])
                ->filter(fn (array $product): bool => $product !== [])
                ->sortByDesc(fn (array $product): int => $this->productMatchScore($title, $product))
                ->first(fn (array $product): bool => $this->productMatchScore($title, $product) >= 75);

            if (is_array($match)) {
                return [
                    'id' => $match['id'] ?? null,
                    'title' => $match['title'] ?? null,
                    'handle' => $match['handle'] ?? null,
                    'status' => $match['status'] ?? null,
                    'image_url' => data_get($match, 'featuredImage.url'),
                ];
            }
        }

        return null;
    }

    protected function productMatchScore(string $title, array $product): int
    {
        $target = Str::slug($title);
        $candidateTitle = Str::slug((string) ($product['title'] ?? ''));
        $candidateHandle = Str::slug((string) ($product['handle'] ?? ''));

        if ($candidateTitle === $target || $candidateHandle === $target) {
            return 100;
        }

        similar_text($target, $candidateTitle, $titlePercent);
        similar_text($target, $candidateHandle, $handlePercent);

        return (int) max($titlePercent, $handlePercent);
    }

    protected function createDraftShopifyProduct(object $shopify, array $row, ?array $photo): array
    {
        $mutation = <<<'GRAPHQL'
mutation CandleClubProductCreate($product: ProductCreateInput!, $media: [CreateMediaInput!]) {
  productCreate(product: $product, media: $media) {
    product {
      id
      title
      handle
      status
      featuredImage {
        url
      }
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $monthLabel = CarbonImmutable::create((int) $row['year'], (int) $row['month'], 1)->format('F Y');
        $variables = [
            'product' => [
                'title' => (string) $row['scent_name'],
                'descriptionHtml' => '<p>'.e($this->memberDescription($row)).'</p><p><strong>Candle Club exclusive:</strong> draft product for member viewing, reviews, and future publishing.</p>',
                'productType' => 'Candle Club Exclusive',
                'status' => 'DRAFT',
                'tags' => [
                    'Candle Club',
                    'Candle Club Exclusive',
                    'Candle Club Monthly Scent',
                    $monthLabel,
                ],
                'metafields' => [
                    ['namespace' => 'evergrove', 'key' => 'candle_club_exclusive', 'type' => 'boolean', 'value' => 'true'],
                    ['namespace' => 'evergrove', 'key' => 'candle_club_month', 'type' => 'single_line_text_field', 'value' => sprintf('%04d-%02d', (int) $row['year'], (int) $row['month'])],
                    ['namespace' => 'evergrove', 'key' => 'member_safe', 'type' => 'boolean', 'value' => 'true'],
                ],
            ],
            'media' => $photo
                ? [[
                    'alt' => (string) $row['scent_name'].' Candle Club scent image',
                    'mediaContentType' => 'IMAGE',
                    'originalSource' => $photo['url'],
                ]]
                : [],
        ];

        $data = $this->shopifyQuery($shopify, $mutation, $variables);
        $errors = data_get($data, 'productCreate.userErrors', []);
        if (is_array($errors) && $errors !== []) {
            throw new RuntimeException('Shopify productCreate failed: '.collect($errors)->pluck('message')->implode(' | '));
        }

        $product = data_get($data, 'productCreate.product');
        if (! is_array($product) || empty($product['id'])) {
            throw new RuntimeException('Shopify productCreate did not return a product.');
        }

        return [
            'id' => $product['id'] ?? null,
            'title' => $product['title'] ?? null,
            'handle' => $product['handle'] ?? null,
            'status' => $product['status'] ?? 'DRAFT',
            'image_url' => data_get($product, 'featuredImage.url'),
        ];
    }

    /**
     * @param  array<string,mixed>  $variables
     * @return array<string,mixed>
     */
    protected function shopifyQuery(object $shopify, string $query, array $variables = []): array
    {
        if ($shopify instanceof ShopifyCliAdminClient) {
            $shopDomain = trim((string) $this->resolvedShopDomain);
            if ($shopDomain === '') {
                throw new RuntimeException('Shopify CLI fallback needs a configured shop domain.');
            }

            return $shopify->query($shopDomain, $query, $variables);
        }

        if ($shopify instanceof ShopifyGraphqlClient) {
            return $shopify->query($query, $variables);
        }

        throw new RuntimeException('Unsupported Shopify client.');
    }

    protected function photoFor(array $row): ?array
    {
        $query = $this->photoQuery($row);
        return app(FreeStockPhotoService::class)->firstMatch($query);
    }

    protected function photoQuery(array $row): string
    {
        $name = (string) $row['scent_name'];
        $normalized = strtolower($name);

        return match (true) {
            Str::contains($normalized, ['coffee', 'latte', 'chai']) => 'cozy coffee candle warm cafe',
            Str::contains($normalized, ['snow', 'christmas', 'garland', 'holiday']) => 'winter evergreen candle cozy',
            Str::contains($normalized, ['beach', 'coast', 'sea', 'waters', 'rain', 'mist', 'breeze']) => 'coastal candle ocean clean',
            Str::contains($normalized, ['rose', 'jasmine', 'honeysuckle', 'iris']) => 'floral candle soft flowers',
            Str::contains($normalized, ['apple', 'pumpkin', 'autumn', 'woodland', 'cedar']) => 'autumn candle woods cozy',
            default => $name.' candle scent lifestyle',
        };
    }

    protected function positiveInt(mixed $value): ?int
    {
        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function recipeRows(): array
    {
        $rawRows = [
            ['2021-10', 'Rose Champagne', 'Love Spell (1)', 'Rose Petal Gelato (1)', '', 'Order a 5lb jug of whatever oil is being used for candle club scent if the oil is the only scent being used. If the scent is split between two oils, order accordingly.'],
            ['2021-11', 'Bamboo Rainforest', 'Himalayan Bamboo', '', '', ''],
            ['2021-12', 'Sweet Oakmoss', 'Cedarwood Blanc', 'Spiced Honey and Tonka', '', '26 grams of oil per 16oz'],
            ['2022-01', 'Sparkling Citrus', 'Sparkling Grapefruit', 'Blood Orange', '', '14 grams of oil per 8oz'],
            ['2022-02', 'Cherry Merlot', 'Black Cherry Merlot', '', 'CM', '5 grams of oil per 4oz and wax melt'],
            ['2022-03', 'Honey Amber', 'Frankincense and Myrrh', 'Spiced Honey and Tonka', '', '45'],
            ['2022-04', 'Mossy Trail', 'Mediterranean Fig', '', 'MT', '1 oz is equal to 28.5 grams'],
            ['2022-05', 'Coastal Breeze', 'Hibiscus Palm', '', 'BZ', '78'],
            ['2022-06', 'Raspberry Sangria', 'Red Sangria', '', 'RS', '42'],
            ['2022-07', 'Herbal Tea', 'Green Tea and Lemongrass', 'White Tea', 'HT', '15 / 15 / 30'],
            ['2022-08', 'Holiday at Sea', 'Sea Salt and Orchid', '', 'HAS', ''],
            ['2022-09', 'Nightfall', 'Peppercorn Pomander', 'Black Sea', 'NF', ''],
            ['2022-10', "Papa's Pipe", 'Cedarwood Blanc', 'Spiced Honey and Tonka', 'PP', ''],
            ['2022-11', 'Pumpkin Chai', 'Toasted Pumpkin Spice', 'Cinnamon and Vanilla', 'PKCH', ''],
            ['2022-12', 'Midnight Embers', 'Black Amber and Plum', 'Peppermint and Eucalyptus', 'ME', ''],
            ['2023-01', 'Snow Day', 'Nordic Night (2)', 'Fireside (1)', '', ''],
            ['2023-02', 'Wanderlust', 'Lavender', 'Patchouli', 'WL', ''],
            ['2023-03', 'Golden Hour', 'Sweet Tobacco', 'Very Vanilla', 'GH', ''],
            ['2023-04', 'Thundershowers', 'Rain Water', '', 'TS', ''],
            ['2023-05', 'Modern Margarita', 'Citrus Agave', 'Sweet Orange and Sriracha', 'MM', ''],
            ['2023-06', 'Campfire', 'Fireside', '', 'CF', ''],
            ['2023-07', 'White Tea', 'White Tea', '', 'WT', ''],
            ['2023-08', 'Beach Day', 'Sea Minerals', '', 'BD', ''],
            ['2023-09', 'Pumpkin Spice Latte', 'Pumpkin Spice Buttercream', 'Coffeeshop', 'PSL', ''],
            ['2023-10', "Apple Pickin'", 'Macintosh Apple', 'Fallen Leaves', 'AP', ''],
            ['2023-11', 'Pomegranate Sage', 'Pomegranate Cider', 'White Sage and Lavender', 'Pom Sage', ''],
            ['2023-12', 'All is Calm', 'Nordic Night', 'Very Vanilla', '', ''],
            ['2024-01', 'Berries and Bubbly', 'Cranberry Prosecco', '', 'BB', ''],
            ['2024-02', 'Quiet Mountain', 'Lavender', 'Blue Spruce', 'QM', ''],
            ['2024-03', 'Jasmine Honeysuckle', 'Jasmine Honeysuckle', '', '', ''],
            ['2024-04', 'Rosemary Citrus', 'Red Sangria', 'Rosemary', '', ''],
            ['2024-05', 'Cypress Mist', 'Dry Gin and Cypress', '', 'CM', ''],
            ['2024-06', 'Summer Linen', 'Clean Cotton', '', 'SL', ''],
            ['2024-07', 'Coconut Glow', 'Toasted Coconut', 'Golden Hour', 'CGL', ''],
            ['2024-08', 'Clean Shaven', 'Fog and Fern', 'Black Currant Absinthe', '', ''],
            ['2024-09', 'Autumn Leaves', 'Autumn Leaves Blend', '', 'AL', ''],
            ['2024-10', 'Apple Ginger Fizz', 'Apple Ginger Spritz', '', '', ''],
            ['2024-11', 'Woodland Retreat', 'Woodland Snow', 'Fog and Fern', 'WR', ''],
            ['2024-12', 'Christmas Garland', 'Christmas Garland', '', 'CG', ''],
            ['2025-01', 'Snow Day', 'Nordic Night (2)', 'Fireside (1)', 'SD', ''],
            ['2025-02', 'Tranquil Waters', 'Lavender Driftwood', 'Sea Minerals', '', ''],
            ['2025-03', 'Citrus Splash', 'Lemon Verbena', 'Citrus Agave', '', ''],
            ['2025-04', 'Morning Mist', 'Baltic Dew', '', '', ''],
            ['2025-05', 'Golden Yuzu', 'Yuzu Blossom', '', 'GY', ''],
            ['2025-06', 'Cedar and Lavender', 'Rosemary Sage', 'Lavender', '', ''],
            ['2025-07', 'Smoky Mountain Berries', 'Black Currant Absinthe', 'Campfire Marshmallow', '', ''],
            ['2025-08', 'Laurel and Fig', 'Fig Tree', '', '', ''],
            ['2025-09', 'Autumn No. 9', 'Cashmere Pumpkin', 'Vanilla Cake Pop', '', ''],
            ['2025-10', 'Wild Thistle', 'Meadow Thistle', '', '', ''],
            ['2025-11', 'Poison Apple', 'Dark Orchard', 'Macintosh Apple', 'PA', ''],
            ['2025-12', 'White Christmas', 'Winter Chalet', '', '', ''],
            ['2026-01', 'Iris and Amber', 'White Orris and Sandalwood', '', '', ''],
            ['2026-02', 'Amber Fog', 'Black Sea', '', 'AF', ''],
            ['2026-03', 'Carolina Spring', 'Black Coral and Moss', '', '', ''],
            ['2026-04', 'Lava Rock', 'Citrus Agave', '', 'LR', ''],
            ['2026-05', 'Honeysuckle', 'Orange Blossom', '', 'HS', ''],
            ['2026-06', 'Coastal Calm', 'Azure Coast', '', '', ''],
        ];

        return array_map(function (array $row): array {
            [$period, $scentName, $oilOne, $oilTwo, $abbreviation, $notes] = $row;
            [$year, $month] = array_map('intval', explode('-', $period));

            return [
                'period' => $period,
                'year' => $year,
                'month' => $month,
                'scent_name' => trim((string) $scentName),
                'oils' => $this->parseOils([$oilOne, $oilTwo]),
                'abbreviation' => trim((string) $abbreviation),
                'notes' => trim((string) $notes),
            ];
        }, $rawRows);
    }

    /**
     * @param  array<int,string>  $values
     * @return array<int,array{name:string,parts:float}>
     */
    protected function parseOils(array $values): array
    {
        return collect($values)
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->map(function (string $value): array {
                $parts = 1.0;
                if (preg_match('/\(([\d.]+)\)\s*$/', $value, $match) === 1) {
                    $parts = (float) $match[1];
                    $value = trim((string) preg_replace('/\s*\([\d.]+\)\s*$/', '', $value));
                }

                return [
                    'name' => $this->normalizeOilName($value),
                    'parts' => $parts > 0 ? $parts : 1.0,
                ];
            })
            ->values()
            ->all();
    }

    protected function normalizeOilName(string $value): string
    {
        $value = Str::of($value)
            ->replace('&', 'and')
            ->squish()
            ->toString();

        return match (strtolower($value)) {
            'sparkling grapfruit' => 'Sparkling Grapefruit',
            'coffeshop' => 'Coffeeshop',
            'peppermint and eucaluptus' => 'Peppermint and Eucalyptus',
            default => Str::title($value),
        };
    }
}
