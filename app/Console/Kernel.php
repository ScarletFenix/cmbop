<?php

namespace App\Console;

use App\Console\Commands\AutoApproveOrders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Register commands
     */
    protected $commands = [
        AutoApproveOrders::class,
    ];

    /**
     * Define scheduled commands
     */
    protected function schedule(Schedule $schedule): void
    {
        // Kept in sync with bootstrap/app.php (Laravel 11+ uses bootstrap schedule)
        $schedule->command('orders:auto-approve')
            ->everyFifteenMinutes()
            ->withoutOverlapping();

        $schedule->command('emails:send-deposit-reminders')
            ->dailyAt('09:00')
            ->withoutOverlapping();
    }

    /**
     * Register command files
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
