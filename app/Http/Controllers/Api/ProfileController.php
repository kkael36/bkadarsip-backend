<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash, Mail, DB, Storage};
use App\Mail\OTPMail;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class ProfileController extends Controller {

   public function updateGeneral(Request $request) {
    $user = $request->user();
    
    $request->validate([
        'name' => 'required|string|max:255',
        'photo' => 'nullable|image|max:5120'
    ]);
    
    $user->name = $request->name;
    
    if ($request->hasFile('photo')) {
        try {
            $file = $request->file('photo');
            
            // ✅ Cara dengan Cloudinary facade
            $result = Cloudinary::upload($file->getRealPath(), [
                'folder' => 'profiles'
            ]);
            
            $user->photo_profile = $result->getSecurePath();
            
        } catch (\Exception $e) {
            \Log::error('Cloudinary Error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Upload foto gagal: ' . $e->getMessage()
            ], 500);
        }
    }
    
    $user->save();
    
    return response()->json([
        'message' => 'Profil berhasil diperbarui', 
        'user' => $user
    ]);
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