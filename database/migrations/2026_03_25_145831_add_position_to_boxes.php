<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('boxes', function (Blueprint $table) {
            $table->string('nama_rak')->nullable();
            $table->string('nomor_rak')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('boxes', function (Blueprint $table) {
            $table->dropColumn(['nama_rak', 'nomor_rak']);
        });
    }
};