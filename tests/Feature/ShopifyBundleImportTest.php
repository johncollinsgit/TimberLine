<?php

namespace Tests\Feature;

use App\Models\OrderLine;
use App\Models\MappingException;
use App\Models\Scent;
use App\Models\Size;
use App\Models\ShopifyImportException;
use App\Services\Shopify\ShopifyOrderIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyBundleImportTest extends TestCase
{
    use RefreshDatabase;

    public function testBundleExpandsIntoMultipleLines(): void
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
                    ],
                ],
            ],
        ];

        $ingestor = app(ShopifyOrderIngestor::class);
        $store = ['key' => 'retail', 'source' => 'shopify_retail'];
        $ingestor->ingest($store, $orderData);

        $lines = OrderLine::query()->get();
        $this->assertCount(2, $lines);
        $this->assertSame($river->id, $lines[0]->scent_id);
        $this->assertSame($pumpkin->id, $lines[1]->scent_id);
        $this->assertNotNull($lines[0]->size_id);
        $this->assertNotNull($lines[1]->size_id);
        $this->assertNotNull($lines[0]->external_key);
    }

    public function testBundleMissingScentCreatesImportException(): void
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
    }

    public function testReimportDoesNotDuplicateBundleLines(): void
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
                    ],
                ],
            ],
        ];

        $ingestor = app(ShopifyOrderIngestor::class);
        $store = ['key' => 'retail', 'source' => 'shopify_retail'];
        $ingestor->ingest($store, $orderData);
        $ingestor->ingest($store, $orderData);

        $this->assertSame(2, OrderLine::query()->count());
    }

    public function testSaleCandlesUsesVariantAsScentSourceForWholesaleImport(): void
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

    public function testCustomScentUsesVariantAsScentSourceForWholesaleImport(): void
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
