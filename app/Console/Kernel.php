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
     * Define scheduled commands.
     *
     * Intentionally empty: the canonical schedule lives in bootstrap/app.php
     * (Laravel 11+). Registering the same commands here caused double runs
     * for orders:auto-approve and emails:send-publisher-add-site-reminders.
     */
    protected function schedule(Schedule $schedule): void
    {
        //
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
