<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Hapus kelas salah bernama 'RPL 1' atau 'RPL1' (bukan nama kelas 'RPL')
        DB::table('classes')
            ->whereIn('nama_kelas', ['RPL 1', 'RPL1'])
            ->delete();
    }

    public function down(): void
    {
        // No-op rollback
    }
};

