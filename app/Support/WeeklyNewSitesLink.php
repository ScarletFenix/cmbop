<?php

namespace App\Support;

use App\Models\User;
use App\Models\WeeklyNewSitesToken;
use App\Services\Catalog\CatalogUrlQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Opaque signed tokens for digest unsubscribe, preferences, and click-through.
 *
 * The public URL carries a random token, not a user id. Destinations are stored
 * server-side so the query string cannot open-redirect.
 */
class WeeklyNewSitesLink
{
    public static function expireDays(): int
    {
        return max(1, (int) config('weekly_new_sites_digest.unsubscribe_expire_days', 30));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function mint(User $user, string $purpose, array $payload = [], ?Carbon $expiresAt = null): string
    {
        if (EmailCatalog::isPreviewUser($user) || (int) $user->id < 1) {
            $path = (string) ($payload['path'] ?? '/advertiser/catalog');
            if (! self::isSafeInternalPath($path)) {
                $path = '/advertiser/catalog';
            }

            return rtrim(app_public_url(), '/').$path;
        }

        $plain = Str::lower(Str::random(48));
        if (WeeklyNewSitesToken::tableReady()) {
            WeeklyNewSitesToken::query()->create([
                'token_hash' => self::hash($plain),
                'user_id' => $user->id,
                'purpose' => $purpose,
                'payload' => $payload,
                'expires_at' => $expiresAt ?? now()->addDays(self::expireDays()),
            ]);
        }

        $relative = URL::temporarySignedRoute(
            'weekly-new-sites.action',
            $expiresAt ?? now()->addDays(self::expireDays()),
            ['token' => $plain],
            absolute: false
        );

        return rtrim(app_public_url(), '/').$relative;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function find(string $plain): ?WeeklyNewSitesToken
    {
        if (! WeeklyNewSitesToken::tableReady() || $plain === '') {
            return null;
        }

        return WeeklyNewSitesToken::query()
            ->where('token_hash', self::hash($plain))
            ->first();
    }

    /**
     * Allowlisted advertiser-catalog path (no host, no protocol).
     *
     * @param  array<string, mixed>  $query
     */
    public static function catalogPath(array $query = []): string
    {
        $allowed = [];
        foreach (['site', 'category', 'country'] as $key) {
            if (! array_key_exists($key, $query)) {
                continue;
            }
            $value = $query[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $allowed[$key] = is_scalar($value) ? (string) $value : '';
        }

        if (class_exists(CatalogUrlQuery::class) && $allowed !== []) {
            $allowed = CatalogUrlQuery::canonicalize($allowed);
        }

        $path = '/advertiser/catalog';
        if ($allowed === []) {
            return $path;
        }

        return $path.'?'.http_build_query($allowed);
    }

    public static function isSafeInternalPath(string $path): bool
    {
        $path = trim($path);
        if ($path === '' || ! str_starts_with($path, '/')) {
            return false;
        }
        if (str_starts_with($path, '//') || str_contains($path, '://') || str_contains($path, '\\')) {
            return false;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }

        $parsed = parse_url($path);
        $onlyPath = $parsed['path'] ?? '';

        return in_array($onlyPath, [
            '/advertiser/catalog',
            '/profile/notifications',
            '/advertiser/new-site-digest',
        ], true);
    }
}
