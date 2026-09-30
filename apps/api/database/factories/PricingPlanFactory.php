<?php

namespace Database\Factories;

use App\Domain\Pricing\Models\PricingPlan;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PricingPlan> */
class PricingPlanFactory extends Factory
{
    protected $model = PricingPlan::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => null,
            'name' => 'Standart',
            'type' => 'HOURLY',
            'price_per_hour' => 20000,
            'rounding_step' => 1000,
            'allowed_durations' => [30, 60, 90, 120],
            'is_active' => true,
        ];
    }
}
