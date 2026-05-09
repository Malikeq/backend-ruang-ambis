<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $entries = User::select('id', 'name', 'avatar_url', 'tier', 'points', 'streak_days')
            ->where('is_banned', false)
            ->orderByDesc('points')
            ->limit(100)
            ->get()
            ->map(fn($u, $i) => [
                'rank'        => $i + 1,
                'user'        => $u,
                'points'      => $u->points,
                'streak_days' => $u->streak_days,
            ]);

        return response()->json(['success' => true, 'data' => $entries]);
    }

    public function myRank(Request $request): JsonResponse
    {
        $user  = $request->user();
        $rank  = User::where('is_banned', false)->where('points', '>', $user->points)->count() + 1;

        return response()->json([
            'success' => true,
            'data'    => [
                'rank'        => $rank,
                'user'        => $user,
                'points'      => $user->points,
                'streak_days' => $user->streak_days,
            ],
        ]);
    }
}
