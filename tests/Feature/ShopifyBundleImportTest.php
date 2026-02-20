<?php

namespace Tests\Feature;

use App\Models\OrderLine;
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
        Size::query()->create(['code' => '8oz-cotton', 'label' => '8oz Cotton Wick', 'is_active' => true]);
        Size::query()->create(['code' => 'wax-melts', 'label' => 'Wax Melts', 'is_active' => true]);

        $river = Scent::query()->create(['name' => 'River Birch', 'display_name' => 'River Birch', 'is_active' => true]);
        $pumpkin = Scent::query()->create(['name' => 'Pumpkin Chai', 'display_name' => 'Pumpkin Chai', 'is_active' => true]);

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
        Size::query()->create(['code' => '8oz-cotton', 'label' => '8oz Cotton Wick', 'is_active' => true]);

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
        Size::query()->create(['code' => '8oz-cotton', 'label' => '8oz Cotton Wick', 'is_active' => true]);
        Scent::query()->create(['name' => 'River Birch', 'display_name' => 'River Birch', 'is_active' => true]);
        Scent::query()->create(['name' => 'Pumpkin Chai', 'display_name' => 'Pumpkin Chai', 'is_active' => true]);

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
}
