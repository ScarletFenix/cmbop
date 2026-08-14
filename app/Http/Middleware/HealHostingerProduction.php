<?php

namespace App\Http\Middleware;

use App\Support\HostingerMediaPath;
use App\Support\ProductionRepair;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Hostinger has no SSH from this agent and often no per-minute cron.
 * After the response is flushed: repair migrate / MEDIA_PATH / APP_URL /
 * storage link (at most every few hours) and run due schedule events
 * (at most once a minute). Mail still drains via DrainQueuedMail.
 */
class HealHostingerProduction
{
    private const HEAL_LOCK = 'ops:hostinger-heal';

    private const HEAL_FLAG = 'ops:hostinger-healed';

    private const SCHEDULE_LOCK = 'ops:web-schedule';

    private const SCHEDULE_FLAG = 'ops:web-schedule-ran';

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, mixed $response): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->healOnce();
        $this->runDueSchedule();
    }

    private function enabled(): bool
    {
        if (app()->runningInConsole() || app()->runningUnitTests()) {
            return false;
        }

        if (! (bool) config('app.web_heal', true)) {
            return false;
        }

        return app()->environment('production')
            || HostingerMediaPath::looksLikeHostinger();
    }

    private function healOnce(): void
    {
        try {
            if (Cache::get(self::HEAL_FLAG)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $lock = $this->lock(self::HEAL_LOCK, 120);
        if ($lock && ! $lock->get()) {
            return;
        }

        try {
            $notes = app(ProductionRepair::class)->run();
            Cache::put(self::HEAL_FLAG, true, now()->addHours(6));
            Log::info('Hostinger production heal ran', ['notes' => $notes]);
        } catch (\Throwable $e) {
            Log::warning('Hostinger production heal failed', ['error' => $e->getMessage()]);
        } finally {
            $lock?->release();
        }
    }

    private function runDueSchedule(): void
    {
        try {
            $last = Cache::get(self::SCHEDULE_FLAG);
            if (is_numeric($last) && (microtime(true) - (float) $last) < 55) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $lock = $this->lock(self::SCHEDULE_LOCK, 55);
        if ($lock && ! $lock->get()) {
            return;
        }

        try {
            Artisan::call('schedule:run');
            Cache::put(self::SCHEDULE_FLAG, microtime(true), 120);
        } catch (\Throwable $e) {
            Log::warning('Web schedule:run failed', ['error' => $e->getMessage()]);
        } finally {
            $lock?->release();
        }
    }

    private function lock(string $name, int $seconds): mixed
    {
        try {
            $store = Cache::store()->getStore();
            if (! $store instanceof LockProvider) {
                return null;
            }

            return Cache::store()->lock($name, $seconds);
        } catch (\Throwable) {
            return null;
        }
    }
}
