<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'no_telp')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('no_telp', 30)->nullable()->after('username');
            });
        }

        if (!Schema::hasTable('parent_student')) {
            Schema::create('parent_student', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_id')->constrained('users', 'id_user')->onDelete('cascade');
                $table->foreignId('student_id')->constrained('users', 'id_user')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['parent_id', 'student_id']);
            });
        }

        try {
            DB::table('users')
                ->where('username', 'waka')
                ->update(['email' => 'anjasmc78080@gmail.com']);
        } catch (\Throwable $e) {
            // Ignore if table not seeded yet
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_student');

        if (Schema::hasColumn('users', 'no_telp')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('no_telp');
            });
        }
    }
};
