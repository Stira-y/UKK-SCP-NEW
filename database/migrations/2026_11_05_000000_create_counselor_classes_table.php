<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counselor_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_user')->constrained('users', 'id_user')->onDelete('cascade');
            $table->foreignId('id_class')->constrained('classes', 'id_class')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['id_user', 'id_class']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counselor_classes');
    }
};