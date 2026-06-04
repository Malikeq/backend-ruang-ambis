<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Free tier — daily practice session limit
    |--------------------------------------------------------------------------
    | Must match mobile LIMITS.free.sesiPerHari in lib/feature-flags.ts
    */
    'free_daily_sessions' => (int) env('FREE_DAILY_SESSIONS', 2),

    'free_max_soal_per_session' => (int) env('FREE_MAX_SOAL_PER_SESSION', 20),

    /*
    | Allow POST /notifications/test-push (development / staging)
    */
    'allow_test_push' => (bool) env('ALLOW_TEST_PUSH', true),
];
