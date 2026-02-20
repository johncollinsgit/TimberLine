<?php

namespace Tests\Unit;

use App\Services\Pouring\MeasurementResolver;
use Tests\TestCase;

class MeasurementResolverCanonicalReferenceTest extends TestCase
{
    public function testUsesExactCanonicalRowForListedQuantity(): void
    {
        $resolver = new MeasurementResolver();

        $ingredients = $resolver->resolveLineIngredients('16oz', 3);

        $this->assertNotNull($ingredients);
        $this->assertSame(1072.0, $ingredients['wax_grams']);
        $this->assertSame(68.0, $ingredients['oil_grams']);
        $this->assertSame(1140.0, $ingredients['total_grams']);
    }

    public function testScalesFromOneUnitWhenQuantityNotListed(): void
    {
        $resolver = new MeasurementResolver();

        $ingredients = $resolver->resolveLineIngredients('16oz', 11);

        $this->assertNotNull($ingredients);
        $this->assertSame(3927.0, $ingredients['wax_grams']);
        $this->assertSame(253.0, $ingredients['oil_grams']);
        $this->assertSame(4180.0, $ingredients['total_grams']);
    }

    public function testUsesCanonicalRoomSprayRowsAndFallbackScaling(): void
    {
        $resolver = new MeasurementResolver();

        $listed = $resolver->resolveLineIngredients('room spray', 3);
        $scaled = $resolver->resolveLineIngredients('room spray', 5);

        $this->assertNotNull($listed);
        $this->assertSame(84.0, $listed['alcohol_grams']);
        $this->assertSame(10.0, $listed['oil_grams']);
        $this->assertSame(245.0, $listed['water_grams']);
        $this->assertSame(339.0, $listed['total_grams']);

        $this->assertNotNull($scaled);
        $this->assertSame(140.0, $scaled['alcohol_grams']);
        $this->assertSame(15.0, $scaled['oil_grams']);
        $this->assertSame(405.0, $scaled['water_grams']);
        $this->assertSame(560.0, $scaled['total_grams']);
    }
}
