<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{ArsipController, AuthController, UserController, BoxController, StatsController, ProfileController};

// ==========================================
// ROUTE PUBLIC (TANPA LOGIN)
// ==========================================
Route::post('/login', [AuthController::class, 'login']);

// Tambahkan ini agar React bisa akses fitur Lupa Password
Route::post('/forgot-password/request', [AuthController::class, 'forgotPasswordRequest']);
Route::post('/forgot-password/verify', [AuthController::class, 'forgotPasswordVerify']);
Route::post('/forgot-password/reset', [AuthController::class, 'forgotPasswordReset']);

// ==========================================
// ROUTE PRIVATE (WAJIB LOGIN)
// ==========================================
Route::middleware('auth:sanctum')->group(function () {

    // ----------------------------------------------------
    // 1. AKSES GLOBAL (Semua Role: super_admin, operator, viewer)
    // ----------------------------------------------------
    Route::get('/denah', [BoxController::class, 'denah']);
    Route::get('/stats', [StatsController::class, 'index']);
    
    // 🔥 VIEWER BISA AKSES INI: Read-only Arsip (Daftar & Detail)
    Route::get('/arsip', [ArsipController::class, 'index']);
    Route::get('/arsip/{id}', [ArsipController::class, 'show']);

    // 🔥 VIEWER BISA AKSES INI: Read-only Box (Daftar & Detail)
    Route::get('/boxes', [BoxController::class, 'index']);
    Route::get('/boxes/{id}', [BoxController::class, 'show']);

    // Pengaturan Profil (Berlaku untuk akun yang sedang login)

    Route::get('/profile', function (Request $request) {
        return response()->json($request->user());
    });

   // Pengaturan Profil
    Route::post('/user/update-general', [ProfileController::class, 'updateGeneral']);
    Route::post('/user/request-email-change', [ProfileController::class, 'requestEmailChange']);
    Route::post('/user/verify-old-email-otp', [ProfileController::class, 'verifyOldEmailOtp']);
    Route::post('/user/request-new-email-otp', [ProfileController::class, 'requestNewEmailOtp']);
    Route::post('/user/finalize-email', [ProfileController::class, 'finalizeEmailChange']);
    
    // Ganti Password (Pemicu Error 500 jika gagal email)
    Route::post('/user/request-password-otp', [ProfileController::class, 'requestPasswordOtp']);
    Route::post('/user/verify-password-otp', [ProfileController::class, 'verifyPasswordOtp']);
    Route::post('/user/update-password', [ProfileController::class, 'updatePassword']);

    Route::post('/logout', [AuthController::class, 'logout']);

    // ----------------------------------------------------
    // 2. AKSES MENENGAH (Hanya operator & super_admin)
    // ----------------------------------------------------
    Route::middleware('role:operator,super_admin')->group(function () {
        // Aksi Arsip (Input, Edit, Hapus)
        Route::post('/arsip', [ArsipController::class, 'store']);
        Route::put('/arsip/{id}', [ArsipController::class, 'update']);
        Route::delete('/arsip/{id}', [ArsipController::class, 'destroy']);
        Route::post('/upload-sp2d', [ArsipController::class, 'upload']);
        Route::post('/arsip/import', [ArsipController::class, 'import']); 
        
    
    Route::delete('/delete-temp-file', [ArsipController::class, 'destroyTemp']);

        // Aksi Box (Tambah, Edit, Hapus)
        Route::post('/boxes', [BoxController::class, 'store']);
        Route::put('/boxes/{id}', [BoxController::class, 'update']);
        Route::delete('/boxes/{id}', [BoxController::class, 'destroy']);
        Route::post('/boxes/import', [BoxController::class, 'import']);
    });

    // ----------------------------------------------------
    // 3. AKSES TERTINGGI (Hanya super_admin)
    // ----------------------------------------------------
    Route::middleware('role:super_admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/register-internal', [UserController::class, 'registerInternal']);
        Route::post('/users/{id}/role', [UserController::class, 'updateRole']);
        Route::post('/users/{id}/status', [UserController::class, 'toggleStatus']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);
    });

});