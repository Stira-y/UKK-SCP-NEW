<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Schedule;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement('ALTER TABLE schedules MODIFY id_guru BIGINT UNSIGNED NULL');
        } catch (\Throwable $e) {
            // Ignore if already nullable
        }

        try {
            $dummyIds = User::where('id_role', 2)
                ->where(function ($q) {
                    $q->whereNull('nip')
                      ->orWhere('nip', '')
                      ->orWhere('nip', '-');
                })
                ->where(function ($q) {
                    $q->where('email', 'like', '%@school.id')
                      ->orWhere('username', 'like', 'guru_%')
                      ->orWhere('title', 'Guru')
                      ->orWhereNull('title')
                      ->orWhere('fullname', '-');
                })
                ->pluck('id_user');

            if ($dummyIds->count() > 0) {
                Schedule::whereIn('id_guru', $dummyIds)->delete();
                User::whereIn('id_user', $dummyIds)->delete();
            }
        } catch (\Throwable $e) {
            // Ignore
        }
    }

    public function down(): void
    {
        //
    }
};
