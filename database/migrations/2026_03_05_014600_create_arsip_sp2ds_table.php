<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arsip_sp2ds', function (Blueprint $table) {
            $table->id();

            // hasil OCR / AI
            $table->string('kode_klas')->nullable();
            $table->string('no_surat')->nullable();
            $table->text('keperluan')->nullable();
            $table->year('tahun')->nullable();
            $table->bigInteger('nominal')->nullable();

            // JRA
            $table->integer('jra_aktif')->default(2);
            $table->integer('jra_inaktif')->default(5);

            // input manual
            $table->string('unit_pencipta')->nullable();
            $table->string('no_box_sementara')->nullable();
            $table->string('no_box_permanen')->nullable();
            $table->string('tingkat_pengembangan')->nullable();
            $table->string('kondisi')->nullable();
            $table->string('nasib_akhir')->nullable();
            $table->string('rekomendasi')->nullable();
            $table->text('keterangan')->nullable();

            // lampiran file
            $table->string('file_dokumen')->nullable();
            $table->string('terlampir')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arsip_sp2ds');
    }
};