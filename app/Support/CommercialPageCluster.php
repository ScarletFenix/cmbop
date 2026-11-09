<?php

namespace App\Support;

/**
 * Cross-locale equivalents for English commercial landers and their
 * locale money pages. Same-slug clusters (digital-pr, niche-edits) stay
 * on MoneyLanderCatalog; this map is only for different paths.
 */
class CommercialPageCluster
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function maps(): array
    {
        return [
            'agencies' => [
                'en' => 'for-agencies',
                'de' => 'agenturen',
                'at' => 'agenturen',
                'ch' => 'agenturen',
                'fr' => 'agences',
                'nl' => 'bureaus',
                'es' => 'agencias',
                'it' => 'agenzie',
                'pt' => 'agencias',
                'ro' => 'agentii',
                'dk' => 'bureauer',
                'se' => 'byraer',
                'no' => 'for-byraer',
                'bg' => 'agencii',
                'hu' => 'ugynoksegek',
                'ee' => 'agentuurid',
                'pl' => 'agencje',
            ],
            'sponsored' => [
                'en' => 'sponsored-articles',
                'de' => 'advertorial',
                'at' => 'advertorial',
                'ch' => 'advertorial',
                'fr' => 'article-sponsorise',
                'nl' => 'gesponsord-artikel',
                'es' => 'articulo-patrocinado',
                'it' => 'articoli-sponsorizzati',
                'pt' => 'artigo-patrocinado',
                'ro' => 'articol-sponsorizat',
                'dk' => 'sponsoreret-artikel',
                'se' => 'sponsrat-inlagg',
                'no' => 'sponset-artikkel',
                'bg' => 'sponsorirana-statiya',
                'hu' => 'szponzoralt-cikk',
                'ee' => 'sponsoreeritud-artikkel',
                'pl' => 'artykul-sponsorowany',
            ],
            'alternatives' => [
                'en' => 'alternatives',
                'es' => 'alternativas',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function englishSlugs(): array
    {
        $slugs = [];
        foreach (self::maps() as $map) {
            $slug = trim((string) ($map['en'] ?? ''));
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    public static function owns(string $slug): bool
    {
        return self::pathByLocale($slug) !== [];
    }

    public static function hasEnglishAlternate(string $slug): bool
    {
        $paths = self::pathByLocale($slug);

        return isset($paths['en']) && $paths['en'] !== '';
    }

    /**
     * @return array<string, string>
     */
    public static function pathByLocale(string $slug): array
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return [];
        }

        foreach (self::maps() as $map) {
            if (! in_array($slug, $map, true)) {
                continue;
            }

            $out = [];
            foreach ($map as $locale => $path) {
                $locale = strtolower(trim((string) $locale));
                $path = trim((string) $path, '/');
                if ($locale === '' || $path === '') {
                    continue;
                }
                if ($locale === 'en') {
                    $out['en'] = $path;

                    continue;
                }
                if (! self::localeOwnsPath($locale, $path)) {
                    continue;
                }
                $out[$locale] = $path;
            }

            return $out;
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public static function locales(string $slug): array
    {
        return array_keys(self::pathByLocale($slug));
    }

    public static function urlForLocale(string $slug, string $locale): ?string
    {
        $paths = self::pathByLocale($slug);
        $locale = strtolower(trim($locale));
        $path = $paths[$locale] ?? null;
        if (! is_string($path) || $path === '') {
            return null;
        }

        if ($locale === 'en' || (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'default') && $locale === PublicI18n::default())) {
            return url('/'.$path);
        }

        return url('/'.$locale.'/'.$path);
    }

    private static function localeOwnsPath(string $locale, string $path): bool
    {
        if (! class_exists(MoneyLanderCatalog::class) || ! method_exists(MoneyLanderCatalog::class, 'classFor')) {
            return false;
        }

        $class = MoneyLanderCatalog::classFor($locale);
        if (! is_string($class) || ! class_exists($class) || ! method_exists($class, 'isSlug')) {
            return false;
        }

        try {
            return $class::isSlug($path);
        } catch (\Throwable) {
            return false;
        }
    }
}
