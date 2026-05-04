<?php
// bootstrap/app.php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Configure middleware here if needed
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Configure exception handling here if needed
    })
    ->withSchedule(function (Schedule $schedule) {
        // Auto-approve orders every minute
        $schedule->command('orders:auto-approve')
                 ->everyMinute()
                 ->withoutOverlapping()
                 ->sendOutputTo(storage_path('logs/auto-approve.log'))
                 ->emailOutputOnFailure(config('mail.admin_email'));
        
        // Alternative schedules (uncomment as needed):
        // $schedule->command('orders:auto-approve')->hourly();
        // $schedule->command('orders:auto-approve')->everyFiveMinutes();
        // $schedule->command('orders:auto-approve')->everyTenMinutes();
    })
    ->create();