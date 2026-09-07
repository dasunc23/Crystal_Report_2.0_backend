<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the application's database seeders.
     */
    public function run(): void
    {
        $this->call([
            // Creates the single fixed login account.
            AdminUserSeeder::class,
        ]);
    }
}
