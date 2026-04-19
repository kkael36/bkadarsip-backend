<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Box;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB; // 🔥 Wajib ada
use Illuminate\Support\Facades\Log; // 🔥 Wajib ada
use Illuminate\Support\Facades\Validator;

class BoxController extends Controller
{
    public function index()
    {
        return response()->json(Box::latest()->get());
    }

    public function denah()
    {
        return response()->json(
            Box::select(
                'id',
                'nomor_box',
                'nama_rak',
                'nomor_rak',
                'pos_x',
                'pos_y',
                'width',
                'height'
            )->get()
        );
    }

    public function show($id)
    {
        $box = Box::find($id);
        if (!$box) {
            return response()->json(['message' => 'Data Box tidak ditemukan'], 404);
        }
        return response()->json($box);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nomor_box' => 'required|string|max:255',
            'nama_rak' => 'required|string|max:255',
            'nomor_rak' => 'required|string|max:255',
            'tahun' => 'nullable|string|max:4',
            'kode_klasifikasi' => 'nullable|string|max:255',
            'keterangan' => 'nullable|string',
        ]);

        try {
            $box = Box::create($validated);
            return response()->json(['message' => 'Box baru berhasil ditambahkan!', 'data' => $box], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menyimpan', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $box = Box::find($id);
        if (!$box) {
            return response()->json(['message' => 'Data Box tidak ditemukan'], 404);
        }

        $box->update([
            'nomor_box' => $request->nomor_box,
            'tahun' => $request->tahun,
            'kode_klasifikasi' => $request->kode_klasifikasi,
            'nama_rak' => $request->nama_rak,
            'nomor_rak' => $request->nomor_rak,
            'keterangan' => $request->keterangan,
        ]);

        return response()->json(['message' => 'Box berhasil diperbarui', 'data' => $box]);
    }

    public function destroy($id)
    {
        Box::destroy($id);
        return response()->json(['message' => 'Data box berhasil dihapus']);
    }

    /**
     * 🔥 FUNGSI IMPORT EXCEL BOX
     */
    public function import(Request $request) 
    {
        if (!$request->has('data')) {
            return response()->json(['message' => 'Payload data tidak ditemukan'], 400);
        }

        try {
            DB::beginTransaction();
            $count = 0;
            $skipped = 0;

            foreach ($request->data as $row) {
                // Skip jika nomor box kosong
                if (empty($row['nomor_box'])) continue;

                // Cek Duplikat berdasarkan nomor box
                $exists = Box::where('nomor_box', trim($row['nomor_box']))->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }

                // Proteksi Tahun agar tidak Error 500 (Tipe Integer di DB)
                $tahun = $row['tahun'];
                if ($tahun === '-' || !is_numeric($tahun)) {
                    $tahun = null; 
                }

                Box::create([
                    'nomor_box'        => trim($row['nomor_box']),
                    'tahun'            => $tahun,
                    'kode_klasifikasi' => ($row['kode_klasifikasi'] === '-') ? null : $row['kode_klasifikasi'],
                    'nama_rak'         => ($row['nama_rak'] === '-') ? null : $row['nama_rak'],
                    'nomor_rak'        => ($row['nomor_rak'] === '-') ? null : $row['nomor_rak'],
                    'keterangan'       => ($row['keterangan'] === '-') ? null : $row['keterangan'],
                ]);
                $count++;
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => "Impor Selesai! $count data masuk" . ($skipped > 0 ? ", $skipped dilewati (duplikat)." : ".")
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("IMPORT BOX ERROR: " . $e->getMessage());
            return response()->json(['message' => 'Database error: ' . $e->getMessage()], 500);
        }
    }
}