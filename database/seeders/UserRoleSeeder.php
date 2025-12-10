<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        UserRole::create([
            'name' => 'admin',
        ]);

        UserRole::create([
            'name' => 'superAdmin',
        ]);

        UserRole::create([
            'name' => 'user',
        ]);
    }
}
