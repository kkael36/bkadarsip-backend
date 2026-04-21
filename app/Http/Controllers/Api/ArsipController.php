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
        try {
            Log::info("=== UPLOAD FUNCTION STARTED ===");
            
            if (!$request->hasFile('file')) {
                Log::error("File tidak ditemukan dalam request");
                return response()->json(["success" => false, "error" => "File tidak ditemukan"], 400);
            }

            $file = $request->file('file');
            $realPath = $file->getRealPath();
            Log::info("File diterima: " . $file->getClientOriginalName());

            // 1. Upload ke Cloudinary (konfigurasi hardcoded seperti ProfileController)
            Log::info("Upload ke Cloudinary...");
            $cloudName = 'dswy4tagj';
            $apiKey = '877393947668591';
            $apiSecret = 'h-EXj0-IhNHx2zKBuNXVwNbPeWI';
            $folder = 'sp2d_arsip';
            $timestamp = time();
            
            // Generate signature
            $signature = sha1("folder={$folder}&timestamp={$timestamp}" . $apiSecret);
            
            // Upload dengan cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'file' => new CURLFile($realPath, $file->getMimeType(), $file->getClientOriginalName()),
                'api_key' => $apiKey,
                'timestamp' => $timestamp,
                'folder' => $folder,
                'signature' => $signature,
            ]);
            
            $resCloud = curl_exec($ch);
            $httpCloud = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCloud != 200) {
                throw new \Exception('Cloudinary Error: ' . $resCloud);
            }
            
            $dataCloud = json_decode($resCloud, true);
            $secureUrl = $dataCloud['secure_url'];
            Log::info("Upload Cloudinary berhasil: " . $secureUrl);

            // 2. OCR dengan endpoint PUBLIC
            Log::info("Memulai OCR dengan endpoint public...");
            $textMentah = $this->doOCRWithPublicEndpoint($realPath);
            
            Log::info("Hasil OCR length: " . strlen($textMentah));
            Log::info("Hasil OCR (first 500 chars): " . substr($textMentah, 0, 500));

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

            // 3. Parsing Data
            $parsed = $this->parseDataDariFullText($textMentah);
            Log::info("Hasil parsing: " . json_encode($parsed));

            return response()->json([
                "success" => true,
                "file_dokumen" => $secureUrl,
                "kode_klas" => $parsed['kode_klas'],
                "no_surat" => $parsed['no_surat'],
                "tahun" => $parsed['tahun'],
                "nominal" => $parsed['nominal'],
                "keperluan" => $parsed['keperluan'],
                "raw_ocr" => $textMentah
            ]);

        } catch (\Exception $e) {
            Log::error("UPLOAD/OCR CRASH: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
            return response()->json([
                "success" => false, 
                "error" => $e->getMessage()
            ], 500);
        }
    }

    private function doOCRWithPublicEndpoint($imagePath)
    {
        // Endpoint PUBLIC (tanpa token)
        $url = "https://cartyspaceship-ocr.hf.space/ocr";
        
        try {
            Log::info("Memanggil public endpoint: " . $url);
            
            if (!file_exists($imagePath)) {
                Log::error("File tidak ditemukan: " . $imagePath);
                return "";
            }
            
            // Prepare file
            $fileData = new \CURLFile($imagePath);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => $fileData]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            Log::info("Public OCR Response HTTP Code: {$httpCode}");
            
            if ($error) {
                Log::error("CURL Error: " . $error);
                return "";
            }
            
            if ($httpCode === 200) {
                $decoded = json_decode($response, true);
                Log::info("Response: " . json_encode($decoded));
                
                // Format response dari endpoint public menggunakan field 'teks'
                if (isset($decoded['teks'])) {
                    return $decoded['teks'];
                } elseif (isset($decoded['text'])) {
                    return $decoded['text'];
                } elseif (isset($decoded['result'])) {
                    return $decoded['result'];
                } elseif (isset($decoded['data'])) {
                    return $decoded['data'];
                } elseif (is_string($decoded)) {
                    return $decoded;
                }
            }
            
            Log::error("HTTP {$httpCode}: " . $response);
            return "";
            
        } catch (\Exception $e) {
            Log::error("Public OCR Exception: " . $e->getMessage());
            return "";
        }
    }

    private function parseDataDariFullText($text)
    {
        $res = [
            "kode_klas" => "", 
            "no_surat" => "", 
            "tahun" => "", 
            "keperluan" => "", 
            "nominal" => ""
        ];

        if (empty($text)) {
            return $res;
        }

        // Bersihkan teks: replace newline dengan spasi, hapus multiple spaces
        $text = preg_replace('/\s+/', ' ', $text);
        Log::info("Parsing text: " . substr($text, 0, 500));

        // ==================== 1. PARSING NO SURAT ====================
        // Format OCR: "N0M0R:931/001302/15/2013" atau "NOMOR:931/001302/LS/2013"
        $patterns = [
            // Pattern untuk format "N0M0R:931/001302/15/2013" (angka 0 bukan huruf O)
            '/(?:N0M0R|NOMOR|No\.?)\s*[:;]?\s*(\d{3})\/(\d{6})\/(\d{2,3})\/(\d{4})/i',
            // Pattern untuk format "931/001302/LS/2013" (huruf)
            '/(?:N0M0R|NOMOR|No\.?)\s*[:;]?\s*(\d{3})\/(\d{6})\/([A-Z]{2,3})\/(\d{4})/i',
            // Pattern tanpa label "NOMOR"
            '/(\d{3})\/(\d{6})\/(\d{2,3})\/(\d{4})/i',
            '/(\d{3})\/(\d{6})\/([A-Z]{2,3})\/(\d{4})/i',
            '/(\d{3})\/(\d{4,8})\/([A-Z0-9]{2,3})\/(\d{4})/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $res["kode_klas"] = $m[1];
                $res["no_surat"] = "{$m[1]}/{$m[2]}/" . strtoupper($m[3]) . "/{$m[4]}";
                $res["tahun"] = $m[4];
                Log::info("No Surat ditemukan: " . $res["no_surat"]);
                break;
            }
        }

        // Backup cari tahun jika no surat tidak ditemukan
        if (empty($res["tahun"])) {
            if (preg_match('/(20[0-9]{2})/', $text, $m)) {
                $res["tahun"] = $m[1];
                Log::info("Tahun ditemukan dari backup: " . $res["tahun"]);
            }
        }

        // ==================== 2. PARSING KEPERLUAN ====================
        // Format OCR: "KEPERLUAN:PEMBAYARAN PENGADAAN KURS KERO SEKSUAL..."
        $keperluanPatterns = [
            '/KEPERLUAN\s*[:;]?\s*(.*?)(?=KEGIATAN|No\.|Rp|JUMLAH|$)/is',
            '/Keperluan\s*[:;]?\s*(.*?)(?=Kegiatan|No\.|Rp|Jumlah|$)/is',
            '/PEMBAYARAN\s*[:;]?\s*(.*?)(?=KEGIATAN|No\.|Rp|JUMLAH|$)/is',
            '/Untuk\s*[:;]?\s*(.*?)(?=Kegiatan|No\.|Rp|$)/is',
        ];
        
        foreach ($keperluanPatterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $keperluanText = trim(preg_replace('/\s+/', ' ', $m[1]));
                if (strlen($keperluanText) > 5 && strlen($keperluanText) < 500) {
                    $res["keperluan"] = $keperluanText;
                    Log::info("Keperluan ditemukan: " . $res["keperluan"]);
                    break;
                }
            }
        }

        // ==================== 3. PARSING NOMINAL ====================
        // Format OCR: "UANG SEBESAR RP 32.997.500" atau "JUMLAH YANG DLBEYARKAN RP. 32.997.500"
        $nominalPatterns = [
            '/UANG SEBESAR RP\s*([\d\.]+)/i',
            '/UANG SEBESAR RP\.?\s*([\d\.]+)/i',
            '/JUMLAH YANG DLBEYARKAN\s*RP\.?\s*([\d\.]+)/i',
            '/JUMLAH YANG DIBAYARKAN\s*RP\.?\s*([\d\.]+)/i',
            '/Uang sebesar Rp\s*([\d\.]+)/i',
            '/(?:Rp|Rupiah|RP\.?)\s*[:;]?\s*([\d\.\,]{5,})/i',
        ];
        
        foreach ($nominalPatterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $nominal = trim($m[1]);
                // Bersihkan titik sebagai pemisah ribuan
                $numericNominal = (int) str_replace('.', '', $nominal);
                if ($numericNominal >= 10000) {
                    $res["nominal"] = "Rp " . $nominal;
                    Log::info("Nominal ditemukan: " . $res["nominal"]);
                    break;
                }
            }
        }

        return $res;
    }

    // Helper function untuk extract public_id dari URL Cloudinary (sama dengan ProfileController)
    private function extractPublicIdFromUrl($url) {
        // URL pattern: https://res.cloudinary.com/cloud_name/image/upload/v1234567890/folder/filename.jpg
        preg_match('/\/upload\/v\d+\/(.+)\.(jpg|jpeg|png|gif|webp)/', $url, $matches);
        return $matches[1] ?? null;
    }

    // Helper function untuk hapus dari Cloudinary (sama dengan ProfileController)
    private function deleteFromCloudinary($publicId) {
        $cloudName = 'dswy4tagj';
        $apiKey = '877393947668591';
        $apiSecret = 'h-EXj0-IhNHx2zKBuNXVwNbPeWI';
        $timestamp = time();
        
        $signature = sha1("public_id={$publicId}&timestamp={$timestamp}" . $apiSecret);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$cloudName}/image/destroy");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'public_id' => $publicId,
            'api_key' => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200) {
            $result = json_decode($response, true);
            if ($result['result'] == 'ok') {
                Log::info('File deleted from Cloudinary: ' . $publicId);
            }
            return $result;
        }
        
        return null;
    }
}