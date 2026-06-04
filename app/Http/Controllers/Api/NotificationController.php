<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use App\Services\ExpoPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:20'],
        ]);

        PushToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id'   => $request->user()->id,
                'platform'  => $data['platform'] ?? null,
                'is_active' => true,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Token push terdaftar.',
        ]);
    }

    public function unregister(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        PushToken::where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->update(['is_active' => false]);

        return response()->json(['success' => true]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => [
                'push_streak_reminder' => (bool) $user->push_streak_reminder,
                'push_weekly_report'   => (bool) $user->push_weekly_report,
                'has_token'            => $user->pushTokens()->where('is_active', true)->exists(),
            ],
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'push_streak_reminder' => ['sometimes', 'boolean'],
            'push_weekly_report'   => ['sometimes', 'boolean'],
        ]);

        $request->user()->update($data);

        return response()->json([
            'success' => true,
            'data'    => [
                'push_streak_reminder' => (bool) $request->user()->push_streak_reminder,
                'push_weekly_report'   => (bool) $request->user()->push_weekly_report,
            ],
        ]);
    }

    /**
     * POST /notifications/test-push
     * Sends a test push to the authenticated user's active device(s).
     */
    public function testPush(Request $request, ExpoPushService $push): JsonResponse
    {
        if (!config('ailolos.allow_test_push', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Test push dinonaktifkan di server.',
            ], 403);
        }

        $user = $request->user();
        $tokens = $user->pushTokens()->where('is_active', true)->pluck('token');

        if ($tokens->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Token push belum terdaftar. Buka Notifikasi → izinkan permission dulu.',
            ], 422);
        }

        $name = explode(' ', $user->name)[0] ?? 'Pejuang';
        $sent = $push->send(
            $tokens,
            '🔔 Test Push AI Lolos PTN',
            "Hai {$name}! Push dari server Laravel berhasil sampai ke perangkatmu.",
            ['screen' => 'latihan', 'type' => 'test'],
        );

        if ($sent === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim ke Expo Push API. Pastikan FCM credentials sudah di-setup di EAS.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => "Test push dikirim ke {$sent} perangkat.",
            'data'    => ['sent' => $sent],
        ]);
    }
}
