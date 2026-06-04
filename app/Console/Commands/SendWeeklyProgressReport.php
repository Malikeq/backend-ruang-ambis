<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ExpoPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendWeeklyProgressReport extends Command
{
    protected $signature = 'notifications:weekly-report';

    protected $description = 'Kirim laporan progres mingguan via push notification';

    public function handle(ExpoPushService $push): int
    {
        $weekStart = now()->subDays(7)->startOfDay();

        $users = User::query()
            ->where('role', 'user')
            ->where('push_weekly_report', true)
            ->whereHas('pushTokens', fn ($q) => $q->where('is_active', true))
            ->with(['pushTokens' => fn ($q) => $q->where('is_active', true)])
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            $stats = DB::table('user_attempts')
                ->where('user_id', $user->id)
                ->where('created_at', '>=', $weekStart)
                ->selectRaw('COUNT(*) as total, SUM(is_correct) as benar')
                ->first();

            $total = (int) ($stats->total ?? 0);
            if ($total === 0) {
                continue;
            }

            $benar   = (int) ($stats->benar ?? 0);
            $akurasi = $total > 0 ? round(($benar / $total) * 100) : 0;
            $name    = explode(' ', $user->name)[0] ?? 'Pejuang';

            $tokens = $user->pushTokens->pluck('token');
            $sent += $push->send(
                $tokens,
                '📊 Laporan Mingguan SNBT',
                "Hai {$name}! Minggu ini: {$total} soal, akurasi {$akurasi}%. Terus semangat!",
                ['screen' => 'explore', 'type' => 'weekly_report'],
            );
        }

        $this->info("Weekly reports: {$sent} notifications queued.");

        return self::SUCCESS;
    }
}
