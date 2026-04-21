<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash, Mail, DB, Storage};
use App\Mail\OTPMail;
use CURLFile;
//use Cloudinary\Cloudinary;

class ProfileController extends Controller {

    public function updateGeneral(Request $request) {
    $user = $request->user();
    
    // Validasi dengan pesan error kustom
    $request->validate([
        'name' => 'required|string|max:255',
        'photo' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp|max:5120'
    ], [
        'name.required' => 'Nama tidak boleh kosong.',
        'photo.image' => 'File harus berupa gambar.',
        'photo.mimes' => 'Format file tidak didukung. Gunakan: JPG, JPEG, PNG, GIF, atau WEBP.',
        'photo.max' => 'Ukuran file terlalu besar. Maksimal 5 MB.'
    ]);
    
    $user->name = $request->name;
    
    if ($request->hasFile('photo')) {
        $file = $request->file('photo');
        
        try {
            // 🔥 HAPUS FOTO LAMA JIKA ADA
            if ($user->photo_profile) {
                $oldPublicId = $this->extractPublicIdFromUrl($user->photo_profile);
                if ($oldPublicId) {
                    $this->deleteFromCloudinary($oldPublicId);
                }
            }
            
            // Konfigurasi Cloudinary
            $cloudName = 'dswy4tagj';
            $apiKey = '877393947668591';
            $apiSecret = 'h-EXj0-IhNHx2zKBuNXVwNbPeWI';
            $folder = 'profiles';
            $timestamp = time();
            
            // Generate signature
            $signature = sha1("folder={$folder}&timestamp={$timestamp}" . $apiSecret);
            
            // Upload dengan cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'file' => new CURLFile($file->getRealPath(), $file->getMimeType(), $file->getClientOriginalName()),
                'api_key' => $apiKey,
                'timestamp' => $timestamp,
                'folder' => $folder,
                'signature' => $signature,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode != 200) {
                $error = json_decode($response, true);
                throw new \Exception($error['error']['message'] ?? 'Upload failed');
            }
            
            $result = json_decode($response, true);
            $user->photo_profile = $result['secure_url'];
            
        } catch (\Exception $e) {
            \Log::error('Upload Error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Upload foto gagal: ' . $e->getMessage()
            ], 422);
        }
    }
    
    $user->save();
    
    return response()->json([
        'message' => 'Profil berhasil diperbarui', 
        'user' => $user
    ]);
}

    // Helper function untuk extract public_id dari URL Cloudinary
    private function extractPublicIdFromUrl($url) {
        // URL pattern: https://res.cloudinary.com/cloud_name/image/upload/v1234567890/folder/filename.jpg
        preg_match('/\/upload\/v\d+\/(.+)\.(jpg|jpeg|png|gif|webp)/', $url, $matches);
        return $matches[1] ?? null;
    }

    // Helper function untuk hapus dari Cloudinary
    private function deleteFromCloudinary($publicId) {
        $cloudName = 'dswy4tagj';
        $apiKey = '877393947668591';
        $apiSecret = 'h-EXj0-IhNHx2zKBuNXVwNbPeWI';
        $timestamp = time();
        
        $signature = sha1("public_id={$publicId}&timestamp={$timestamp}" . $apiSecret);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$cloudName}/image/destroy");
        curl_setopt($ch, CURLOPT_POST, 1);
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
                \Log::info('Old photo deleted from Cloudinary: ' . $publicId);
            }
            return $result;
        }
        
        return null;
    }

    // 2. FLOW GANTI EMAIL
    public function requestEmailChange(Request $request) {
        $request->validate(['password' => 'required']);
        if (!Hash::check($request->password, $request->user()->password)) {
            return response()->json(['message' => 'Kata sandi akun salah'], 403);
        }
        
        $otp = rand(100000, 999999);
        try {
            DB::table('pending_email_changes')->updateOrInsert(
                ['user_id' => $request->user()->id], 
                ['old_otp' => Hash::make($otp), 'expires_at' => now()->addMinutes(15), 'created_at' => now()]
            );

            Mail::to($request->user()->email)->queue(new OTPMail($otp, "OTP Perubahan Email (Lama)"));
            return response()->json(['message' => 'Kode OTP dikirim ke email lama']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal memproses antrean', 'error' => $e->getMessage()], 500);
        }
    }

    public function verifyOldEmailOtp(Request $request) {
        $request->validate(['otp_old' => 'required']);
        $pending = DB::table('pending_email_changes')->where('user_id', $request->user()->id)->first();
        
        if (!$pending || !Hash::check($request->otp_old, $pending->old_otp)) {
            return response()->json(['message' => 'Kode OTP salah atau telah kadaluarsa'], 403);
        }
        return response()->json(['message' => 'Verifikasi berhasil']);
    }

    public function requestNewEmailOtp(Request $request) {
        $request->validate(['new_email' => 'required|email|unique:users,email']);
        
        $newOtp = rand(100000, 999999);
        try {
            DB::table('pending_email_changes')->where('user_id', $request->user()->id)->update([
                'new_email' => $request->new_email, 
                'new_otp' => Hash::make($newOtp), 
                'expires_at' => now()->addMinutes(15)
            ]);

            Mail::to($request->new_email)->queue(new OTPMail($newOtp, "Verifikasi Email Baru"));
            return response()->json(['message' => 'Kode OTP dikirim ke email baru']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal memproses antrean'], 500);
        }
    }

    public function finalizeEmailChange(Request $request) {
        $request->validate(['otp_new' => 'required']);
        $pending = DB::table('pending_email_changes')->where('user_id', $request->user()->id)->first();
        
        if (!$pending || !Hash::check($request->otp_new, $pending->new_otp)) {
            return response()->json(['message' => 'Kode verifikasi email baru salah'], 403);
        }

        $request->user()->update(['email' => $pending->new_email]);
        DB::table('pending_email_changes')->where('user_id', $request->user()->id)->delete();
        return response()->json(['message' => 'Email berhasil diperbarui', 'user' => $request->user()]);
    }

    // 3. FLOW GANTI PASSWORD
    public function requestPasswordOtp(Request $request) {
        try {
            $otp = rand(100000, 999999);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $request->user()->email], 
                ['token' => Hash::make($otp), 'created_at' => now()]
            );

            Mail::to($request->user()->email)->queue(new OTPMail($otp, "OTP Pemulihan Kata Sandi"));
            return response()->json(['message' => 'Kode OTP telah dikirim']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal memproses antrean'], 500);
        }
    }

    public function verifyPasswordOtp(Request $request) {
        $request->validate(['otp' => 'required']);
        $reset = DB::table('password_reset_tokens')->where('email', $request->user()->email)->first();
        
        if (!$reset || !Hash::check($request->otp, $reset->token)) {
            return response()->json(['message' => 'Kode OTP tidak valid'], 403);
        }
        return response()->json(['message' => 'Verifikasi berhasil']);
    }

    public function updatePassword(Request $request) {
        $request->validate(['password' => 'required|min:8|confirmed']);
        $request->user()->update(['password' => Hash::make($request->password)]);
        DB::table('password_reset_tokens')->where('email', $request->user()->email)->delete();
        return response()->json(['message' => 'Kata sandi berhasil diperbarui']);
    }
}