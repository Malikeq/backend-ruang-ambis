<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckTier
{
    /**
     * Allow only users with the specified tier(s).
     * Usage: middleware('check.tier:premium,daily_pass')
     */
    public function handle(Request $request, Closure $next, string ...$tiers): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if (!in_array($user->tier, $tiers)) {
            return response()->json([
                'success' => false,
                'message' => 'Fitur ini memerlukan akun ' . implode(' atau ', $tiers) . '.',
                'upgrade_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
