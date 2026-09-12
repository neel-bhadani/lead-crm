<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * One admin and nothing else. Lead stages and sources are reference data
     * the app cannot boot without, and they are inserted by their own
     * migration, not here — so a production install starts empty.
     */
    public function run(): void
    {
        $this->call(AdminSeeder::class);
    }
}
