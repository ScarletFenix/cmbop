<?php

namespace App\Support;

use App\Models\User;

/**
 * Admin vs marketing workspace URLs.
 *
 * Shared staff pages live under both /admin and /marketing. Bells and mail
 * must use the recipient's prefix — marketers hitting leftover /admin links
 * for non-ops paths are dumped on the marketing dashboard.
 */
class StaffWorkspace
{
    public static function usesMarketingWorkspace(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isAdmin() || $user->hasRole('admin')) {
            return false;
        }

        return $user->isMarketing() || $user->hasRole('marketing');
    }

    public static function routePrefixFor(?User $user): string
    {
        return self::usesMarketingWorkspace($user) ? 'marketing' : 'admin';
    }

    public static function routeFor(?User $user, string $name, mixed $parameters = [], bool $absolute = true): string
    {
        return route(self::routePrefixFor($user).'.'.ltrim($name, '.'), $parameters, $absolute);
    }

    /**
     * Paths under /admin/* that marketing can actually open (mirrored on /marketing).
     * Keep in lockstep with RedirectMarketingFromAdmin.
     */
    public static function isMarketingOpsPath(string $rest): bool
    {
        if ($rest === '' || $rest === 'dashboard' || $rest === 'history' || str_starts_with($rest, 'history/')) {
            return true;
        }
        if (str_starts_with($rest, 'sites')) {
            if (preg_match('#^sites/\d+/verify$#', $rest) === 1) {
                return false;
            }

            return true;
        }
        if (str_starts_with($rest, 'bulk-site-requests')) {
            return true;
        }
        if (str_starts_with($rest, 'site-enrichment')) {
            return true;
        }
        if (preg_match('#^users/\d+/sites$#', $rest) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Rewrite a stored /admin/{ops} URL to /marketing/{ops} for marketing-only users.
     * Community/claims and other admin-only paths stay on /admin (middleware
     * sends marketers to the dashboard rather than a 404).
     */
    public static function actionUrlFor(?User $user, ?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        if (! self::usesMarketingWorkspace($user)) {
            return $url;
        }

        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        if ($path === '' || $path === false) {
            return $url;
        }
        if (! str_starts_with($path, '/admin')) {
            return $url;
        }

        $rest = ltrim((string) preg_replace('#^/admin/?#', '', $path), '/');
        if (! self::isMarketingOpsPath($rest)) {
            return $url;
        }

        $newPath = '/marketing/'.($rest !== '' ? $rest : 'dashboard');

        if (isset($parts['host'])) {
            $rebuilt = (isset($parts['scheme']) ? $parts['scheme'].'://' : '//').$parts['host'];
            if (isset($parts['port'])) {
                $rebuilt .= ':'.$parts['port'];
            }
            $rebuilt .= $newPath;
            if (isset($parts['query'])) {
                $rebuilt .= '?'.$parts['query'];
            }
            if (isset($parts['fragment'])) {
                $rebuilt .= '#'.$parts['fragment'];
            }

            return $rebuilt;
        }

        $target = $newPath;
        if (isset($parts['query'])) {
            $target .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $target .= '#'.$parts['fragment'];
        }

        return $target;
    }
}
