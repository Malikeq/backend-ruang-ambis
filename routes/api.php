<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Dashboard\DashboardController;
use App\Http\Controllers\Api\Dashboard\WeaknessController;
use App\Http\Controllers\Api\Latihan\LatihanController;
use App\Http\Controllers\Api\AI\AiExplanationController;
use App\Http\Controllers\Api\AI\AiPhotoController;
use App\Http\Controllers\Api\AI\AiUploadController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminSoalController;
use App\Http\Controllers\Api\Admin\AdminAiController;
use App\Http\Controllers\Api\Admin\AdminPackageController;

Route::prefix('v1')->group(function () {

    // ── Public endpoints ──────────────────────────────────
    Route::post('auth/register',  [AuthController::class, 'register']);
    Route::post('auth/login',     [AuthController::class, 'login']);
    Route::post('auth/forgot',    [PasswordController::class, 'sendResetLink']);
    Route::post('auth/reset',     [PasswordController::class, 'reset']);
    Route::get('packages',        [AdminPackageController::class, 'index']);
    Route::get('kampus',          [OnboardingController::class, 'getKampus']);
    Route::get('kampus/{id}/jurusan', [OnboardingController::class, 'getJurusan']);

    // ── Auth required ─────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('auth/logout',         [AuthController::class, 'logout']);
        Route::get('auth/me',              [AuthController::class, 'me']);

        // User profile & targets
        Route::get('user/targets',         [OnboardingController::class, 'getTargets']);

        // Onboarding
        Route::post('onboarding/target',            [OnboardingController::class, 'setTarget']);
        Route::post('onboarding/referral',          [OnboardingController::class, 'saveReferral']);
        Route::post('onboarding/complete',          [OnboardingController::class, 'complete']);
        Route::post('onboarding/diagnostic/mulai',  [OnboardingController::class, 'startDiagnostic']);
        Route::post('onboarding/diagnostic/jawab',  [OnboardingController::class, 'submitDiagnostic']);


        // Dashboard
        Route::get('dashboard',            [DashboardController::class, 'index']);
        Route::get('dashboard/streak',     [DashboardController::class, 'streak']);

        // Weakness
        Route::get('weakness',             [WeaknessController::class, 'index']);
        Route::get('weakness/{id}',        [WeaknessController::class, 'detail']);

        // Leaderboard
        Route::get('leaderboard',          [LeaderboardController::class, 'index']);
        Route::get('leaderboard/me',       [LeaderboardController::class, 'myRank']);

        // Sub-materi list (for Per Bab mode)
        Route::get('sub-materi',                         [LatihanController::class, 'subMateri']);

        // Latihan
        Route::post('latihan/mulai',                     [LatihanController::class, 'mulai']);
        Route::get('latihan/{sesi}/soal/{index}',        [LatihanController::class, 'getSoal']);
        Route::post('latihan/{sesi}/jawab',              [LatihanController::class, 'jawab']);
        Route::post('latihan/{sesi}/selesai',            [LatihanController::class, 'selesai']);
        Route::get('latihan/{sesi}/hasil',               [LatihanController::class, 'hasil']);

        // AI Explanation (free — cached)
        Route::get('ai/explanation/{soalId}',            [AiExplanationController::class, 'getExplanation']);

        // AI Tanya (premium/daily only)
        Route::post('ai/tanya', [AiExplanationController::class, 'tanya'])
            ->middleware(['check.tier:premium,daily_pass', 'rate.limit.ai:tanya_ai']);

        // AI Photo Solve (premium/daily only)
        Route::post('ai/photo-solve', [AiPhotoController::class, 'solve'])
            ->middleware(['check.tier:premium,daily_pass', 'rate.limit.ai:photo_solve']);

        // Payment
        Route::post('payment/initiate',         [PaymentController::class, 'initiate']);
        Route::post('payment/webhook',           [PaymentController::class, 'webhook']);
        Route::get('payment/status/{orderId}',   [PaymentController::class, 'status']);
        Route::post('payment/promo',             [PaymentController::class, 'applyPromo']);
    });

    // ── Admin (superadmin role) ───────────────────────────
    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
        Route::get('dashboard',                         [AdminDashboardController::class, 'index']);
        Route::get('dashboard/revenue',                 [AdminDashboardController::class, 'revenue']);
        Route::get('dashboard/ai-costs',                [AdminDashboardController::class, 'aiCosts']);
        Route::get('transactions',                      [AdminDashboardController::class, 'transactions']);

        Route::get('users',                             [AdminUserController::class, 'index']);
        Route::get('users/{user}',                      [AdminUserController::class, 'show']);
        Route::patch('users/{user}/tier',               [AdminUserController::class, 'updateTier']);
        Route::post('users/{user}/ban',                 [AdminUserController::class, 'ban']);
        Route::post('users/{user}/unban',               [AdminUserController::class, 'unban']);

        Route::get('soal',                              [AdminSoalController::class, 'index']);
        Route::post('soal',                             [AdminSoalController::class, 'store']);
        Route::patch('soal/{soal}',                     [AdminSoalController::class, 'update']);
        Route::delete('soal/{soal}',                    [AdminSoalController::class, 'destroy']);
        Route::post('soal/{soal}/publish',              [AdminSoalController::class, 'publish']);

        Route::get('mapel-list',                           [AiUploadController::class, 'mapelList']);

        Route::post('ai/upload',                        [AiUploadController::class, 'upload']);
        Route::get('ai/upload/history',                 [AiUploadController::class, 'history']);
        Route::get('ai/upload/{upload}/status',         [AiUploadController::class, 'status']);
        Route::post('ai/upload/{upload}/retry',         [AdminAiController::class, 'retryUpload']);
        Route::get('ai/drafts',                         [AdminAiController::class, 'drafts']);
        Route::post('ai/drafts/test',                   [AdminAiController::class, 'createTestDraft']);
        Route::post('ai/drafts/bulk-approve',           [AdminAiController::class, 'bulkApproveDrafts']);
        Route::post('ai/drafts/bulk-reject',            [AdminAiController::class, 'bulkRejectDrafts']);
        Route::patch('ai/drafts/{draft}',               [AdminAiController::class, 'editDraft']);
        Route::post('ai/drafts/{draft}/approve',        [AdminAiController::class, 'approveDraft']);
        Route::post('ai/drafts/{draft}/reject',         [AdminAiController::class, 'rejectDraft']);
        Route::get('ai/settings',                       [AdminAiController::class, 'settings']);
        Route::post('ai/cache/clear',                   [AdminAiController::class, 'clearCache']);

        Route::get('packages',                          [AdminPackageController::class, 'index']);
        Route::post('packages',                         [AdminPackageController::class, 'store']);
        Route::patch('packages/{pkg}',                  [AdminPackageController::class, 'update']);
        Route::delete('packages/{pkg}',                 [AdminPackageController::class, 'destroy']);

        // Kampus logo management
        Route::get('kampus',                            [\App\Http\Controllers\Api\Admin\AdminKampusController::class, 'index']);
        Route::post('kampus/{kampus}/fetch-logo',       [\App\Http\Controllers\Api\Admin\AdminKampusController::class, 'fetchLogo']);
        Route::post('kampus/fetch-all-logos',           [\App\Http\Controllers\Api\Admin\AdminKampusController::class, 'fetchAllLogos']);
    });

});
