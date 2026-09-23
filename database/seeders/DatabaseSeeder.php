<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Roles sesuai enum
        $roles = [
            'Waka Kurikulum',
            'Kesiswaan',
            'BK & Walikelas',
            'Guru',
            'Siswa'
        ];

        foreach ($roles as $role) {
            Role::create(['name_role' => $role]);
        }

        // 2. Seed User Admin/Waka Awal untuk testing
        User::create([
            'id_role' => 1, // Waka Kurikulum
            'username' => 'waka_admin',
            'fullname' => 'Waka Kurikulum Admin',
            'email' => 'waka@school.id',
            'password' => Hash::make('password123'),
            'title' => 'Waka'
        ]);
    }
}