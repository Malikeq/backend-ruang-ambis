<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
