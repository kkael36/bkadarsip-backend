<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ArsipSp2d;
use Illuminate\Support\Facades\{Log, Storage, Validator, DB};
use CURLFile;

class ArsipController extends Controller
{
    private $cloudName;
    private $apiKey;
    private $apiSecret;
    private $hfToken;

    public function __construct()
    {
        // Ambil semua credential dari environment variable
        $this->cloudName = env('CLOUDINARY_CLOUD_NAME', '');
        $this->apiKey = env('CLOUDINARY_API_KEY', '');
        $this->apiSecret = env('CLOUDINARY_API_SECRET', '');
        $this->hfToken = env('HF_TOKEN', '');
        
        // Log warning jika ada credential yang kosong
        if (empty($this->cloudName)) {
            Log::warning('CLOUDINARY_CLOUD_NAME tidak diset di environment variables');
        }
        if (empty($this->apiKey)) {
            Log::warning('CLOUDINARY_API_KEY tidak diset di environment variables');
        }
        if (empty($this->apiSecret)) {
            Log::warning('CLOUDINARY_API_SECRET tidak diset di environment variables');
        }
        if (empty($this->hfToken)) {
            Log::warning('HF_TOKEN tidak diset di environment variables');
        }
    }

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

            // 1. Upload ke Cloudinary
            Log::info("Upload ke Cloudinary...");
            $folder = 'sp2d_arsip';
            $timestamp = time();
            $signature = sha1("folder={$folder}&timestamp={$timestamp}" . $this->apiSecret);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/upload");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'file' => new CURLFile($realPath, $file->getMimeType(), $file->getClientOriginalName()),
                'api_key' => $this->apiKey,
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

            // 2. OCR dengan FastAPI endpoint (PRIVATE SPACE - PAKAI TOKEN)
            Log::info("Memulai OCR dengan FastAPI endpoint...");
            $textMentah = $this->doOCRWithFastAPI($realPath);
            
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

    private function doOCRWithFastAPI($imagePath)
    {
        // Endpoint FastAPI dari main.py (PRIVATE SPACE)
        $url = "https://albedoes-ocr-bkad.hf.space/ocr";
        $hf_token = $this->hfToken;
        
        try {
            Log::info("Memanggil FastAPI endpoint: " . $url);
            
            if (empty($hf_token)) {
                Log::error("HF_TOKEN tidak diset! Cannot access private space");
                return "";
            }
            
            Log::info("Menggunakan token: " . substr($hf_token, 0, 10) . "...");
            
            // Cek apakah file ada
            if (!file_exists($imagePath)) {
                Log::error("File tidak ditemukan: " . $imagePath);
                return "";
            }
            
            // Prepare file untuk upload (sesuai dengan parameter file: UploadFile = File(...))
            $fileData = new \CURLFile($imagePath);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            
            // Kirim sebagai multipart/form-data dengan field 'file'
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => $fileData]);
            
