<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ArsipSp2d;
use Illuminate\Support\Facades\Log;
use App\Exports\Sp2dExport;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    public function exportExcel(Request $request) {
        $query = ArsipSp2d::query(); // Ganti 'Arsip' sesuai nama Model kamu

    // --- LOGIKA FILTER ---
    if ($request->kode_klas) $query->where('kode_klas', $request->kode_klas);
    if ($request->no_surat) $query->where('no_surat', 'like', "%{$request->no_surat}%");
    if ($request->tahun) $query->where('tahun', $request->tahun);
    if ($request->unit_pencipta) $query->where('unit_pencipta', $request->unit_pencipta);
    if ($request->no_box_sementara) $query->where('no_box_sementara', $request->no_box_sementara);
    if ($request->no_box_permanen) $query->where('no_box_permanen', $request->no_box_permanen);
    if ($request->kondisi) $query->where('kondisi', $request->kondisi);
    if ($request->nasib_akhir) $query->where('nasib_akhir', $request->nasib_akhir);
    if ($request->rekomendasi) $query->where('rekomendasi', $request->rekomendasi);

    // Filter Range Nominal
    if ($request->min_nominal) $query->where('nominal', '>=', $request->min_nominal);
    if ($request->max_nominal) $query->where('nominal', '<=', $request->max_nominal);

    return Excel::download(new Sp2dExport($query), 'Data_SP2D_Custom.xlsx');
}
}