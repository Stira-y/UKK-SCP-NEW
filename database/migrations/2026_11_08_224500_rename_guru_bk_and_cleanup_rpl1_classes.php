<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ubah seluruh title 'Guru BK Pengajar' menjadi 'Guru BK'
        if (Schema::hasTable('users')) {
            DB::table('users')
                ->whereRaw("LOWER(REPLACE(TRIM(COALESCE(title, '')), ' ', '')) = 'gurubkpengajar'")
                ->update(['title' => 'Guru BK']);

            // Pastikan Guru BK yang memiliki id_class tunggal di tabel users juga tersinkron ke counselor_classes
            if (Schema::hasTable('counselor_classes')) {
                $bkTeachers = DB::table('users')
                    ->where('id_role', 3)
                    ->whereRaw("LOWER(REPLACE(TRIM(COALESCE(title, '')), ' ', '')) = 'gurubk'")
                    ->whereNotNull('id_class')
                    ->get();

                foreach ($bkTeachers as $bk) {
                    $rawIds = array_filter(array_map('trim', explode(',', (string) $bk->id_class)));
                    foreach ($rawIds as $cid) {
                        if (is_numeric($cid)) {
                            $exists = DB::table('counselor_classes')
                                ->where('id_user', $bk->id_user)
                                ->where('id_class', (int) $cid)
                                ->exists();
                            if (!$exists) {
                                DB::table('counselor_classes')->insert([
                                    'id_user' => $bk->id_user,
                                    'id_class' => (int) $cid,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }
                }
            }
        }

        // 2. Hapus data kelas salah: Kelas Nomor (X, XI, XII), Nama Kelas (RPL 1), Nomor Kelas (- / null)
        if (Schema::hasTable('classes')) {
            $badClasses = DB::table('classes')
                ->whereIn(DB::raw("UPPER(TRIM(nama_kelas))"), ['RPL 1', 'RPL1'])
                ->get();

            foreach ($badClasses as $bad) {
                // Cari kelas RPL nomor 1 yang benar pada tingkat yang sama
                $goodClass = DB::table('classes')
                    ->where('kelas_nomor', $bad->kelas_nomor)
                    ->whereRaw("UPPER(TRIM(nama_kelas)) = 'RPL'")
                    ->where('nomor_kelas', '1')
                    ->first();

                $replacementId = $goodClass ? $goodClass->id_class : null;

                if (Schema::hasTable('users')) {
                    DB::table('users')
                        ->where('id_class', $bad->id_class)
                        ->update(['id_class' => $replacementId]);
                }

                if (Schema::hasTable('schedules')) {
                    if ($replacementId) {
                        DB::table('schedules')
                            ->where('id_class', $bad->id_class)
                            ->update(['id_class' => $replacementId]);
                    } else {
                        DB::table('schedules')
                            ->where('id_class', $bad->id_class)
                            ->delete();
                    }
                }

                if (Schema::hasTable('counselor_classes')) {
                    DB::table('counselor_classes')
                        ->where('id_class', $bad->id_class)
                        ->delete();
                }

                DB::table('classes')->where('id_class', $bad->id_class)->delete();
            }
        }
    }

    public function down(): void
    {
        //
    }
};

