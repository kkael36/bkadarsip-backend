<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole {
    public function handle(Request $request, Closure $next, ...$roles) {
        // Cek apakah user sudah login dan apakah role-nya sesuai
        if (!$request->user() || !in_array($request->user()->role, $roles)) {
            return response()->json(['message' => 'Akses ditolak!'], 403);
        }
        return $next($request);
    }
}