<?php

namespace Database\Factories;

use App\Domain\Branches\Models\Branch;
use App\Domain\Tables\Models\BilliardTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BilliardTable> */
class BilliardTableFactory extends Factory
{
    protected $model = BilliardTable::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'tenant_id' => fn (array $attributes) => Branch::withoutGlobalScopes()->findOrFail($attributes['branch_id'])->tenant_id,
            'number' => fake()->unique()->numberBetween(1, 5000),
            'name' => fn (array $attributes) => $attributes['number'].'-stol',
            'is_active' => true,
        ];
    }
}
