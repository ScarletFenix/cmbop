<?php

namespace App\Services\Catalog;

use App\Models\Order;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Services\InAppNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Decides whether an advertiser may see a publisher's domain, and records it
 * when they do.
 *
 * A listing is in one of three states for a given advertiser:
 *
 *   masked    the default; only a partial host leaves the server
 *   revealed  they asked, we logged it, and it stays revealed for them
 *   owned     it is in their cart or they have ordered it, so masking it would
 *             only stop them checking what they are buying
 *
 * Every surface that shows a domain should ask this class rather than reading
 * site_url directly, because the protection is only worth anything if the real
 * value never reaches the browser in the first place.
 */
class SiteUrlVisibility
{
    /** @var array<int, array<int, bool>> */
    private array $revealCache = [];

    public function __construct(private InAppNotificationService $notifications) {}

    /**
     * The host with the middle of the name replaced.
     *
     * Keeps the TLD and the first few characters so listings stay
     * distinguishable in a long table without being searchable.
     */
    public function mask(?string $url): string
    {
        $host = $this->host($url);

        if ($host === '') {
            return '••••••';
        }

        $parts = explode('.', $host);

        if (count($parts) < 2) {
            return substr($host, 0, 3).'******';
        }

        $tld = array_pop($parts);
        $name = implode('.', $parts);
        $visible = min(4, max(2, strlen($name)));

        return substr($name, 0, $visible).'***.'.$tld;
    }

    public function host(?string $url): string
    {
        return (string) Str::of((string) $url)
            ->replaceMatches('/^(https?:\/\/)?(www\.)?/', '')
            ->before('/')
            ->trim();
    }

    /**
     * What this person should see for this site.
     */
    public function hostFor(?User $user, Site $site): string
    {
        return $this->canSee($user, $site)
            ? $this->host($site->site_url)
            : $this->mask($site->site_url);
    }

    public function canSee(?User $user, Site $site): bool
    {
        if (! $user) {
            return false;
        }

        // Staff and the publisher who owns the listing are not the audience this
        // is protecting anyone from.
        if ($user->isAdmin() || $user->isMarketing()) {
            return true;
        }

        if ((int) $site->publisher_id === (int) $user->id) {
            return true;
        }

        return $this->hasRevealed($user, $site);
    }

    public function hasRevealed(User $user, Site $site): bool
    {
        $userId = (int) $user->id;
        $siteId = (int) $site->id;

        if (isset($this->revealCache[$userId][$siteId])) {
            return $this->revealCache[$userId][$siteId];
        }

        $this->warmFor($user, [$siteId]);

        return $this->revealCache[$userId][$siteId] ?? false;
    }

    /**
     * Load the reveal state for a page of listings in one query.
     *
     * Without this a 20-row catalog asks the same question 20 times.
     *
     * @param  iterable<int|Site>  $sites
     */
    public function warmFor(?User $user, iterable $sites): void
    {
        if (! $user || ! $this->tableAvailable()) {
            return;
        }

        $ids = collect($sites)
            ->map(fn ($site) => $site instanceof Site ? (int) $site->id : (int) $site)
            ->filter()
            ->unique();

        $userId = (int) $user->id;
        $this->revealCache[$userId] ??= [];

        $unknown = $ids->reject(fn (int $id) => array_key_exists($id, $this->revealCache[$userId]));

        if ($unknown->isEmpty()) {
            return;
        }

        $revealed = SiteUrlReveal::query()
            ->where('user_id', $userId)
            ->whereIn('site_id', $unknown->all())
            ->pluck('site_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        foreach ($unknown as $id) {
            $this->revealCache[$userId][$id] = $revealed->has($id);
        }
    }

