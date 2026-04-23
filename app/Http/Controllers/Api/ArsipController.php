<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ArsipSp2d;
use Illuminate\Support\Facades\{Log, Storage, Validator, DB};
use CURLFile;

class ArsipController extends Controller
{
    public function index() {
        return response()->json(ArsipSp2d::orderBy('created_at', 'desc')->get());
    }

    public function show($id) {
        return response()->json(["success" => true, "data" => ArsipSp2d::findOrFail($id)]);
    }

    public function destroyTemp(Request $request) {
        $url = $request->filename;
        if ($url) {
            $publicId = $this->extractPublicIdFromUrl($url);
            if ($publicId) {
                $this->deleteFromCloudinary($publicId);
                return response()->json([
                    "success" => true, 
                    "message" => "File temporer di Cloudinary berhasil dihapus."
                ]);
            }
        }
        return response()->json(["success" => false, "message" => "ID file tidak ditemukan."], 404);
    }

    public function store(Request $request) {
        $data = $request->all();
        if (!empty($data['nominal'])) {
            $data['nominal'] = preg_replace('/[^0-9]/', '', $data['nominal']);
        }
        $validator = Validator::make($data, [
            'kode_klas' => 'required|string',
            'no_surat' => 'required|string',
            'keperluan' => 'required|string',
            'tahun' => 'required|numeric|digits:4',
            'nominal' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $arsip = ArsipSp2d::create($data);
        return response()->json(["success" => true, "data" => $arsip]);
    }

    // 🔥 FUNCTION UPDATE TETAP ADA (Gak Gue Hapus!)
    public function update(Request $request, $id) {
        $arsip = ArsipSp2d::findOrFail($id);
        $data = $request->all();

        if (!empty($data['nominal'])) {
            $data['nominal'] = preg_replace('/[^0-9]/', '', $data['nominal']);
        }

        $validator = Validator::make($data, [
            'kode_klas' => 'required|string',
            'no_surat' => 'required|string',
            'keperluan' => 'required|string',
            'tahun' => 'required|numeric|digits:4',
            'nominal' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $arsip->update($data);
        return response()->json(["success" => true, "data" => $arsip, "message" => "Arsip berhasil diperbarui"]);
    }

    public function upload(Request $request)
    {
        // 🔥 ANTI TIMEOUT & BOOST MEMORY
        set_time_limit(0);
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '512M');

        try {
            Log::info("=== UPLOAD FUNCTION STARTED ===");
            
            if (!$request->hasFile('file')) {
                Log::error("File tidak ditemukan dalam request");
                return response()->json(["success" => false, "error" => "File tidak ditemukan"], 400);
            }

            $file = $request->file('file');
            $realPath = $file->getRealPath();
            Log::info("File diterima: " . $file->getClientOriginalName());

            // 1. Upload ke Cloudinary (PAKE ENV RAILWAY)
            $cloudName = env('CLOUDINARY_CLOUD_NAME');
            $apiKey    = env('CLOUDINARY_API_KEY');
            $apiSecret = env('CLOUDINARY_API_SECRET');
            $folder    = 'sp2d_arsip';
            $timestamp = time();
            $signature = sha1("folder={$folder}&timestamp={$timestamp}" . $apiSecret);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120); 
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'file'      => new CURLFile($realPath, $file->getMimeType(), $file->getClientOriginalName()),
                'api_key'   => $apiKey,
                'timestamp' => $timestamp,
                'folder'    => $folder,
                'signature' => $signature,
            ]);
            
            $resCloud = curl_exec($ch);
            $httpCloud = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCloud != 200) {
                $error = json_decode($resCloud, true);
                throw new \Exception('Cloudinary Error: ' . ($error['error']['message'] ?? 'Upload failed'));
            }
            
            $dataCloud = json_decode($resCloud, true);
            $secureUrl = $dataCloud['secure_url'];

            // 2. OCR dengan endpoint PUBLIC (TIMEOUT 3 MENIT)
            $textMentah = $this->doOCRWithPublicEndpoint($realPath);
            
            if (empty($textMentah)) {
                return response()->json([
                    "success" => true,
                    "file_dokumen" => $secureUrl,
                    "kode_klas" => "", "no_surat" => "", "tahun" => "", "nominal" => "", "keperluan" => "",
                    "raw_ocr" => "",
                    "warning" => "OCR tidak dapat membaca teks."
                ]);
            }

            // 3. Parsing Data (LOGIKA REGEX SAKTI)
            $parsed = $this->parseDataDariFullText($textMentah);

            return response()->json([
                "success"       => true,
                "file_dokumen"  => $secureUrl,
                "kode_klas"     => $parsed['kode_klas'],
                "no_surat"      => $parsed['no_surat'],
                "tahun"         => $parsed['tahun'],
                "nominal"       => $parsed['nominal'],
                "keperluan"     => $parsed['keperluan'],
                "raw_ocr"       => $textMentah
            ]);

        } catch (\Exception $e) {
            Log::error("UPLOAD ERROR: " . $e->getMessage());
            return response()->json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    private function doOCRWithPublicEndpoint($imagePath)
    {
        $url = "https://cartyspaceship-ocr.hf.space/ocr";
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 180); 
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new \CURLFile($imagePath)]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $decoded = json_decode($response, true);
                return $decoded['teks'] ?? $decoded['text'] ?? $decoded['result'] ?? "";
            }
            return "";
        } catch (\Exception $e) { return ""; }
    }

    private function parseDataDariFullText($text)
    {
        $res = ["kode_klas" => "", "no_surat" => "", "tahun" => "", "keperluan" => "", "nominal" => ""];
        if (empty($text)) return $res;

        // Normalisasi: Hapus spasi ganda, jadikan satu baris panjang
        $cleanText = preg_replace('/\s+/', ' ', $text);

        // 1. REGEX NO SURAT & KODE KLAS (Kebal salah baca simbol /)
        // Mencari pola 000/000000/AA/2013 meskipun dibaca 000 I 000000 | AA 2013
        // Pola: Digits - Separator - Digits - Separator - Letters - Separator - Year
        if (preg_match('/(\d{3,})[\s\/|I1l-]{1,3}(\d{4,8})[\s\/|I1l-]{1,3}([A-Z0-9]{2,5})[\s\/|I1l-]{1,3}(\d{4})/i', $cleanText, $m)) {
            $res["kode_klas"] = $m[1]; // Ambil angka depan (misal: 931)
            $res["no_surat"] = "{$m[1]}/{$m[2]}/" . strtoupper($m[3]) . "/{$m[4]}";
            $res["tahun"] = $m[4];
        } else {
            // Backup jika pola lengkap gagal, cari tahun saja
            if (preg_match('/(20[0-9]{2})/', $cleanText, $m)) $res["tahun"] = $m[1];
        }

        // 2. REGEX KEPERLUAN (ANTI-BABLAS)
        // Ambil teks setelah "Keperluan" TAPI berhenti tepat sebelum kata "Kegiatan" atau typo-nya
        if (preg_match('/(?:KEPERLUAN|Keperluan)\s*[:;]?\s*(.*?)(?=\s*(?:Kegiatan|Keglotan|Kegiaton|Keglatan|Kegiatun|No\.|Nomor|Rp|JUMLAH|Uang|$))/is', $cleanText, $m)) {
            $res["keperluan"] = trim($m[1]);
        }

        // 3. REGEX NOMINAL (SPESIFIK TOTAL PALING BAWAH)
        // Cari angka nominal yang muncul setelah label "Jumlah Rp" (Biasanya total ada di paling akhir)
        if (preg_match_all('/(?:Jumlah|JUMLAH)\s*(?:Rp\.?|RP\.?)\s*([\d\.,]{5,})/i', $cleanText, $matches)) {
            $lastMatch = end($matches[1]); // Ambil match terakhir (Total Penjumlahan)
            $res["nominal"] = preg_replace('/[^0-9]/', '', $lastMatch);
        }

        return $res;
    }

    private function extractPublicIdFromUrl($url) {
        preg_match('/\/upload\/v\d+\/(.+)\.(jpg|jpeg|png|gif|webp)/', $url, $matches);
        return $matches[1] ?? null;
    }

    private function deleteFromCloudinary($publicId) {
        $cloudName = env('CLOUDINARY_CLOUD_NAME');
        $apiKey    = env('CLOUDINARY_API_KEY');
        $apiSecret = env('CLOUDINARY_API_SECRET');
        $timestamp = time();
        $signature = sha1("public_id={$publicId}&timestamp={$timestamp}" . $apiSecret);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$cloudName}/image/destroy");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'public_id' => $publicId,
            'api_key'   => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}