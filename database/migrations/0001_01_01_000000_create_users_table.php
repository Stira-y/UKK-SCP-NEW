<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id('id_user');
            $table->foreignId('id_role')->constrained('roles', 'id_role')->onDelete('cascade');
            $table->foreignId('id_class')->nullable()->constrained('classes', 'id_class')->onDelete('set null');
            $table->string('username', 255)->unique();
            $table->string('password', 255);
            $table->string('fullname', 255);
            $table->string('email', 255);
            $table->string('nis', 20)->nullable();
            $table->string('nisn', 20)->nullable();
            $table->string('nip', 30)->nullable();
            $table->enum('title', ['Walikelas', 'Guru BK', 'Waka', 'Guru Biasa'])->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
