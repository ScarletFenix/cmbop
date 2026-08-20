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
 * Before the response when promotions tables are missing, then after
 * flush: repair migrate / MEDIA_PATH / APP_URL / storage link (at most
 * every few hours) and run due schedule events (at most once a minute).
 * Mail still drains via DrainQueuedMail.
 */
class HealHostingerProduction
{
    private const HEAL_LOCK = 'ops:hostinger-heal';

    // v2: bust the 6-hour skip left by a failed migrate (flag was set anyway).
    private const HEAL_FLAG = 'ops:hostinger-healed-v2';

    private const HEAL_RETRY = 'ops:hostinger-heal-retry';

    private const SCHEDULE_LOCK = 'ops:web-schedule';

    private const SCHEDULE_FLAG = 'ops:web-schedule-ran';

    private bool $healedThisRequest = false;

    public function handle(Request $request, Closure $next)
    {
        // terminate() is too late for Promotions: the hub already rendered
        // Unknown / the red banner. Heal first when storage is incomplete.
        if ($this->enabled() && ! $this->fullHealCached() && ! ProductionRepair::promotionsStorageReady()) {
            $this->healOnce();
        }

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
        if (app()->runningInConsole() || ProductionRepair::runningAutomatedTest()) {
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
        if ($this->healedThisRequest) {
            return;
        }

        try {
            if (Cache::get(self::HEAL_FLAG)) {
                return;
            }
            if (Cache::get(self::HEAL_RETRY) && ProductionRepair::promotionsStorageReady()) {
                return;
            }
        } catch (\Throwable) {
            // Cache down / no cache table: still migrate so Promotions can load.
        }

        $lock = $this->lock(self::HEAL_LOCK, 120);
        if ($lock && ! $lock->get()) {
            return;
        }

        $this->healedThisRequest = true;

        try {
            $notes = app(ProductionRepair::class)->run();
            if (ProductionRepair::migrateCompleted($notes)) {
                Cache::put(self::HEAL_FLAG, true, now()->addHours(6));
            } else {
                if (ProductionRepair::promotionsStorageReady()) {
                    Cache::put(self::HEAL_RETRY, true, now()->addMinutes(5));
                }
                Log::warning('Hostinger production heal did not cache skip; migrate incomplete', [
                    'notes' => $notes,
                ]);
            }
            Log::info('Hostinger production heal ran', ['notes' => $notes]);
        } catch (\Throwable $e) {
            Log::warning('Hostinger production heal failed', ['error' => $e->getMessage()]);
        } finally {
            $lock?->release();
        }
    }

    private function fullHealCached(): bool
    {
        try {
            return (bool) Cache::get(self::HEAL_FLAG);
        } catch (\Throwable) {
            return false;
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
