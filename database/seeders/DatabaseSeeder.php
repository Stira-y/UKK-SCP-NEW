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
            1 => 'Waka Kurikulum',
            2 => 'Guru',
            3 => 'BK & Walikelas',
            4 => 'Siswa'
        ];

        foreach ($roles as $id => $role) {
            Role::firstOrCreate(['id_role' => $id], ['name_role' => $role]);
        }

        // 2. Seed Master Kelas
        $classX = SchoolClass::firstOrCreate(['kelas_nomor' => 'X', 'nama_kelas' => 'RPL 1']);
        $classXI = SchoolClass::firstOrCreate(['kelas_nomor' => 'XI', 'nama_kelas' => 'RPL 1']);
        $classXII = SchoolClass::firstOrCreate(['kelas_nomor' => 'XII', 'nama_kelas' => 'RPL 1']);

        // 3. Seed Users
        User::updateOrCreate(
            ['username' => 'waka'],
            [
                'id_role'  => 1, 
                'fullname' => 'Waka Kurikulum Admin',
                'email'    => 'waka@school.id',
                'password' => Hash::make('Peh_1234'),
            ]
        );

        User::updateOrCreate(
            ['username' => 'siswa'],
            [
                'id_role'  => 4,
                'id_class' => $classX->id_class,
                'fullname' => 'siswanjay',
                'email'    => 'eve78080@gmail.com',
                'nis'      => '17756',
                'nisn'     => '12121',
                'password' => Hash::make('Peh_1234'),
            ]
        );

        User::updateOrCreate(
            ['username' => 'siswa1'],
            [
                'id_role'  => 4,
                'id_class' => $classXII->id_class,
                'fullname' => 'siswa_atu',
                'email'    => 'qw78080@gmail.com',
                'nis'      => '17757',
                'nisn'     => '12122',
                'password' => Hash::make('Peh_1234'),
            ]
        );

        User::updateOrCreate(
            ['username' => 'guru'],
            [
                'id_role'  => 2,
                'fullname' => 'guru12',
                'email'    => 'guru@gmail.com',
                'password' => Hash::make('Peh_1234'),
            ]
        );

        User::updateOrCreate(
            ['username' => 'walikelas'],
            [
                'id_role'  => 3,
                'fullname' => 'walikelas&bk',
                'email'    => 'wali@gmail.com',
                'password' => Hash::make('Peh_1234'),
            ]
        );
    }
}