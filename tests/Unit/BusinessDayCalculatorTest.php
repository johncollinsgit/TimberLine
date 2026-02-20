<?php

namespace Tests\Unit;

use App\Services\Shipping\BusinessDayCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class BusinessDayCalculatorTest extends TestCase
{
    public function testAddBusinessDaysSkipsWeekend(): void
    {
        $calc = new BusinessDayCalculator();
        $start = CarbonImmutable::parse('2026-02-20'); // Friday

        $result = $calc->addBusinessDays($start, 1);
        $this->assertSame('2026-02-23', $result->toDateString()); // Monday
    }

    public function testSubBusinessDaysSkipsWeekend(): void
    {
        $calc = new BusinessDayCalculator();
        $start = CarbonImmutable::parse('2026-02-23'); // Monday

        $result = $calc->subBusinessDays($start, 1);
        $this->assertSame('2026-02-20', $result->toDateString()); // Friday
    }

    public function testAddAndSubAreSymmetric(): void
    {
        $calc = new BusinessDayCalculator();
        $start = CarbonImmutable::parse('2026-02-18'); // Wednesday

        $forward = $calc->addBusinessDays($start, 4);
        $back = $calc->subBusinessDays($forward, 4);

        $this->assertSame($start->toDateString(), $back->toDateString());
    }
}
