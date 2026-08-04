<?php

namespace App\Services\Catalog;

use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Services\InAppNotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Watches how fast an account is taking publisher domains, not how many.
 *
 * A quota is the wrong instrument here. An agency planning a campaign may
 * legitimately open hundreds of listings, and refusing them at twenty-five is
 * hostile to the exact customer you want while barely inconveniencing a scraper
 * who can register again. What a person cannot do is keep it up at machine
 * speed, or space every request the same number of milliseconds apart.
 *
 * So browsing and revealing are unlimited, and this decides whether the pace is
 * human:
 *
 *   ok      nothing happens
 *   slow    the next address waits a moment — imperceptible to a person,
 *           ruinous to a script's throughput, and refuses nobody
 *   frozen  new addresses pause until the window clears; browsing, filtering
 *           and everything already opened carry on working
 *
 * Volume alone only ever raises a flag for a human to look at, because most of
 * those will turn out to be real buyers.
 */
class RevealPaceGuard
{
    public const OK = 'ok';

    public const SLOW = 'slow';

    public const FROZEN = 'frozen';

    public function __construct(private InAppNotificationService $notifications) {}

    /**
     * @return array{state: string, retry_after: int|null, reason: string|null}
     */
    public function assess(User $user): array
    {
        if (! $this->tableAvailable() || $this->isExempt($user)) {
            return $this->verdict(self::OK);
        }

        $cfg = (array) config('catalog.url_reveal.pace', []);

        $this->flagForReviewIfUnusual($user, $cfg);

        $freezeAfter = (int) ($cfg['freeze_after'] ?? 250);
        $freezeWindow = max(1, (int) ($cfg['freeze_window_minutes'] ?? 30));

        if ($freezeAfter > 0 && $this->countWithin($user, $freezeWindow) >= $freezeAfter) {
            $this->announce($user, self::FROZEN, $this->countWithin($user, $freezeWindow), $freezeWindow);

            return $this->enforced($cfg)
                ? $this->verdict(self::FROZEN, $freezeWindow * 60, 'sustained_rate')
                : $this->verdict(self::OK);
        }

        $slowAfter = (int) ($cfg['slow_after'] ?? 40);
        $slowWindow = max(1, (int) ($cfg['slow_window_minutes'] ?? 5));
        $retry = max(1, (int) ($cfg['slow_retry_seconds'] ?? 3));

        if ($slowAfter > 0 && $this->countWithin($user, $slowWindow) >= $slowAfter) {
            return $this->enforced($cfg)
                ? $this->verdict(self::SLOW, $retry, 'fast_rate')
                : $this->verdict(self::OK);
        }

        // Cadence last: it is the most telling signal but needs enough samples,
        // and there is no point computing it for someone browsing normally.
        if ($this->looksMetronomic($user, $cfg)) {
            $this->announce($user, self::SLOW, $this->countWithin($user, $slowWindow), $slowWindow, 'even timing');

            return $this->enforced($cfg)
                ? $this->verdict(self::SLOW, $retry, 'even_timing')
                : $this->verdict(self::OK);
        }

        return $this->verdict(self::OK);
    }

    public function isExempt(User $user): bool
    {
        try {
            return (bool) ($user->catalog_reveal_exempt ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    public function countWithin(User $user, int $minutes): int
    {
        if (! $this->tableAvailable()) {
            return 0;
        }

        return SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subMinutes(max(1, $minutes)))
            ->count();
    }

    /**
     * Are the gaps between requests suspiciously even?
     *
     * People pause to read, get distracted, and change their mind. A loop does
     * not. Evading this means introducing real jitter and slowing down, which is
     * the outcome we wanted anyway.
     */
    public function looksMetronomic(User $user, ?array $cfg = null): bool
    {
        $cfg ??= (array) config('catalog.url_reveal.pace', []);
        $samples = max(5, (int) ($cfg['regularity_samples'] ?? 15));
        $limit = (float) ($cfg['regularity_stddev_seconds'] ?? 1.5);

        if ($limit <= 0 || ! $this->tableAvailable()) {
            return false;
        }

        $times = SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHour())
            ->orderByDesc('created_at')
            ->limit($samples)
            ->pluck('created_at');

        if ($times->count() < $samples) {
            return false;
        }

        $gaps = [];
        $ordered = $times->reverse()->values();

        for ($i = 1; $i < $ordered->count(); $i++) {
            $gaps[] = abs($ordered[$i]->getTimestamp() - $ordered[$i - 1]->getTimestamp());
        }

        if ($gaps === []) {
            return false;
        }

        $mean = array_sum($gaps) / count($gaps);

        // Long, even gaps are just someone working steadily. Only tight ones
        // matter, and a machine's are both tight and even.
        if ($mean > 30) {
            return false;
        }

        $variance = array_sum(array_map(fn ($g) => ($g - $mean) ** 2, $gaps)) / count($gaps);

        return sqrt($variance) <= $limit;
    }

    /**
     * Unusual volume is worth a look, never a restriction.
     */
    private function flagForReviewIfUnusual(User $user, array $cfg): void
    {
        $after = (int) ($cfg['review_after'] ?? 300);
        $hours = max(1, (int) ($cfg['review_window_hours'] ?? 24));

        if ($after <= 0) {
            return;
        }

        $count = $this->countWithin($user, $hours * 60);

        if ($count < $after) {
            return;
        }

        // Once per window per account, or an admin gets a wall of the same news.
        $key = 'catalog-pace-review:'.$user->id;

        if (Cache::has($key)) {
            return;
        }

        Cache::put($key, true, now()->addHours($hours));

        try {
            $this->notifications->notifyAdminsCatalogPace($user, $count, $hours * 60, 'review');
        } catch (\Throwable $e) {
            Log::warning('Catalog pace review notice failed', ['error' => $e->getMessage()]);
        }
    }

    private function announce(User $user, string $state, int $count, int $windowMinutes, string $because = 'rate'): void
    {
        $key = 'catalog-pace-'.$state.':'.$user->id;

        if (Cache::has($key)) {
            return;
        }

        Cache::put($key, true, now()->addMinutes(max(5, $windowMinutes)));

        try {
            $this->notifications->notifyAdminsCatalogPace($user, $count, $windowMinutes, $state, $because);
        } catch (\Throwable $e) {
            Log::warning('Catalog pace notice failed', ['error' => $e->getMessage()]);
        }
    }

    private function enforced(array $cfg): bool
    {
        return (bool) ($cfg['enforce'] ?? true);
    }

    /**
     * @return array{state: string, retry_after: int|null, reason: string|null}
     */
    private function verdict(string $state, ?int $retryAfter = null, ?string $reason = null): array
    {
        return ['state' => $state, 'retry_after' => $retryAfter, 'reason' => $reason];
    }

    private function tableAvailable(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        try {
            return $available = Schema::hasTable('site_url_reveals');
        } catch (\Throwable) {
            return $available = false;
        }
    }
}
