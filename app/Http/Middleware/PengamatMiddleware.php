<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PengamatMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (!$user || $user->role !== 'pengamat') {
            return response()->json(['success' => false, 'message' => 'Akses ditolak. Hanya untuk pengamat.'], 403);
        }

        $approval = $user->pengamatSekolah;
        if (!$approval || $approval->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Akun pengamat belum diverifikasi oleh admin.',
                'status'  => $approval?->status ?? 'not_registered',
            ], 403);
        }

        return $next($request);
    }
}
