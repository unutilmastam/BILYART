<?php

namespace Tests\Unit\Pricing;

use App\Domain\Pricing\Services\PriceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PriceCalculatorTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'exact hour' => [20000, 60, 1000, 20000],
            'half hour' => [20000, 30, 1000, 10000],
            '10 minute test session' => [20000, 10, 1000, 4000],   // 3333.33 → 3334 → 4000
            'odd minutes' => [25000, 50, 1000, 21000],             // 20833.33 → 20834 → 21000
            'step 500' => [25000, 50, 500, 21000],                 // 20834 → 21000
            'step 1 (no rounding)' => [25000, 50, 1, 20834],
            'two hours' => [18000, 120, 1000, 36000],
            'free table' => [0, 60, 1000, 0],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function hourly_price_rounds_up_to_the_step(int $perHour, int $minutes, int $step, int $expected): void
    {
        $this->assertSame($expected, PriceCalculator::hourly($perHour, $minutes, $step));
    }
}
