<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ArsipSp2d;
use Illuminate\Support\Facades\{Log, Storage, Validator, DB};
use CURLFile;

class ArsipController extends Controller
{
    // Konfigurasi Cloudinary (Sesuai ProfileController kamu)
    private $cloudName = 'dswy4tagj';
    private $apiKey = '877393947668591';
    private $apiSecret = 'h-EXj0-IhNHx2zKBuNXVwNbPeWI';

    public function index() {
        return response()->json(ArsipSp2d::orderBy('created_at', 'desc')->get());
    }

    public function show($id) {
        return response()->json(["success" => true, "data" => ArsipSp2d::findOrFail($id)]);
    }

    /**
     * 🔥 Hapus file dari Cloudinary jika dibatalkan/expired di React
     */
    public function destroyTemp(Request $request) {
        $url = $request->filename; // React mengirimkan URL lengkap Cloudinary
        
        if ($url) {
            $publicId = $this->extractPublicIdFromUrl($url);
            if ($publicId) {
                $this->deleteFromCloudinary($publicId);
                return response()->json([
                    "success" => true, 
                    "message" => "File di Cloudinary berhasil dihapus."
                ]);
            }
        }

        return response()->json(["success" => false, "message" => "File tidak ditemukan."], 404);
    }

    public function store(Request $request) {
        $data = $request->all();
        
        if (!empty($data['nominal'])) {
            $data['nominal'] = preg_replace('/[^0-9]/', '', $data['nominal']);
        } else {
            $data['nominal'] = 0;
        }

        $validator = Validator::make($data, [
            'kode_klas' => 'required|string',
            'no_surat' => 'required|string',
            'keperluan' => 'required|string',
            'tahun' => 'required|numeric|digits:4',
            'jumlah' => 'required|string',
            'nominal' => 'required|numeric',
            'jra_aktif' => 'required|numeric',
            'jra_inaktif' => 'required|numeric',
            'unit_pencipta' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $arsip = ArsipSp2d::create($data);
        return response()->json(["success" => true, "data" => $arsip]);
    }

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
            'jumlah' => 'required|string',
            'nominal' => 'required|numeric',
            'jra_aktif' => 'required|numeric',
            'jra_inaktif' => 'required|numeric',
            'unit_pencipta' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $arsip->update($data);
        return response()->json(["success" => true, "data" => $arsip]);
    }

    public function destroy($id) {
        $arsip = ArsipSp2d::findOrFail($id);
        if ($arsip->file_dokumen) {
            $publicId = $this->extractPublicIdFromUrl($arsip->file_dokumen);
            if ($publicId) {
                $this->deleteFromCloudinary($publicId);
            }
        }
        $arsip->delete();
        return response()->json(["success" => true]);
    }

    /**
     * 🔥 UPLOAD KE CLOUDINARY & JALANKAN OCR
     */
    public function upload(Request $request)
    {
        try {
            if (!$request->hasFile('file')) {
                return response()->json(["success" => false, "error" => "file tidak ditemukan"], 400);
            }

            $file = $request->file('file');
            
            // 1. Persiapan Upload Cloudinary
            $folder = 'sp2d_arsip';
            $timestamp = time();
            $signature = sha1("folder={$folder}&timestamp={$timestamp}" . $this->apiSecret);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/upload");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'file' => new CURLFile($file->getRealPath(), $file->getMimeType(), $file->getClientOriginalName()),
                'api_key' => $this->apiKey,
                'timestamp' => $timestamp,
                'folder' => $folder,
                'signature' => $signature,
            ]);
            
            $responseCloud = curl_exec($ch);
            $httpCodeCloud = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCodeCloud != 200) {
                $error = json_decode($responseCloud, true);
                throw new \Exception('Cloudinary Upload Failed: ' . ($error['error']['message'] ?? 'Unknown Error'));
            }
            
            $resultCloud = json_decode($responseCloud, true);
            $secureUrl = $resultCloud['secure_url'];

            // 2. Jalankan OCR ke Hugging Face menggunakan path lokal sementara
            $ocr = $this->doOCR($file->getRealPath());
            $full_text_mentahan = $ocr['teks'] ?? "";
            
            // 3. Parsing Data
            $parsedData = $this->parseDataDariFullText($full_text_mentahan);

            return response()->json([
                "success" => true,
                "file_dokumen" => $secureUrl, // URL Cloudinary
                "kode_klas" => $parsedData['kode_klas'],
                "no_surat" => $parsedData['no_surat'],
                "tahun" => $parsedData['tahun'],
                "nominal" => $parsedData['nominal'],
                "keperluan" => $parsedData['keperluan']
            ]);

        } catch (\Exception $e) {
            Log::error("UPLOAD/OCR ERROR: " . $e->getMessage());
            return response()->json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    private function doOCR($imagePath)
    {
        $url = "https://albedoes-ocr-bkad.hf.space/ocr"; 
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 150); 
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);

        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            "file" => new \CURLFile($imagePath)
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200) ? json_decode($response, true) : ["teks" => ""];
    }

    private function parseDataDariFullText($text)
    {
        $result = ["kode_klas" => "", "no_surat" => "", "tahun" => "", "keperluan" => "", "nominal" => ""];

        // Regex fleksibel untuk nomor surat
        if (preg_match('/([0-9]{3})[\/|lI1\\-]\s*([0-9]{4,8})[\/|lI1\\-]\s*([A-Z]{2})[\/|lI1\\-]\s*(20[0-9]{2})/i', $text, $match)) {
            $result["kode_klas"] = $match[1];
            $result["no_surat"] = $match[1] . '/' . $match[2] . '/' . strtoupper($match[3]) . '/' . $match[4];
            $result["tahun"] = $match[4];
        }

        if (preg_match('/(?:Keperluan|Pembayaran)\s*[:;]?\s*(.*?)(?=Kegiatan|No\.\s*REKENING|JUMLAH|Rp|$)/is', $text, $match)) {
            $result["keperluan"] = "SP2D " . trim(preg_replace('/\s+/', ' ', $match[1]));
        }

        if (preg_match_all('/(?:Rp\.?|Rp|R\.)\s*([\d\.\,]{5,})/i', $text, $matches)) {
            $angka_terbesar = 0;
            foreach ($matches[1] as $nominal_str) {
                $clean_num = preg_replace('/[^0-9]/', '', preg_replace('/\,.*$/', '', $nominal_str));
                $val = (int) $clean_num;
                if ($val > $angka_terbesar) $angka_terbesar = $val;
            }
            if ($angka_terbesar > 0) $result["nominal"] = "Rp. " . number_format($angka_terbesar, 0, ',', '.');
        }

        return $result;
    }

    // Helper: Extract public_id dari URL Cloudinary
    private function extractPublicIdFromUrl($url) {
        preg_match('/\/upload\/v\d+\/(.+)\.(jpg|jpeg|png|gif|webp)/', $url, $matches);
        return $matches[1] ?? null;
    }

    // Helper: Hapus dari Cloudinary
    private function deleteFromCloudinary($publicId) {
        $timestamp = time();
        $signature = sha1("public_id={$publicId}&timestamp={$timestamp}" . $this->apiSecret);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/destroy");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'public_id' => $publicId,
            'api_key' => $this->apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }
}