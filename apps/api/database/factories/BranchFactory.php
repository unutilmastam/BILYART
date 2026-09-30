<?php

namespace Database\Factories;

use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->city().' filiali',
            'address' => fake()->streetAddress(),
            'timezone' => 'Asia/Tashkent',
            'is_active' => true,
        ];
    }
}
