<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"))
            ->when($request->tier,   fn($q, $t) => $q->where('tier', $t))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $users]);
    }

    public function show(User $user): JsonResponse
    {
        $user->load('attempts', 'subscriptions.package', 'pointsTransactions');
        return response()->json(['success' => true, 'data' => $user]);
    }

    public function updateTier(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['tier' => ['required', 'in:free,premium,daily_pass']]);
        $user->update(['tier' => $data['tier']]);
        return response()->json(['success' => true, 'message' => 'Tier berhasil diubah.', 'data' => $user]);
    }

    public function ban(User $user): JsonResponse
    {
        $user->update(['is_banned' => true]);
        $user->tokens()->delete();
        return response()->json(['success' => true, 'message' => 'User berhasil di-ban.']);
    }

    public function unban(User $user): JsonResponse
    {
        $user->update(['is_banned' => false]);
        return response()->json(['success' => true, 'message' => 'User berhasil di-unban.']);
    }

    public function resetPoints(User $user): JsonResponse
    {
        $user->update(['points' => 0]);
        return response()->json(['success' => true, 'message' => 'Poin berhasil direset.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/users/{user}/simulate-day
    // Testing tool: mundurkan last_active 1 hari ke belakang agar besok
    // user bisa trigger streak increment saat latihan.
    // Simulasi: seolah-olah hari ini = last_active + 1.
    // ─────────────────────────────────────────────────────────────────────────
    public function simulateDay(Request $request, User $user): JsonResponse
    {
        $days = (int) $request->input('days', 1);  // default mundur 1 hari
        $days = max(1, min($days, 30));             // clamp 1–30

        // Langkah 1: Mundurkan last_active N hari ke belakang
        $newLastActive = $user->last_active
            ? Carbon::parse($user->last_active)->subDays($days)
            : now()->subDays($days);
        $user->update(['last_active' => $newLastActive]);
        $user->refresh(); // reload fresh dari DB

        // Langkah 2: Panggil updateStreak() seolah user baru saja latihan hari ini
        // → diff antara newLastActive dan now() = N hari
        // → jika N=1 maka streak +1, jika N>1 maka streak reset ke 1
        $streakBefore = $user->streak_days;
        $user->updateStreak();
        $user->refresh();
        $streakAfter = $user->streak_days;

        $streakChanged = $streakAfter > $streakBefore ? "+" . ($streakAfter - $streakBefore) : ($streakAfter < $streakBefore ? "reset ke {$streakAfter}" : 'tidak berubah');

        return response()->json([
            'success' => true,
            'message' => $days === 1
                ? "✅ Streak naik! {$streakBefore} → {$streakAfter} hari ({$streakChanged})"
                : "⚠️ Streak direset karena gap {$days} hari. Sekarang: {$streakAfter} hari",
            'data'    => [
                'user_id'        => $user->id,
                'name'           => $user->name,
                'streak_days'    => $user->streak_days,
                'last_active'    => $user->last_active,
                'days_simulated' => $days,
                'streak_before'  => $streakBefore,
                'streak_after'   => $streakAfter,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/users/{user}/reset-streak
    // Reset streak ke 0 dan last_active ke null untuk fresh testing
    // ─────────────────────────────────────────────────────────────────────────
    public function resetStreak(User $user): JsonResponse
    {
        $user->update(['streak_days' => 0, 'last_active' => null]);
        return response()->json([
            'success' => true,
            'message' => 'Streak direset ke 0. User bisa mulai streak baru dari awal.',
            'data'    => ['user_id' => $user->id, 'streak_days' => 0, 'last_active' => null],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /admin/users/{user}/set-streak
    // Set streak ke nilai tertentu untuk testing visual milestone
    // ─────────────────────────────────────────────────────────────────────────
    public function setStreak(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'streak_days' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $user->update([
            'streak_days' => $data['streak_days'],
            'last_active' => now()->subDay(), // kemarin → besok bisa +1 lagi
        ]);

        return response()->json([
            'success' => true,
            'message' => "Streak di-set ke {$data['streak_days']} hari. last_active = kemarin → latihan sekarang akan naik ke " . ($data['streak_days'] + 1),
            'data'    => [
                'user_id'     => $user->id,
                'streak_days' => $user->streak_days,
                'last_active' => $user->last_active,
            ],
        ]);
    }
}
