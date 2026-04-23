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

            // 1. Upload ke Cloudinary (PAKE KONFIGURASI ENV PERSIS PROFILE)
            Log::info("Upload ke Cloudinary...");
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
            Log::info("Upload Cloudinary berhasil: " . $secureUrl);

            // 2. OCR dengan endpoint PUBLIC (TIMEOUT 3 MENIT)
            Log::info("Memulai OCR dengan endpoint public...");
            $textMentah = $this->doOCRWithPublicEndpoint($realPath);
            
            Log::info("Hasil OCR length: " . strlen($textMentah));

            if (empty($textMentah)) {
                Log::warning("OCR menghasilkan teks kosong");
                return response()->json([
                    "success" => true,
                    "file_dokumen" => $secureUrl,
                    "kode_klas" => "",
                    "no_surat" => "",
                    "tahun" => "",
                    "nominal" => "",
                    "keperluan" => "",
                    "raw_ocr" => "",
                    "warning" => "OCR tidak dapat membaca teks. Silakan input manual."
                ]);
            }

            // 3. Parsing Data (LOGIKA REGEX SAKTI v2)
            $parsed = $this->parseDataDariFullText($textMentah);
            Log::info("Hasil parsing: " . json_encode($parsed));

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
            Log::error("UPLOAD/OCR CRASH: " . $e->getMessage());
            return response()->json([
                "success" => false, 
                "error" => $e->getMessage()
            ], 500);
        }
    }

    private function doOCRWithPublicEndpoint($imagePath)
    {
        $url = "https://cartyspaceship-ocr.hf.space/ocr";
        
        try {
            if (!file_exists($imagePath)) {
                Log::error("File tidak ditemukan: " . $imagePath);
                return "";
            }
            
            $fileData = new \CURLFile($imagePath);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 180); 
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => $fileData]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                Log::error("CURL Error: " . $error);
                return "";
            }
            
            if ($httpCode === 200) {
                $decoded = json_decode($response, true);
                return $decoded['teks'] ?? $decoded['text'] ?? $decoded['result'] ?? "";
            }
            
            return "";
            
        } catch (\Exception $e) {
            Log::error("Public OCR Exception: " . $e->getMessage());
            return "";
        }
    }

    private function parseDataDariFullText($text)
    {
        $res = ["kode_klas" => "", "no_surat" => "", "tahun" => "", "keperluan" => "", "nominal" => ""];
        if (empty($text)) return $res;

        // Normalisasi teks: satu baris & bersihkan spasi double
        $cleanText = preg_replace('/\s+/', ' ', $text);

        // 1. PARSING NO SURAT & KODE KLAS (Support OCR Typo / Spasi / Miring)
        // Mencari pola 000/000000/AA/2013
        if (preg_match('/(\d{3})[\s\/|I1-]+(\d{4,8})[\s\/|I1-]+([A-Z0-9]{2,5})[\s\/|I1-]+(\d{4})/i', $cleanText, $m)) {
            $res["kode_klas"] = $m[1];
            $res["no_surat"] = "{$m[1]}/{$m[2]}/" . strtoupper($m[3]) . "/{$m[4]}";
            $res["tahun"] = $m[4];
        } else {
            // Fallback: Cari tahun aja kalau pola gagal
            if (preg_match('/(20[0-9]{2})/', $cleanText, $m)) $res["tahun"] = $m[1];
        }

        // 2. PARSING KEPERLUAN (BOUNDARY STOP DI KEGIATAN)
        // Regex ini akan mengambil teks setelah "KEPERLUAN" tapi BERHENTI sebelum "Kegiatan" atau typo-nya "Keglatan"
        if (preg_match('/(?:KEPERLUAN|Keperluan|Pembeyaran|Pembayaran)\s*[:;]?\s*(.*?)(?=\s*(?:Kegiatan|Keglatan|Kegiaton|No\.|Nomor|Rp|JUMLAH|Uang|$))/is', $cleanText, $m)) {
            $res["keperluan"] = trim($m[1]);
        }

        // 3. PARSING NOMINAL (Cari angka setelah Rp atau Dibayarkan)
        if (preg_match('/(?:Dibayarkan|sebesar|Jumlah|Rp\.?)\s*(?:Rp\.?\s*)?([\d\.,]{5,})/i', $cleanText, $m)) {
            $numericValue = preg_replace('/[^0-9]/', '', $m[1]); // Bersihkan titik/koma
            $res["nominal"] = $numericValue;
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
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'public_id' => $publicId,
            'api_key'   => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
            Log::info('Foto temporer dihapus: ' . $publicId);
        }
        
        return $response;
    }
}