<?php

namespace Tests\Feature;

use App\Models\MappingException;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\ShopifyImportException;
use App\Models\Size;
use App\Services\Shopify\ShopifyOrderIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyBundleImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_bundle_expands_into_multiple_lines(): void
    {
        Size::query()->firstOrCreate(
            ['code' => '8oz-cotton'],
            ['label' => '8oz Cotton Wick', 'is_active' => true]
        );
        Size::query()->firstOrCreate(
            ['code' => 'wax-melts'],
            ['label' => 'Wax Melts', 'is_active' => true]
        );

        $river = Scent::query()->firstOrCreate(
            ['name' => 'River Birch'],
            ['display_name' => 'River Birch', 'is_active' => true]
        );
        $pumpkin = Scent::query()->firstOrCreate(
            ['name' => 'Pumpkin Chai'],
            ['display_name' => 'Pumpkin Chai', 'is_active' => true]
        );
        $oakmoss = Scent::query()->firstOrCreate(
            ['name' => 'Oakmoss Amber'],
            ['display_name' => 'Oakmoss Amber', 'is_active' => true]
        );

        $orderData = [
            'id' => 123,
            'name' => '#1001',
            'created_at' => '2026-02-10T10:00:00Z',
            'line_items' => [
                [
                    'id' => 777,
                    'title' => '3 Soy Candle Bundle',
                    'product_type' => 'Bundle',
                    'quantity' => 1,
                    'properties' => [
                        ['name' => 'Scent 1', 'value' => 'River Birch'],
                        ['name' => 'Scent 2', 'value' => 'Pumpkin Chai'],
                        ['name' => 'Scent 3', 'value' => 'Oakmoss Amber'],
                    ],
                ],
            ],
        ];

        $ingestor = app(ShopifyOrderIngestor::class);
        $store = ['key' => 'retail', 'source' => 'shopify_retail'];
        $ingestor->ingest($store, $orderData);

        $lines = OrderLine::query()->get();
        $this->assertCount(3, $lines);
        $this->assertSame($river->id, $lines[0]->scent_id);
        $this->assertSame($pumpkin->id, $lines[1]->scent_id);
        $this->assertSame($oakmoss->id, $lines[2]->scent_id);
        $this->assertNotNull($lines[0]->size_id);
        $this->assertNotNull($lines[1]->size_id);
        $this->assertNotNull($lines[0]->external_key);
    }

    public function test_every_everbranch_option_set_normalizes_into_individual_scent_and_size_lines(): void
    {
        $sizes = collect([
            '4oz-cotton' => '4oz Cotton Wick',
            '8oz-cotton' => '8oz Cotton Wick',
            '16oz-cotton' => '16oz Cotton Wick',
            'wax-melts' => 'Wax Melts',
            'room-sprays' => 'Room Sprays',
        ])->mapWithKeys(function (string $label, string $code): array {
            $size = Size::query()->firstOrCreate(
                ['code' => $code],
                ['label' => $label, 'is_active' => true]
            );

            return [$code => $size];
        });

        $scentNames = [
            'River Birch',
            'Pumpkin Chai',
            'Lavender',
            'Lava Rock',
            'White Tea',
        ];
        foreach ($scentNames as $name) {
            Scent::query()->firstOrCreate(
                ['name' => $name],
                ['display_name' => $name, 'is_active' => true]
            );
        }

        $cases = [
            [
                'title' => '3 (4oz) Soy Candle Bundle',
                'product_type' => 'Soy Candles',
                'variant_title' => 'Default Title',
                'count' => 3,
                'size' => '4oz-cotton',
            ],
            [
                'title' => '3 (8oz) Soy Candle Bundle',
                'product_type' => 'Soy Candles',
                'variant_title' => 'Cotton Wick',
                'count' => 3,
                'size' => '8oz-cotton',
            ],
            [
                'title' => 'Teacher Candles',
                'product_type' => 'Soy Candles',
                'variant_title' => '16oz Cotton Wick',
                'count' => 2,
                'size' => '16oz-cotton',
            ],
            [
                'title' => 'Teacher Candles',
                'product_type' => 'Soy Candles',
                'variant_title' => 'Wax Melt',
                'count' => 2,
                'size' => 'wax-melts',
            ],
            [
                'title' => '5 Wax Melts Bundle',
                'product_type' => 'Soy Candles',
                'variant_title' => 'Default Title',
                'count' => 5,
                'size' => 'wax-melts',
            ],
            [
                'title' => 'Three Room Sprays for $30',
                'product_type' => 'Room Sprays',
                'variant_title' => 'Default Title',
                'count' => 3,
                'size' => 'room-sprays',
            ],
        ];

        $ingestor = app(ShopifyOrderIngestor::class);
        foreach ($cases as $caseIndex => $case) {
            $properties = [];
            for ($slot = 1; $slot <= $case['count']; $slot++) {
                $properties[] = [
                    'name' => "Scent {$slot}",
                    'value' => $scentNames[$slot - 1],
                ];
            }

            $lines = $ingestor->mergeLineItems([[
                'id' => 9000 + $caseIndex,
                'title' => $case['title'],
                'product_type' => $case['product_type'],
                'variant_title' => $case['variant_title'],
                'quantity' => 1,
                'properties' => $properties,
            ]]);

            $this->assertCount($case['count'], $lines, $case['title'].' should create one line per scent.');
            $this->assertSame(
                array_slice($scentNames, 0, $case['count']),
                array_column($lines, 'title'),
                $case['title'].' should preserve each selected scent.'
            );
            $this->assertSame(
                array_fill(0, $case['count'], (int) $sizes[$case['size']]->id),
                array_map(static fn (array $line): int => (int) $line['size_id'], $lines),
                $case['title'].' should normalize every scent to the selected product size.'
            );
            $this->assertSame(
                array_fill(0, $case['count'], 1),
                array_column($lines, 'quantity'),
                $case['title'].' should create one unit per selection.'
            );
            $this->assertSame(0, ShopifyImportException::query()->count());
        }
    }

    public function test_bundle_missing_scent_creates_import_exception(): void
    {
        Size::query()->firstOrCreate(
            ['code' => '8oz-cotton'],
            ['label' => '8oz Cotton Wick', 'is_active' => true]
        );

        $orderData = [
            'id' => 456,
            'name' => '#1002',
            'created_at' => '2026-02-10T10:00:00Z',
            'line_items' => [
                [
                    'id' => 888,
                    'title' => '3 Soy Candle Bundle',
                    'product_type' => 'Bundle',
                    'quantity' => 1,
                    'properties' => [
                        ['name' => 'Scent 1', 'value' => 'Unknown Scent'],
                    ],
                ],
            ],
        ];

        $ingestor = app(ShopifyOrderIngestor::class);
        $store = ['key' => 'retail', 'source' => 'shopify_retail'];
        $ingestor->ingest($store, $orderData);

        $this->assertSame(0, OrderLine::query()->count());
        $this->assertSame(1, ShopifyImportException::query()->count());
        $this->assertSame('bundle_scent_count_mismatch', ShopifyImportException::query()->first()->reason);
    }

    public function test_reimport_does_not_duplicate_bundle_lines(): void
    {
        Size::query()->firstOrCreate(
            ['code' => '8oz-cotton'],
            ['label' => '8oz Cotton Wick', 'is_active' => true]
        );
        Scent::query()->firstOrCreate(
            ['name' => 'River Birch'],
            ['display_name' => 'River Birch', 'is_active' => true]
        );
        Scent::query()->firstOrCreate(
            ['name' => 'Pumpkin Chai'],
            ['display_name' => 'Pumpkin Chai', 'is_active' => true]
        );
        Scent::query()->firstOrCreate(
            ['name' => 'Oakmoss Amber'],
            ['display_name' => 'Oakmoss Amber', 'is_active' => true]
        );

        $orderData = [
            'id' => 789,
            'name' => '#1003',
            'created_at' => '2026-02-10T10:00:00Z',
            'line_items' => [
                [
                    'id' => 999,
                    'title' => '3 Soy Candle Bundle',
                    'product_type' => 'Bundle',
                    'quantity' => 1,
                    'properties' => [
                        ['name' => 'Scent 1', 'value' => 'River Birch'],
                        ['name' => 'Scent 2', 'value' => 'Pumpkin Chai'],
                        ['name' => 'Scent 3', 'value' => 'Oakmoss Amber'],
                    ],
                ],
            ],
        ];

        $ingestor = app(ShopifyOrderIngestor::class);
        $store = ['key' => 'retail', 'source' => 'shopify_retail'];
        $ingestor->ingest($store, $orderData);
        $ingestor->ingest($store, $orderData);

        $this->assertSame(3, OrderLine::query()->count());
    }

    public function test_sale_candles_uses_variant_as_scent_source_for_wholesale_import(): void
    {
        $size = Size::query()->firstOrCreate(
            ['code' => '8oz-cotton'],
            ['label' => '8oz Cotton Wick', 'is_active' => true]
        );
        $scent = Scent::query()->firstOrCreate(
            ['name' => "Sippin' Sunshine"],
            ['display_name' => "Sippin' Sunshine", 'is_active' => true]
        );

        $orderData = [
            'id' => 901,
            'name' => '#1004',
            'created_at' => '2026-02-10T10:00:00Z',
            'tags' => 'wholesale',
            'shipping_address' => [
                'company' => 'ERIN NUTZ',
            ],
            'line_items' => [
                [
                    'id' => 1001,
                    'title' => 'Sale Candles',
                    'variant_title' => "Sippin' Sunshine 8oz",
                    'quantity' => 2,
                    'sku' => null,
                    'product_type' => 'Wholesale',
                ],
            ],
        ];

        $ingestor = app(ShopifyOrderIngestor::class);
        $store = ['key' => 'wholesale', 'source' => 'shopify_wholesale'];
        $ingestor->ingest($store, $orderData);

        $line = OrderLine::query()->first();
        $this->assertNotNull($line);
        $this->assertSame((int) $scent->id, (int) $line->scent_id);
        $this->assertSame((int) $size->id, (int) $line->size_id);
        $this->assertSame('Sale Candles', (string) $line->raw_title);
        $this->assertSame("Sippin' Sunshine 8oz", (string) $line->raw_variant);
        $this->assertSame(0, MappingException::query()->count());
    }

    public function test_custom_scent_uses_variant_as_scent_source_for_wholesale_import(): void
    {
        $size = Size::query()->firstOrCreate(
            ['code' => '8oz-cotton'],
            ['label' => '8oz Cotton Wick', 'is_active' => true]
        );
        $scent = Scent::query()->firstOrCreate(
            ['name' => 'vintage amber'],
            ['display_name' => 'Vintage Amber', 'is_active' => true]
        );

        $orderData = [
            'id' => 902,
            'name' => '#1005',
            'created_at' => '2026-02-10T10:00:00Z',
            'tags' => 'wholesale',
            'shipping_address' => [
                'company' => 'ERIN NUTZ',
            ],
            'line_items' => [
                [
                    'id' => 1002,
                    'title' => 'Custom Scent',
                    'variant_title' => 'Vintage Amber 8oz',
                    'quantity' => 1,
                    'sku' => null,
                    'product_type' => 'Wholesale',
                ],
            ],
        ];

        $ingestor = app(ShopifyOrderIngestor::class);
        $store = ['key' => 'wholesale', 'source' => 'shopify_wholesale'];
        $ingestor->ingest($store, $orderData);

        $line = OrderLine::query()->first();
        $this->assertNotNull($line);
        $this->assertSame((int) $scent->id, (int) $line->scent_id);
        $this->assertSame((int) $size->id, (int) $line->size_id);
        $this->assertSame('Custom Scent', (string) $line->raw_title);
        $this->assertSame('Vintage Amber 8oz', (string) $line->raw_variant);
        $this->assertSame(0, MappingException::query()->count());
    }
}
