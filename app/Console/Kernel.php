<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Re-fetch campus logos every Sunday at midnight
        $schedule->command('kampus:fetch-logos --limit=50')
                 ->weekly()->sundays()->at('00:00')
                 ->withoutOverlapping()->runInBackground();

        // Auto-scrape keketatan SNBT — 1st of each month at 02:00
        $schedule->command('snbt:scrape-sidata ' . (date('Y') - 1))
                 ->monthlyOn(1, '02:00')
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->appendOutputTo(storage_path('logs/snbt-scrape.log'));

        // Push notifications — WIB
        $schedule->command('notifications:streak-reminders --slot=morning')
                 ->dailyAt('08:00')
                 ->timezone('Asia/Jakarta')
                 ->withoutOverlapping()
                 ->runInBackground();

        $schedule->command('notifications:streak-reminders --slot=evening')
                 ->dailyAt('20:00')
                 ->timezone('Asia/Jakarta')
                 ->withoutOverlapping()
                 ->runInBackground();

        $schedule->command('notifications:weekly-report')
                 ->weeklyOn(0, '09:00')
                 ->timezone('Asia/Jakarta')
                 ->withoutOverlapping()
                 ->runInBackground();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
