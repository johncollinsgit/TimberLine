<?php

namespace Tests\Unit;

use App\Support\Shopify\InfiniteOptionsParser;
use Tests\TestCase;

class InfiniteOptionsParserTest extends TestCase
{
    public function testMultipleScentFields(): void
    {
        $parser = new InfiniteOptionsParser();
        $line = [
            'properties' => [
                ['name' => 'Scent 1', 'value' => 'River Birch'],
                ['name' => 'Scent 2', 'value' => 'Pumpkin Chai'],
            ],
        ];

        $result = $parser->parseBundleSelections($line);
        $this->assertCount(2, $result);
        $this->assertSame('River Birch', $result[0]['scent_name']);
        $this->assertSame('Pumpkin Chai', $result[1]['scent_name']);
    }

    public function testCommaSeparatedScents(): void
    {
        $parser = new InfiniteOptionsParser();
        $line = [
            'properties' => [
                ['name' => 'Fragrance Choice', 'value' => 'Coconut Glow, Coffeehouse, Vanilla'],
            ],
        ];

        $result = $parser->parseBundleSelections($line);
        $this->assertCount(3, $result);
        $this->assertSame('Coconut Glow', $result[0]['scent_name']);
        $this->assertSame('Coffeehouse', $result[1]['scent_name']);
        $this->assertSame('Vanilla', $result[2]['scent_name']);
    }

    public function testIgnoresEmptyAndNone(): void
    {
        $parser = new InfiniteOptionsParser();
        $line = [
            'properties' => [
                ['name' => 'Scent', 'value' => 'None'],
                ['name' => 'Scent 2', 'value' => ''],
                ['name' => 'Fragrance', 'value' => 'Nightfall'],
            ],
        ];

        $result = $parser->parseBundleSelections($line);
        $this->assertCount(1, $result);
        $this->assertSame('Nightfall', $result[0]['scent_name']);
    }

    public function testAssociativeProperties(): void
    {
        $parser = new InfiniteOptionsParser();
        $line = [
            'properties' => [
                'Scent 1' => 'Forest Spice',
                'Scent 2' => 'Snow Day',
            ],
        ];

        $result = $parser->parseBundleSelections($line);
        $this->assertCount(2, $result);
        $this->assertSame('Forest Spice', $result[0]['scent_name']);
        $this->assertSame('Snow Day', $result[1]['scent_name']);
    }
}
