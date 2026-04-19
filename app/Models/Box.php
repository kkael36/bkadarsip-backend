<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Box extends Model
{
    protected $fillable = [
        'nomor_box',
        'kode_klasifikasi',
        'tahun',
        'nama_rak',
        'nomor_rak',
        'keterangan',
    ];
}