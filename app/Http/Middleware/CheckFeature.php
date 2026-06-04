<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Subscription;

class CheckFeature
{
    /**
     * Check if user's active package has a specific feature enabled.
     * Usage: middleware('check.feature:ai_tutor')
     *
     * Falls back to tier-based check if no active subscription found.
     * Returns 403 if feature is disabled, 200 if enabled.
     */
    public function handle(Request $request, Closure $next, string $feature): mixed
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        // Get user's active subscription & package features
        $features = $this->getUserFeatures($user);

        // Check if the feature exists and is enabled
        $value = $features[$feature] ?? false;

        // Feature disabled (false or 0)
        if ($value === false || $value === 0) {
            return response()->json([
                'success'          => false,
                'message'          => 'Fitur ini tidak tersedia di paket kamu. Upgrade untuk mengaksesnya.',
                'feature_required' => $feature,
                'upgrade_required' => true,
            ], 403);
        }

        // Attach features to request for downstream use (e.g., limit checking)
        $request->attributes->set('user_features', $features);
        $request->attributes->set('feature_value', $value);

        return $next($request);
    }

    public static function getUserFeatures($user): array
    {
        // Check active subscription
        $sub = Subscription::with('package')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('selesai', '>', now())
            ->latest()
            ->first();

        if ($sub && $sub->package) {
            $features = $sub->package->fitur_json ?? [];
            if (is_array($features) && isset($features['ai_tutor'])) {
                return $features;
            }
        }

        // Fallback: derive from user tier
        return self::defaultFeaturesForTier($user->tier ?? 'free');
    }

    public static function defaultFeaturesForTier(string $tier): array
    {
        return match ($tier) {
            'premium' => [
                'ai_tutor'               => true,
                'ai_tanya_harian'        => 50,
                'ai_photo_solve'         => true,
                'ai_foto_harian'         => 20,
                'latihan_soal_per_sesi'  => -1,
                'latihan_sesi_per_hari'  => -1,
                'review_jawaban'         => true,
                'riwayat_latihan'        => true,
                'leaderboard'            => true,
                'analisis_kelemahan'     => true,
                'soal_adaptif'           => true,
                'tryout_penuh'           => true,
                'akses_semua_mapel'      => true,
                'export_hasil'           => true,
                'bonus_poin_streak'      => true,
            ],
            'daily_pass' => [
                'ai_tutor'               => true,
                'ai_tanya_harian'        => 30,
                'ai_photo_solve'         => true,
                'ai_foto_harian'         => 10,
                'latihan_soal_per_sesi'  => -1,
                'latihan_sesi_per_hari'  => -1,
                'review_jawaban'         => true,
                'riwayat_latihan'        => true,
                'leaderboard'            => true,
                'analisis_kelemahan'     => true,
                'soal_adaptif'           => false,
                'tryout_penuh'           => true,
                'akses_semua_mapel'      => true,
                'export_hasil'           => false,
                'bonus_poin_streak'      => false,
            ],
            default => [ // free
                'ai_tutor'               => false,
                'ai_tanya_harian'        => 0,
                'ai_photo_solve'         => false,
                'ai_foto_harian'         => 0,
                'latihan_soal_per_sesi'  => 20,
                'latihan_sesi_per_hari'  => 3,
                'review_jawaban'         => false,
                'riwayat_latihan'        => false,
                'leaderboard'            => true,
                'analisis_kelemahan'     => false,
                'soal_adaptif'           => false,
                'tryout_penuh'           => false,
                'akses_semua_mapel'      => false,
                'export_hasil'           => false,
                'bonus_poin_streak'      => false,
            ],
        };
    }
}
