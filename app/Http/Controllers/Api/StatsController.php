<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArsipSp2d; // Pastikan nama model sesuai
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index()
    {
        // 1. Hitung Total Arsip
        $totalArsip = ArsipSp2d::count();

        // 2. Hitung Arsip Masuk Bulan Ini
        $arsipBulanIni = ArsipSp2d::whereMonth('created_at', Carbon::now()->month)->count();

        // 3. Hitung Total Nominal (Uang)
        $totalNominal = ArsipSp2d::sum('nominal');

        // 4. Hitung Total Pegawai (Khusus Admin nanti yang lihat)
        $totalPegawai = User::count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_arsip' => $totalArsip,
                'arsip_bulan_ini' => $arsipBulanIni,
                'total_nominal' => $totalNominal,
                'total_pegawai' => $totalPegawai,
            ]
        ]);
    }
}