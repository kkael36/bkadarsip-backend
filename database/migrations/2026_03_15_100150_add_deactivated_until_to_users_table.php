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
        Schema::table('users', function (Blueprint $table) {
            // Kita taruh kolomnya setelah kolom 'role' biar rapi di database
            $table->datetime('deactivated_until')->nullable()->after('role')->comment('Jika user dinonaktifkan sementara, simpan tanggal hingga kapan nonaktif');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_until');
        });
    }
};