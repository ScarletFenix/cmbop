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
 * human. Enforcement only reaches eye/visit unlocks while copy-strike hide mode
 * is active — outside that, identity is already open and pace is not consulted:
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

        $inFreezeWindow = $freezeAfter > 0 ? $this->countWithin($user, $freezeWindow) : 0;

        if ($freezeAfter > 0 && $inFreezeWindow >= $freezeAfter) {
            $this->announce($user, self::FROZEN, $inFreezeWindow, $freezeWindow);

            return $this->enforced($cfg)
                ? $this->verdict(self::FROZEN, $freezeWindow * 60, 'sustained_rate')
                : $this->verdict(self::OK);
        }

        $slowAfter = (int) ($cfg['slow_after'] ?? 30);
        $slowWindow = max(5, (int) ($cfg['slow_window_seconds'] ?? 60));

        if ($slowAfter > 0 && $this->countWithinSeconds($user, $slowWindow) >= $slowAfter) {
            return $this->enforced($cfg)
                ? $this->verdict(self::SLOW, $this->secondsUntilRoomFrees($user, $slowWindow, $slowAfter), 'fast_rate')
                : $this->verdict(self::OK);
        }

        // Cadence last: it is the most telling signal but needs enough samples,
        // and there is no point computing it for someone browsing normally.
        if ($this->looksMetronomic($user, $cfg)) {
            $this->announce($user, self::SLOW, $this->countWithinSeconds($user, $slowWindow), 1, 'even timing');

            // Not a room-in-the-window problem, so do not quote one: an even
            // rhythm clears when it stops being even, which happens naturally on
            // a person's next click and never on a loop's. Long enough that the
            // page states it rather than silently spinning against a wait that
            // would not have helped.
            return $this->enforced($cfg)
                ? $this->verdict(self::SLOW, max(11, $slowWindow), 'even_timing')
                : $this->verdict(self::OK);
        }

        return $this->verdict(self::OK);
    }

    public function isExempt(User $user): bool
    {
        try {
            $until = $user->catalog_reveal_exempt_until ?? null;

            return $until !== null && $until->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Exact freeze copy shown when new addresses pause.
     *
     * Kept in one place so the catalog AJAX path and the /go redirect say the
     * same thing, including the support contact for an immediate lift.
     */
    public static function freezeUserMessage(): string
    {
        $email = (string) config(
            'email_notifications.brand.support_email',
            'support@seolinkbuildings.com'
        );

        return "You've opened a large number of new website addresses in a short window, "
            .'so we\'ve paused new addresses on this account for a short while.'."\n\n"
            ."Browsing, filters, cart, and addresses you've already opened still work — "
            ."only new addresses are paused.\n\n"
            .'If you want this removed and normal browsing restored right now, '
            .'contact us via chat or email '.$email.'.';
    }

    public function countWithin(User $user, int $minutes): int
    {
        return $this->countWithinSeconds($user, max(1, $minutes) * 60);
    }

    public function countWithinSeconds(User $user, int $seconds): int
    {
        if (! $this->tableAvailable()) {
            return 0;
        }

        return SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subSeconds(max(1, $seconds)))
            ->count();
    }

    /**
     * How long until this account genuinely has room again.
     *
     * Quoting a fixed Retry-After against a sliding window is a lie: the client
     * waits, asks again, and is refused again, which turns a courtesy pause into
     * a dead end. This returns the real time until the oldest request in the
     * window ages out, so retrying works.
     */
    public function secondsUntilRoomFrees(User $user, int $windowSeconds, int $limit): int
    {
        if (! $this->tableAvailable() || $limit <= 0) {
            return 1;
        }

        $windowStart = now()->subSeconds(max(1, $windowSeconds));

        // The request that has to expire is the one $limit places back from the
        // newest, counting within the window.
        $oldest = SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $windowStart)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->pluck('created_at')
            ->last();

        if (! $oldest) {
            return 1;
        }

        $freesAt = $oldest->copy()->addSeconds($windowSeconds);

        return max(1, (int) ceil(now()->diffInSeconds($freesAt, false)) + 1);
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

        return $this->seriesLooksMetronomic($times->reverse()->values()->all(), $samples, $limit);
    }

    /**
     * The regularity maths on a series of timestamps, with no queries of its own.
     *
     * Split out so a listing screen can fetch every account's history in one
     * query and still ask the same question per account.
     *
     * @param  list<\DateTimeInterface>  $ordered  oldest first
     */
    public function seriesLooksMetronomic(array $ordered, int $samples, float $limit): bool
    {
        if ($limit <= 0 || count($ordered) < $samples) {
            return false;
        }

        $gaps = [];
        for ($i = 1; $i < count($ordered); $i++) {
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
