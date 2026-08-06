<?php

namespace App\Services\Catalog;

use App\Models\Order;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
use Illuminate\Support\Collection;
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
 *   revealed  they asked, we logged it, and it stays revealed for them until
 *             they click the eye closed
 *   owned     it is in their cart or they have ordered it, so masking it would
 *             only stop them checking what they are buying
 *
 * Hiding is a display preference on top of a disclosure: the audit row stays,
 * concealed_at flips, and a refresh keeps the mask until they open it again.
 *
 * Every surface that shows a domain should ask this class rather than reading
 * site_url directly, because the protection is only worth anything if the real
 * value never reaches the browser in the first place.
 */
class SiteUrlVisibility
{
    /** @var array<int, array<int, bool>> */
    private array $revealCache = [];

    private static ?bool $tableAvailableCache = null;

    private static ?bool $concealColumnAvailableCache = null;

    /** @internal Clear memoised schema flags after self-heal / tests. */
    public static function forgetSchemaCache(): void
    {
        self::$tableAvailableCache = null;
        self::$concealColumnAvailableCache = null;
    }

    /**
     * Best-effort: create site_url_reveals (+ concealed_at) when migrations were skipped.
     */
    public function ensureSchema(): void
    {
        app(CatalogRevealSchema::class)->ensure();
    }

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

    /**
     * Currently visible in the UI (disclosed and not manually hidden).
     */
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
     * They have been handed this domain before, even if they hid it again.
     *
     * Re-opening a concealed address must not count against pace — the
     * disclosure already happened.
     */
    public function hasEverSeen(User $user, Site $site): bool
    {
        $this->ensureSchema();

        if (! $this->tableAvailable()) {
            return false;
        }

        return SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->where('site_id', $site->id)
            ->exists();
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
        if (! $user) {
            return;
        }

        $this->ensureSchema();

        if (! $this->tableAvailable()) {
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

        $visibleQuery = SiteUrlReveal::query()
            ->where('user_id', $userId)
            ->whereIn('site_id', $unknown->all());

        if ($this->concealColumnAvailable()) {
            $visibleQuery->whereNull('concealed_at');
        }

        $revealed = $visibleQuery
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
     * Idempotent: asking twice is one disclosure. Re-opening after a manual hide
     * clears concealed_at without writing a second row or counting against pace.
     */
    public function reveal(User $user, Site $site, string $source = SiteUrlReveal::SOURCE_CATALOG): string
    {
        $this->ensureSchema();

        if (! $this->tableAvailable()) {
            // Do not pretend the address stuck — the UI would remask on refresh
            // and hide would say "Open the address before you can hide it."
            throw new \RuntimeException(
                'Could not save this website address. Please try again in a moment, or contact support if it keeps happening.'
            );
        }

        $row = SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->where('site_id', $site->id)
            ->first();

        if (! $row) {
            try {
                $row = SiteUrlReveal::create([
                    'user_id' => $user->id,
                    'site_id' => $site->id,
                    'source' => $source,
                    'ip_address' => request()?->ip(),
                ]);
            } catch (\Throwable $e) {
                // A race on the unique index means someone else already recorded
                // it, which is the outcome we wanted anyway.
                Log::debug('Site URL reveal already recorded', ['error' => $e->getMessage()]);
                $row = SiteUrlReveal::query()
                    ->where('user_id', $user->id)
                    ->where('site_id', $site->id)
                    ->first();
            }
        }

        if (! $row) {
            throw new \RuntimeException(
                'Could not save this website address. Please try again in a moment.'
            );
        }

        if ($this->concealColumnAvailable() && $row->concealed_at !== null) {
            $row->concealed_at = null;
            $row->save();
        }

        $this->revealCache[(int) $user->id][(int) $site->id] = true;

        return $this->host($site->site_url);
    }

    /**
     * Hide the domain in the UI until they click the eye again.
     *
     * Keeps the disclosure row so audits and pace history stay intact.
     */
    public function conceal(User $user, Site $site): void
    {
        $this->ensureSchema();

        if (! $this->tableAvailable() || ! $this->concealColumnAvailable()) {
            $this->revealCache[(int) $user->id][(int) $site->id] = false;

            return;
        }

        $row = SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->where('site_id', $site->id)
            ->first();

        if (! $row) {
            throw new \InvalidArgumentException(
                'Reveal this address with the eye first — then you can hide it again.'
            );
        }

        if ($row->concealed_at === null) {
            $row->concealed_at = now();
            $row->save();
        }

        $this->revealCache[(int) $user->id][(int) $site->id] = false;
    }

    /**
     * Has this advertiser actually bought anything?
     *
     * Gates nothing — browsing is unlimited — but it is the column that answers
     * "customer or research project" on the admin activity screen.
     */
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
            Log::warning('Could not classify advertiser', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Domains this advertiser has ever been shown — including ones they hid.
     *
     * Search uses this so typing a host they already know still finds the row
     * after they closed the eye.
     *
     * @return Collection<int, int>
     */
    public function revealedSiteIds(?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        $this->ensureSchema();

        if (! $this->tableAvailable()) {
            return collect();
        }

        return SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->pluck('site_id')
            ->map(fn ($id) => (int) $id);
    }

    private function tableAvailable(): bool
    {
        if (self::$tableAvailableCache !== null) {
            return self::$tableAvailableCache;
        }

        try {
            return self::$tableAvailableCache = Schema::hasTable('site_url_reveals');
        } catch (\Throwable) {
            return self::$tableAvailableCache = false;
        }
    }

    private function concealColumnAvailable(): bool
    {
        if (self::$concealColumnAvailableCache !== null) {
            return self::$concealColumnAvailableCache;
        }

        try {
            return self::$concealColumnAvailableCache = Schema::hasColumn('site_url_reveals', 'concealed_at');
        } catch (\Throwable) {
            return self::$concealColumnAvailableCache = false;
        }
    }

    /** @internal Reset memoised state between tests. */
    public function flush(): void
    {
        $this->revealCache = [];
        self::forgetSchemaCache();
    }
}
