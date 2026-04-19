<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('arsip_sp2ds', function (Blueprint $table) {
            // Menambahkan kolom jumlah setelah kolom tahun
            $table->string('jumlah')->nullable()->after('tahun');
        });
    }

    public function down()
    {
        Schema::table('arsip_sp2ds', function (Blueprint $table) {
            $table->dropColumn('jumlah');
        });
    }
};