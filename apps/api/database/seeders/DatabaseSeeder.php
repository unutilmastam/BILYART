<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Production-safe: only the platform bootstrap. DemoSeeder is never run in production. */
    public function run(): void
    {
        $this->call(PlatformSeeder::class);
    }
}
