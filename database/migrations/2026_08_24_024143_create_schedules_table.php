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
        Schema::create('schedules', function (Blueprint $table) {
            $table->id('id_schedule');
            $table->foreignId('id_guru')->constrained('users', 'id_user')->onDelete('cascade');
            $table->foreignId('id_class')->constrained('classes', 'id_class')->onDelete('cascade');
            $table->foreignId('id_room')->constrained('rooms', 'id_room')->onDelete('cascade');
            $table->string('mapel', 100);
            $table->enum('day_of_week', ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']);
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('cycle_type', ['1', '2']);
            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
