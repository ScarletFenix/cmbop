<?php

namespace App\Support;

/**
 * Marketing URL slugs per public locale.
 *
 * English (unprefixed) and US English keep the original paths.
 * Other prefixed locales use a translated first segment so
 * /de/ueber-uns is the canonical About URL, not /de/about.
 */
class LocalizedPublicPath
{
    /**
     * English path key → localized first segment.
     * Omitted keys (blog, newsletter, home) stay English in every locale.
     *
     * @return array<string, array<string, string>>
     */
    public static function map(): array
    {
        return [
            'de' => [
                'about' => 'ueber-uns',
                'faq' => 'haeufige-fragen',
                'pricing' => 'preise',
                'marketplace' => 'marktplatz',
                'how-it-works' => 'so-funktioniert-es',
                'become-a-publisher' => 'publisher-werden',
                'why-choose-us' => 'warum-wir',
                'contact' => 'kontakt',
                'privacy-policy' => 'datenschutz',
                'terms-of-services' => 'agb',
                'cookie-policy' => 'cookie-richtlinie',
                'refund-policy' => 'rueckerstattung',
            ],
            'fr' => [
                'about' => 'a-propos',
                'faq' => 'faq',
                'pricing' => 'tarifs',
                'marketplace' => 'marche',
                'how-it-works' => 'comment-ca-marche',
                'become-a-publisher' => 'devenir-editeur',
                'why-choose-us' => 'pourquoi-nous',
                'contact' => 'contact',
                'privacy-policy' => 'confidentialite',
                'terms-of-services' => 'conditions-generales',
                'cookie-policy' => 'cookies',
                'refund-policy' => 'remboursement',
            ],
            'nl' => [
                'about' => 'over-ons',
                'faq' => 'veelgestelde-vragen',
                'pricing' => 'prijzen',
                'marketplace' => 'marktplaats',
                'how-it-works' => 'hoe-het-werkt',
                'become-a-publisher' => 'publisher-worden',
                'why-choose-us' => 'waarom-wij',
                'contact' => 'contact',
                'privacy-policy' => 'privacybeleid',
                'terms-of-services' => 'voorwaarden',
                'cookie-policy' => 'cookiebeleid',
                'refund-policy' => 'restitutie',
            ],
            'es' => [
                'about' => 'sobre-nosotros',
                'faq' => 'preguntas-frecuentes',
                'pricing' => 'precios',
                'marketplace' => 'mercado',
                'how-it-works' => 'como-funciona',
                'become-a-publisher' => 'convertirse-en-publisher',
                'why-choose-us' => 'por-que-elegirnos',
                'contact' => 'contacto',
                'privacy-policy' => 'privacidad',
                'terms-of-services' => 'terminos',
                'cookie-policy' => 'cookies',
                'refund-policy' => 'reembolso',
            ],
            'it' => [
                'about' => 'chi-siamo',
                'faq' => 'domande-frequenti',
                'pricing' => 'prezzi',
                'marketplace' => 'mercato',
                'how-it-works' => 'come-funziona',
                'become-a-publisher' => 'diventa-publisher',
                'why-choose-us' => 'perche-sceglierci',
                'contact' => 'contatto',
                'privacy-policy' => 'privacy',
                'terms-of-services' => 'termini',
                'cookie-policy' => 'cookie',
                'refund-policy' => 'rimborso',
            ],
        ];
    }

    /**
     * English marketing first segments (from config + translatable keys).
     *
     * @return list<string>
     */
    public static function englishKeys(): array
    {
        $fromConfig = array_values(array_filter(
            config('i18n.public_paths', []),
            fn ($path) => is_string($path) && $path !== ''
        ));

        $fromMap = [];
        foreach (self::map() as $localeMap) {
            foreach (array_keys($localeMap) as $english) {
                $fromMap[] = $english;
            }
        }

        return array_values(array_unique(array_merge($fromConfig, $fromMap)));
    }

    /**
     * Localized first segment for an English path key.
     */
    public static function for(string $englishPath, ?string $locale): string
    {
        $englishPath = trim($englishPath, '/');
        if ($englishPath === '' || ! PublicI18n::isSupported($locale) || $locale === PublicI18n::default() || $locale === 'us') {
            return $englishPath;
        }

        return self::map()[$locale][$englishPath] ?? $englishPath;
    }

    /**
     * Map a (possibly localized) first segment back to the English key.
     */
    public static function toEnglish(string $segment): string
    {
        $segment = trim($segment, '/');
        if ($segment === '') {
            return '';
        }

        foreach (self::map() as $localeMap) {
            $english = array_search($segment, $localeMap, true);
            if ($english !== false) {
                return $english;
            }
        }

        return $segment;
    }

    /**
     * Translate the first path segment into $locale; keep the rest (blog slugs, etc.).
     */
    public static function localize(string $path, ?string $locale): string
    {
        $path = ltrim($path, '/');
        if ($path === '') {
            return '';
        }

        $parts = explode('/', $path);
        $parts[0] = self::for(self::toEnglish($parts[0]), $locale);

        return implode('/', $parts);
    }

    /**
     * Normalize a public path to English keys (ueber-uns → about).
     */
    public static function canonicalize(string $path): string
    {
        $path = ltrim($path, '/');
        if ($path === '') {
            return '';
        }

        $parts = explode('/', $path);
        $parts[0] = self::toEnglish($parts[0]);

        return implode('/', $parts);
    }

    /**
     * Host-relative public URL path including the locale prefix when needed.
     */
    public static function publicPath(string $englishPath, ?string $locale): string
    {
        $locale = $locale ?? PublicI18n::default();
        $localized = self::for($englishPath, $locale);

        if (! PublicI18n::isPrefixed($locale)) {
            return $localized === '' ? '/' : '/'.$localized;
        }

        return $localized === '' ? '/'.$locale : '/'.$locale.'/'.$localized;
    }

    /**
     * First segments that still count as public marketing (switcher / hreflang).
     *
     * @return list<string>
     */
    public static function allFirstSegments(): array
    {
        $segments = self::englishKeys();
        foreach (self::map() as $localeMap) {
            foreach ($localeMap as $localized) {
                $segments[] = $localized;
            }
        }

        return array_values(array_unique($segments));
    }

    /**
     * English path → localized slug, only when they differ.
     *
     * @return array<string, string>
     */
    public static function legacyRedirects(string $locale): array
    {
        $out = [];
        foreach (self::map()[$locale] ?? [] as $english => $localized) {
            if ($english !== $localized) {
                $out[$english] = $localized;
            }
        }

        return $out;
    }
}
