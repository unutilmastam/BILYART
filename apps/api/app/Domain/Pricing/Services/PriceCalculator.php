<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Enums\PricingType;
use App\Domain\Pricing\Models\PricingPlan;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;

/**
 * Integer-only price maths (UZS). HOURLY: amount = ceil(pricePerHour × minutes / 60),
 * then rounded *up* to the plan's rounding step. New pricing models add a case here.
 */
final class PriceCalculator
{
    public function quote(PricingPlan $plan, int $minutes): int
    {
        if ($minutes < 1) {
            throw ApiException::of(ErrorCode::DURATION_NOT_ALLOWED);
        }

        return match ($plan->type) {
            PricingType::HOURLY => self::hourly($plan->price_per_hour, $minutes, $plan->rounding_step),
        };
    }

    public static function hourly(int $pricePerHour, int $minutes, int $roundingStep): int
    {
        $raw = intdiv($pricePerHour * $minutes + 59, 60);
        $step = max(1, $roundingStep);

        return intdiv($raw + $step - 1, $step) * $step;
    }

    /** @return list<array{minutes: int, amount: int}> */
    public function quotes(PricingPlan $plan): array
    {
        $durations = array_values(array_unique(array_map('intval', $plan->allowed_durations ?? [])));
        sort($durations);

        return array_map(fn (int $m) => ['minutes' => $m, 'amount' => $this->quote($plan, $m)], $durations);
    }
}
