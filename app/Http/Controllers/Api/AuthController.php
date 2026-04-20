<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash, Validator, Mail};
use App\Http\Controllers\Controller;
use App\Mail\OTPMail; 
use Carbon\Carbon;

class AuthController extends Controller {

    // --- FUNGSI LOGIN ---
    public function login(Request $request) {
        $request->validate([
            'email' => 'required|email', 
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Email atau password salah'], 401);
        }

        // VALIDASI AKUN NONAKTIF
        if ($user->deactivated_until && now()->lessThan(Carbon::parse($user->deactivated_until))) {
            $sisa = Carbon::parse($user->deactivated_until)->translatedFormat('d F Y (H:i)');
            return response()->json([
                'message' => "Akun Anda dinonaktifkan sementara oleh Admin hingga $sisa WIB."
            ], 403);
        }

        return response()->json([
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => $user
        ]);
    }

    // --- 1. REQUEST OTP (LUPA PASSWORD) ---
    public function forgotPasswordRequest(Request $request) {
        $request->validate(['email' => 'required|email']);
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Email tidak terdaftar'], 404);
        }

        $otp = rand(100000, 999999);
        $user->otp = $otp; 
        $user->save();

        try {
            $subject = "Kode OTP Pemulihan Akun - BKAD Kota Bogor";
            Mail::to($user->email)->queue(new OTPMail($otp, $subject));
            
            return response()->json(['message' => 'Kode OTP berhasil dikirim ke email Anda']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengirim email. Periksa konfigurasi.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // --- 2. VERIFIKASI OTP ---
    public function forgotPasswordVerify(Request $request) {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required'
        ]);

        $user = User::where('email', $request->email)
                    ->where('otp', $request->otp)
                    ->first();

        if (!$user) {
            return response()->json(['message' => 'Kode OTP salah atau tidak sesuai'], 422);
        }

        return response()->json(['message' => 'OTP Valid. Silakan atur password baru.']);
    }

    // --- 3. RESET PASSWORD FINAL ---
    public function forgotPasswordReset(Request $request) {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required',
            'password' => 'required|min:8|confirmed'
        ]);

        $user = User::where('email', $request->email)
                    ->where('otp', $request->otp)
                    ->first();

        if (!$user) {
            return response()->json(['message' => 'Sesi kadaluarsa, silakan minta OTP baru'], 422);
        }

        $user->password = Hash::make($request->password);
        $user->otp = null; 
        $user->save();

        return response()->json(['message' => 'Password berhasil diubah. Silakan login.']);
    }

    public function logout(Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Berhasil keluar']);
    }
}