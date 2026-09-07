<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the single fixed user account
     * used to access the Crystal Report system.
     */
    public function run(): void
    {
        User::updateOrCreate(
            [
                'email' => 'admin@crystalreport.local',
            ],
            [
                'name' => 'System Administrator',

                // Laravel automatically hashes this password
                // because the User model uses the "hashed" cast.
                'password' => 'ChangeMe@123',
            ]
        );
    }
}
