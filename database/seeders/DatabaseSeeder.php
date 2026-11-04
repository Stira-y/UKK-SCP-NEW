<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\SchoolClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed 4 Roles utama
        $roles = [
            'Waka Kurikulum', // ID 1
            'Guru',           // ID 2
            'BK & Walikelas', // ID 3
            'Siswa'           // ID 4
        ];

        foreach ($roles as $role) {
            Role::create(['name_role' => $role]);
        }

        // 2. Seed Master Kelas
        $classX = SchoolClass::create(['kelas_nomor' => 'X', 'nama_kelas' => 'RPL 1']);
        $classXI = SchoolClass::create(['kelas_nomor' => 'XI', 'nama_kelas' => 'RPL 1']);
        $classXII = SchoolClass::create(['kelas_nomor' => 'XII', 'nama_kelas' => 'RPL 1']);

        // 3. Seed Users
        User::create([
            'id_role'  => 1, 
            'username' => 'waka',
            'fullname' => 'Waka Kurikulum Admin',
            'email'    => 'waka@school.id',
            'password' => Hash::make('Peh_1234'),
        ]);

        User::create([
            'id_role'  => 4,
            'id_class' => $classX->id_class, // Dimasukkan ke kelas X
            'username' => 'siswa',
            'fullname' => 'siswanjay',
            'email'    => 'eve78080@gmail.com',
            'nis'      => '17756',
            'nisn'     => '12121',
            'password' => Hash::make('Peh_1234'),
        ]);

        User::create([
            'id_role'  => 4,
            'id_class' => $classXII->id_class, // Dimasukkan ke kelas XII
            'username' => 'siswa1',
            'fullname' => 'siswa_atu',
            'email'    => 'qw78080@gmail.com',
            'nis'      => '17757',
            'nisn'     => '12122',
            'password' => Hash::make('Peh_1234'),
        ]);

        User::create([
            'id_role'  => 2,
            'username' => 'guru',
            'fullname' => 'guru12',
            'email'    => 'guru@gmail.com',
            'password' => Hash::make('Peh_1234'),
        ]);

        User::create([
            'id_role'  => 3,
            'username' => 'walikelas',
            'fullname' => 'walikelas&bk',
            'email'    => 'wali@gmail.com',
            'password' => Hash::make('Peh_1234'),
        ]);
    }
}