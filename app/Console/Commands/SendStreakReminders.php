<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ExpoPushService;
use Illuminate\Console\Command;

class SendStreakReminders extends Command
{
    protected $signature = 'notifications:streak-reminders {--slot=morning : morning|evening}';

    protected $description = 'Kirim push pengingat streak ke user yang belum latihan hari ini';

    public function handle(ExpoPushService $push): int
    {
        $slot = $this->option('slot');

        $users = User::query()
            ->where('role', 'user')
            ->where('push_streak_reminder', true)
            ->whereHas('pushTokens', fn ($q) => $q->where('is_active', true))
            ->where(function ($q) {
                $q->whereNull('last_active')
                    ->orWhereDate('last_active', '<', today());
            })
            ->with(['pushTokens' => fn ($q) => $q->where('is_active', true)])
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            $tokens = $user->pushTokens->pluck('token');
            if ($tokens->isEmpty()) {
                continue;
            }

            if ($slot === 'evening' && $user->streak_days <= 0) {
                continue;
            }

            [$title, $body] = $this->messageFor($user, $slot);

            $sent += $push->send($tokens, $title, $body, [
                'screen' => 'latihan',
                'type'   => 'streak_reminder',
            ]);
        }

        $this->info("Streak reminders ({$slot}): {$sent} notifications queued.");

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string} */
    private function messageFor(User $user, string $slot): array
    {
        $streak = (int) $user->streak_days;
        $name   = explode(' ', $user->name)[0] ?? 'Pejuang';

        if ($slot === 'evening') {
            return [
                '⚠️ Streak bisa terputus malam ini!',
                "{$name}, streak {$streak} hari-mu belum aman. Latihan 10 menit sekarang!",
            ];
        }

        if ($streak > 0) {
            return [
                '🔥 Jaga streak-mu!',
                "Hai {$name}! Streak {$streak} hari — latihan sekarang sebelum jam 12 malam.",
            ];
        }

        return [
            '🔥 Mulai streak hari ini!',
            "Hai {$name}! Kerjakan 1 sesi latihan untuk memulai streak SNBT-mu.",
        ];
    }
}
