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
    Schema::create('leave_requests', function (Blueprint $table) {
        $table->id('id_izin_keluar');
        $table->foreignId('id_siswa')->constrained('users', 'id_user')->onDelete('cascade');
        $table->foreignId('id_pengaju')->constrained('users', 'id_user')->onDelete('cascade');
        $table->foreignId('verified_by')->nullable()->constrained('users', 'id_user')->onDelete('set null');
        $table->date('start_date');
        $table->date('end_date');
        $table->text('reason');
        $table->string('document_path', 255);
        $table->enum('status_approval', ['Disetujui', 'Pending', 'Ditolak'])->default('Pending');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
