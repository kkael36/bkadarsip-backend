<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash, Validator};
use Carbon\Carbon;

class UserController extends Controller {
    
    /**
     * Tampilkan daftar pegawai dengan status aktif & sisa waktu nonaktif
     */
    public function index() {
        // Ambil user selain yang sedang login
        $users = User::where('id', '!=', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();
        
        $users->transform(function($u) {
            if (!$u->deactivated_until) {
                $u->is_active = true;
            } else {
                $u->is_active = now()->greaterThan(Carbon::parse($u->deactivated_until));
            }
            return $u;
        });

        return response()->json($users, 200);
    }

    /**
     * Daftarkan Pegawai Baru (Internal)
     */
    public function registerInternal(Request $request) {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:super_admin,operator,viewer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

        return response()->json([
            'success' => true, 
            'message' => 'Pegawai ' . $user->name . ' berhasil didaftarkan sebagai ' . $user->role,
            'user' => $user
        ], 201);
    }

    /**
     * Update Hak Akses
     */
    public function updateRole(Request $request, $id) {
        $request->validate([
            'role' => 'required|in:super_admin,operator,viewer'
        ]);

        $user = User::findOrFail($id);
        
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Dilarang mengubah role sendiri'], 403);
        }
        
        $user->role = $request->role;
        $user->save();

        return response()->json([
            'success' => true, 
            'message' => 'Hak akses ' . $user->name . ' berhasil diperbarui'
        ], 200);
    }

    /**
     * Toggle Status (Aktifkan/Nonaktifkan/Perpanjang)
     */
    public function toggleStatus(Request $request, $id) {
    try {
        $user = User::findOrFail($id);
        
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Dilarang blokir diri sendiri!'], 403);
        }

        // Aktifkan kembali jika duration null/kosong
        if (!$request->duration || $request->duration === 'null') {
            $user->deactivated_until = null;
            $user->save();
            return response()->json(['success' => true, 'message' => 'Akun ' . $user->name . ' diaktifkan kembali']);
        }

        // Tentukan base time: kalau sudah nonaktif, hitung dari sisa waktu (perpanjang)
        $baseTime = ($user->deactivated_until && now()->lessThan($user->deactivated_until)) 
                    ? Carbon::parse($user->deactivated_until) 
                    : now();

        $until = match($request->duration) {
            '1_day'   => $baseTime->addDay(),
            '1_week'  => $baseTime->addWeek(),
            '1_month' => $baseTime->addMonth(),
            'forever' => now()->addYears(20), // 20 tahun biar aman di semua tipe DB
            'custom'  => $request->custom_date ? Carbon::parse($request->custom_date)->endOfDay() : null,
            default   => null
        };

        if (!$until) {
            return response()->json(['message' => 'Durasi atau tanggal tidak valid'], 422);
        }

        $user->deactivated_until = $until;
        $user->save();

        return response()->json([
            'success' => true, 
            'message' => 'Masa nonaktif ' . $user->name . ' berhasil diperbarui',
            'until'   => $until->toDateTimeString()
        ]);

    } catch (\Exception $e) {
        return response()->json(['message' => 'Server Error: ' . $e->getMessage()], 500);
    }
}

    /**
     * Hapus User
     */
    public function destroy($id) {
        $user = User::findOrFail($id);
        
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Tindakan tidak diizinkan'], 403);
        }
        
        $user->delete();
        return response()->json([
            'success' => true,
            'message' => 'Data pegawai berhasil dihapus permanen'
        ], 200);
    }
}