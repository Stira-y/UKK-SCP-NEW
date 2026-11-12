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
        if (!Schema::hasColumn('users', 'mapel')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('mapel', 150)->nullable()->after('title');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'mapel')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('mapel');
            });
        }
    }
};