    /**
     * Record that this advertiser has seen the domain, and hand it back.
     *
     * Idempotent: asking twice is one disclosure, so the second call neither
     * writes a row nor spends allowance.
     */
    public function reveal(User $user, Site $site, string $source = SiteUrlReveal::SOURCE_CATALOG): string
    {
        if (! $this->tableAvailable()) {
            return $this->host($site->site_url);
        }

        $alreadySeen = $this->hasRevealed($user, $site);

        if (! $alreadySeen) {
            try {
                SiteUrlReveal::create([
                    'user_id' => $user->id,
                    'site_id' => $site->id,
                    'source' => $source,
                    'ip_address' => request()?->ip(),
                ]);
            } catch (\Throwable $e) {
                // A race on the unique index means someone else already recorded
                // it, which is the outcome we wanted anyway.
                Log::debug('Site URL reveal already recorded', ['error' => $e->getMessage()]);
            }

            $this->revealCache[(int) $user->id][(int) $site->id] = true;
            $this->checkForAnomaly($user);
        }

        return $this->host($site->site_url);
    }

    /**
     * Reveals left today, or null when this advertiser is not metered.
     */
    public function remainingAllowance(User $user): ?int
    {
        $allowance = $this->dailyAllowance($user);

        if ($allowance === null) {
            return null;
        }

        return max(0, $allowance - $this->revealsToday($user));
    }

    public function hasAllowanceLeft(User $user): bool
    {
        $remaining = $this->remainingAllowance($user);

        return $remaining === null || $remaining > 0;
    }

    /**
     * Null means unlimited.
     *
     * Someone who has paid us is a customer, not a scraping risk, and a brand
     * new advertiser has to be able to inspect the goods before they will trust
     * us with money — so the meter is on the unfunded end, not the funded one.
     */
    public function dailyAllowance(User $user): ?int
    {
        $key = $this->isEstablished($user)
            ? 'catalog.url_reveal.daily_allowance_funded'
            : 'catalog.url_reveal.daily_allowance_new';

        $allowance = (int) config($key, 0);

        return $allowance > 0 ? $allowance : null;
    }

    public function isEstablished(User $user): bool
    {
        try {
            $hasPaidOrder = Order::query()
                ->where('user_id', $user->id)
                ->where('payment_status', 'paid')
                ->exists();

            if ($hasPaidOrder) {
                return true;
            }

            return $user->depositRequests()
                ->whereIn('status', ['approved', 'completed'])
                ->exists();
        } catch (\Throwable $e) {
            // Fail open: a broken lookup should not lock a real buyer out of the
            // catalog they are trying to spend money in.
            Log::warning('Could not classify advertiser for reveal allowance', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    public function revealsToday(User $user): int
    {
        if (! $this->tableAvailable()) {
            return 0;
        }

        return SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->when(
                ! (bool) config('catalog.url_reveal.count_cart_adds_against_allowance', false),
                fn ($q) => $q->where('source', SiteUrlReveal::SOURCE_CATALOG)
            )
            ->count();
    }

    /**
     * @return Collection<int, int>
     */
    public function revealedSiteIds(?User $user): Collection
    {
        if (! $user || ! $this->tableAvailable()) {
            return collect();
        }

        return SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->pluck('site_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * Tell an admin when one account is working through the catalog far faster
     * than a person shopping would.
     */
    private function checkForAnomaly(User $user): void
    {
        $threshold = (int) config('catalog.url_reveal.anomaly_threshold', 60);
        $window = (int) config('catalog.url_reveal.anomaly_window_minutes', 60);

        if ($threshold <= 0 || $window <= 0) {
            return;
        }

        try {
            $recent = SiteUrlReveal::query()
                ->where('user_id', $user->id)
                ->where('created_at', '>=', now()->subMinutes($window))
                ->count();

            // Fire once as the line is crossed rather than on every reveal after.
            if ($recent !== $threshold) {
                return;
            }

            $this->notifications->notifyAdminsCatalogScrapeSuspected($user, $recent, $window);
        } catch (\Throwable $e) {
            Log::warning('Reveal anomaly check failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
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

    /** @internal Reset memoised state between tests. */
    public function flush(): void
    {
        $this->revealCache = [];
        DB::connection();
    }
}
