<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArsipSp2d extends Model
{
    protected $fillable = [
        'kode_klas',
        'no_surat',
        'keperluan',
        'tahun',
        'jumlah', // 🔥 Tambahan baru
        'nominal',
        'jra_aktif',
        'jra_inaktif',
        'unit_pencipta',
        'no_box_sementara',
        'no_box_permanen',
        'tingkat_pengembangan',
        'kondisi',
        'nasib_akhir',
        'rekomendasi',
        'keterangan',
        'file_dokumen',
        'terlampir'
    ];
}