            // WAJIB PAKAI TOKEN UNTUK PRIVATE SPACE!
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $hf_token,
            ]);
            
            // Debug: tambahkan verbose
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_STDERR, $verbose);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            // Baca verbose output
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            Log::info("CURL Verbose: " . $verboseLog);
            
            curl_close($ch);
            
            Log::info("FastAPI Response HTTP Code: {$httpCode}");
            
            // Cek apakah token valid
            if ($httpCode === 401) {
                Log::error("UNAUTHORIZED! Token Hugging Face tidak valid atau expired");
                return "";
            }
            
            if ($httpCode === 403) {
                Log::error("FORBIDDEN! Token tidak memiliki akses ke Space ini");
                return "";
            }
            
            if ($httpCode !== 200) {
                Log::error("FastAPI Error - HTTP {$httpCode}: " . $error);
                if (!empty($response)) {
                    Log::error("Response body: " . substr($response, 0, 500));
                }
                return "";
            }
            
            if (empty($response)) {
                Log::error("FastAPI Response kosong");
                return "";
            }
            
            Log::info("Raw response: " . substr($response, 0, 500));
            
            // Parse JSON response dari FastAPI
            $decoded = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("JSON decode error: " . json_last_error_msg());
                return "";
            }
            
            // Cek struktur response dari main.py
            if (isset($decoded['status']) && $decoded['status'] === 'success') {
                if (isset($decoded['teks'])) {
                    $teks = $decoded['teks'];
                    Log::info("OCR berhasil, jumlah kata: " . ($decoded['jumlah_kata'] ?? 0));
                    return $teks;
                }
            } elseif (isset($decoded['teks'])) {
                return $decoded['teks'];
            } elseif (isset($decoded['result'])) {
                return $decoded['result'];
            }
            
            Log::warning("Response tidak mengandung teks. Structure: " . json_encode(array_keys($decoded)));
            return "";
            
        } catch (\Exception $e) {
            Log::error("FastAPI Exception: " . $e->getMessage());
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
            Log::warning("Text OCR kosong untuk parsing");
            return $res;
        }

        // Bersihkan teks
        $text = preg_replace('/\s+/', ' ', $text);
        Log::info("Parsing text: " . substr($text, 0, 300));

        // Pattern untuk No Surat - format: 931/001302/LS/2013
        $patterns = [
            '/Nomor\s*[:;]?\s*(\d{3})\/(\d{6})\/([A-Z]{2})\/(\d{4})/i',
            '/(\d{3})\/(\d{6})\/([A-Z]{2})\/(\d{4})/i',
            '/(\d{3})\/(\d{4,8})\/([A-Z]{2,3})\/(\d{4})/i',
            '/No\.?\s*[:;]?\s*(\d{3})\/(\d{4,8})\/([A-Z]{2,3})\/(\d{4})/i',
            '/SP2D\s*No\.?\s*[:;]?\s*(\d{3})\/(\d{4,8})\/([A-Z]{2,3})\/(\d{4})/i'
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

        // Cari Keperluan
        $keperluanPatterns = [
            '/Keperluan\s*[:;]?\s*(.*?)(?=Kegiatan|No\.|Rp|$)/is',
            '/Pembayaran\s*[:;]?\s*(.*?)(?=Kegiatan|No\.|Rp|$)/is',
            '/Untuk\s*[:;]?\s*(.*?)(?=Kegiatan|No\.|Rp|$)/is'
        ];
        
        foreach ($keperluanPatterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $keperluanText = trim(preg_replace('/\s+/', ' ', $m[1]));
                if (strlen($keperluanText) > 5 && strlen($keperluanText) < 500) {
                    $res["keperluan"] = "SP2D " . $keperluanText;
                    Log::info("Keperluan ditemukan: " . $res["keperluan"]);
                    break;
                }
            }
        }

        // Cari Nominal
        $nominalPatterns = [
            '/Uang sebesar Rp\s*([\d\.]+)/i',
            '/JUMLAH Yang Dibayarkan Rp\s*([\d\.]+)/i',
            '/(?:Rp|Rupiah|Rp\.)\s*[:;]?\s*([\d\.\,]{5,})/i',
            '/Jumlah\s*[:;]?\s*(?:Rp|Rupiah)\s*([\d\.\,]{5,})/i'
        ];
        
        foreach ($nominalPatterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $nominal = trim($m[1]);
                $numericNominal = (int) preg_replace('/[^0-9]/', '', $nominal);
                if ($numericNominal >= 10000) {
                    $res["nominal"] = "Rp " . $nominal;
                    Log::info("Nominal ditemukan: " . $res["nominal"]);
                    break;
                }
            }
        }

        return $res;
    }

    private function extractPublicIdFromUrl($url) {
        preg_match('/\/upload\/v\d+\/(.+)\.(jpg|jpeg|png|gif|webp)/', $url, $m);
        return $m[1] ?? null;
    }

    private function deleteFromCloudinary($publicId) {
        $timestamp = time();
        $sig = sha1("public_id={$publicId}&timestamp={$timestamp}" . $this->apiSecret);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/destroy");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'public_id' => $publicId, 
            'api_key' => $this->apiKey, 
            'timestamp' => $timestamp, 
            'signature' => $sig
        ]);
        $result = curl_exec($ch);
        Log::info("Delete Cloudinary result: " . $result);
        curl_close($ch);
    }
}