<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Default Laravel Inspire Command
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Custom Test Command (optional but useful)
|--------------------------------------------------------------------------
*/

Artisan::command('orders:test', function () {
    $this->info('Orders system is working correctly.');
})->purpose('Test orders system');

/*
|--------------------------------------------------------------------------
| Trigger Auto Approve Command (optional shortcut)
|--------------------------------------------------------------------------
|
| This does NOT replace Kernel or proper command.
| It just triggers your real command.
|
*/

Artisan::command('orders:auto-approve-run', function () {
    $this->info('Running auto-approve process...');

    $exitCode = Artisan::call('orders:auto-approve');

    $this->info('Completed with exit code: ' . $exitCode);
})->purpose('Manually trigger auto approve orders');