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
    Schema::create('boxes', function (Blueprint $table) {
        $table->id();
        $table->string('nomor_box')->unique(); // Mendukung FA-947, 126, W-19
        $table->string('kode_klasifikasi')->nullable(); // Tambahan kode klasifikasi
        $table->year('tahun')->nullable(); // Sekarang bisa null
        $table->text('keterangan')->nullable();
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('boxes'); // Pastikan nama tabel jamak sesuai create
}

};
