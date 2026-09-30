<?php

namespace Database\Factories;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Billiard',
            'contact_name' => fake()->name(),
            'contact_phone' => '+99890'.fake()->numerify('#######'),
            'status_flag' => 'ACTIVE',
            'subscription_expires_at' => now()->addDays(30),
            'branch_limit' => 2,
            'timezone' => 'Asia/Tashkent',
        ];
    }

    public function expired(): static
    {
        return $this->state(['subscription_expires_at' => now()->subDay()]);
    }

    public function suspended(): static
    {
        return $this->state(['status_flag' => 'SUSPENDED']);
    }
}
