<?php

namespace App\Support;

use App\Services\Marketing\GuestPostPriceIndex;

/**
 * Country landers, the Europe price index, and the trust FAQ stay on unprefixed English URLs.
 * Kept in a small class so leftover Hostinger PublicI18n.php (missing the
 * method, or truncated in File Manager) does not 500 public pages.
 */
class EnglishOnlyMarketingSlugs
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $slugs = ['guest-post-prices-europe', 'guest-post-prices-europe.csv', 'is-seolinkbuildings-safe', 'author'];

        try {
            if (class_exists(EnglishCommercialLanders::class) && method_exists(EnglishCommercialLanders::class, 'slugs')) {
                foreach (EnglishCommercialLanders::slugs() as $slug) {
                    $slug = trim((string) $slug);
                    if ($slug !== '') {
                        $slugs[] = $slug;
                    }
                }
            }
            if (class_exists(EnglishCommercialLanders::class) && method_exists(EnglishCommercialLanders::class, 'aliases')) {
                foreach (array_keys(EnglishCommercialLanders::aliases()) as $alias) {
                    $alias = trim((string) $alias);
                    if ($alias === '' || self::localeMoneyLanderUses($alias)) {
                        continue;
                    }
                    $slugs[] = $alias;
                }
            }
        } catch (\Throwable) {
        }

        try {
            if (
                class_exists(GuestPostPriceIndex::class)
                && defined(GuestPostPriceIndex::class.'::SLUG')
            ) {
                $indexSlug = trim((string) GuestPostPriceIndex::SLUG);
                if ($indexSlug !== '') {
                    $slugs = array_values(array_unique(array_merge([$indexSlug], $slugs)));
                }
            }
        } catch (\Throwable) {
        }

        try {
            if (class_exists(CountryLander::class) && method_exists(CountryLander::class, 'slugs')) {
                foreach (CountryLander::slugs() as $slug) {
                    $slug = trim((string) $slug);
                    if ($slug !== '') {
                        $slugs[] = $slug;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return array_values(array_unique($slugs));
    }

    /**
     * English alias 301s stay unprefixed. Do not English-only-lock a slug that
     * a locale money lander already serves (for example /de/advertorial).
     */
    private static function localeMoneyLanderUses(string $segment): bool
    {
        if (! class_exists(MoneyLanderCatalog::class) || ! method_exists(MoneyLanderCatalog::class, 'classes')) {
            return false;
        }

        try {
            foreach (MoneyLanderCatalog::classes() as $class) {
                if (! is_string($class) || ! class_exists($class)) {
                    continue;
                }
                if (method_exists($class, 'isSlug') && $class::isSlug($segment)) {
                    return true;
                }
                if (method_exists($class, 'aliases')) {
                    $aliases = $class::aliases();
                    if (is_array($aliases) && array_key_exists($segment, $aliases)) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
