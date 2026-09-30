<?php

namespace Database\Factories;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'role' => 'CLIENT_OWNER',
            'name' => fake()->name(),
            'login' => 'u'.fake()->unique()->numerify('########'),
            'password' => 'password-Secret-123',
            'is_active' => true,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(['tenant_id' => null, 'role' => 'SUPER_ADMIN']);
    }

    public function role(string $role): static
    {
        return $this->state(['role' => $role]);
    }
}
