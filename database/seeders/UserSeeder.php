<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    // database/seeders/UserSeeder.php
public function run(): void
{
    \App\Models\User::create([
        'name' => 'Super Admin Bogor',
        'email' => 'admin@bogor.go.id',
        'password' => bcrypt('password123'),
        'role' => 'super_admin',
    ]);
}
}
