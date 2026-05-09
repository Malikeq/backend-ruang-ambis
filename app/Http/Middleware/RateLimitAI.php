<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class RateLimitAI
{
    /**
     * Rate limit AI features per user per day.
     * Usage: middleware('rate.limit.ai:tanya_ai')
     */
    public function handle(Request $request, Closure $next, string $feature): mixed
    {
        $user  = $request->user();
        $key   = "ai:{$feature}:{$user->id}:" . now()->format('Y-m-d');
        $limit = $this->getLimit($user->tier, $feature);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = RateLimiter::availableIn($key);
            return response()->json([
                'success'     => false,
                'message'     => "Batas penggunaan {$feature} hari ini telah tercapai.",
                'retry_after' => $retryAfter,
                'limit'       => $limit,
            ], 429);
        }

        RateLimiter::hit($key, 86400); // Decay after 24 hours

        return $next($request);
    }

    private function getLimit(string $tier, string $feature): int
    {
        return match($feature) {
            'tanya_ai'    => match($tier) { 'premium' => 30, 'daily_pass' => 5, default => 0 },
            'photo_solve' => match($tier) { 'premium' => 10, 'daily_pass' => 3, default => 0 },
            default       => 10,
        };
    }
}
