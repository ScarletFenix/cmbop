<?php

namespace App\Support;

use App\Models\Blog;
use App\Models\BlogTranslation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;

class PublicI18n
{
    public static function supported(): array
    {
        return config('i18n.supported', ['en', 'de', 'fr', 'nl', 'es', 'it', 'us']);
    }

    public static function prefixed(): array
    {
        return config('i18n.prefixed', ['de', 'fr', 'nl', 'es', 'it', 'us']);
    }

    public static function prefixedPattern(): string
    {
        return implode('|', self::prefixed());
    }

    public static function supportedPattern(): string
    {
        return implode('|', self::supported());
    }

    /**
     * BCP 47 tag for hreflang / html lang (en = UK, us = US).
     */
    public static function hreflang(string $locale): string
    {
        return match ($locale) {
            'en' => 'en-GB',
            'us' => 'en-US',
            default => $locale,
        };
    }

    public static function htmlLang(?string $locale = null): string
    {
        return self::hreflang($locale ?? App::getLocale());
    }

    /**
     * Open Graph locale (underscore form).
     */
    public static function ogLocale(?string $locale = null): string
    {
        $locale = $locale ?? App::getLocale();

        return match ($locale) {
            'en' => 'en_GB',
            'us' => 'en_US',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            'nl' => 'nl_NL',
            'es' => 'es_ES',
            'it' => 'it_IT',
            default => $locale.'_'.strtoupper($locale),
        };
    }

    /**
     * Map an Accept-Language / BCP 47 tag onto a supported public locale.
     */
    public static function fromBrowserTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        $normalized = strtolower(str_replace('_', '-', trim($tag)));
        if ($normalized === '') {
            return null;
        }

        $aliases = [
            'en-us' => 'us',
            'en-gb' => 'en',
            'en-uk' => 'en',
            'eng' => 'en',
            'uk' => 'en',
            'es-es' => 'es',
            'es-mx' => 'es',
            'es-ar' => 'es',
            'es-co' => 'es',
            'es-cl' => 'es',
            'it-it' => 'it',
        ];

        if (isset($aliases[$normalized]) && self::isSupported($aliases[$normalized])) {
            return $aliases[$normalized];
        }

        if (self::isSupported($normalized)) {
            return $normalized;
        }

        $base = explode('-', $normalized)[0];
        if ($base === 'en' && self::isSupported('en')) {
            return 'en';
        }

        return self::isSupported($base) ? $base : null;
    }

    public static function default(): string
    {
        return config('i18n.default', 'en');
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::supported(), true);
    }

    public static function isPrefixed(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::prefixed(), true);
    }

    /**
     * @return array{0: ?string, 1: list<string>}
     */
    public static function splitPath(Request $request): array
    {
        $segments = $request->segments();
        $locale = null;

        if (! empty($segments) && self::isPrefixed($segments[0])) {
            $locale = $segments[0];
            array_shift($segments);
        }

        return [$locale, array_values($segments)];
    }

    public static function pathWithoutLocale(Request $request): string
    {
        [, $segments] = self::splitPath($request);

        return implode('/', $segments);
    }

    public static function firstPathSegment(Request $request): string
    {
        [, $segments] = self::splitPath($request);

        return $segments[0] ?? '';
    }

    public static function isEnglishOnlyPath(Request $request): bool
    {
        $first = self::firstPathSegment($request);
        if ($first === '') {
            return false;
        }

        foreach (config('i18n.english_only_paths', []) as $prefix) {
            if ($first === $prefix) {
                return true;
            }
        }

        return false;
    }

    /**
     * Public English landers / indexes that must not grow locale-prefixed copies.
     *
     * @return list<string>
     */
    public static function englishOnlyMarketingSlugs(): array
    {
        $slugs = ['guest-post-prices-europe'];
        if (class_exists(CountryLander::class)) {
            $slugs = array_merge($slugs, CountryLander::slugs());
        }

        return array_values(array_unique(array_filter($slugs, fn ($slug) => is_string($slug) && $slug !== '')));
    }

    public static function isEnglishOnlyMarketingPath(Request $request): bool
    {
        $first = self::firstPathSegment($request);

        return $first !== '' && in_array($first, self::englishOnlyMarketingSlugs(), true);
    }

    public static function isPublicMarketingPath(Request $request): bool
    {
        if (self::isEnglishOnlyPath($request)) {
            return false;
        }

        $first = self::firstPathSegment($request);
        $public = class_exists(LocalizedPublicPath::class)
            ? LocalizedPublicPath::allFirstSegments()
            : array_values(array_filter(
                config('i18n.public_paths', []),
                fn ($path) => is_string($path) && $path !== ''
            ));

        // Home
        if ($first === '') {
            return true;
        }

        return in_array($first, $public, true);
    }

    public static function shouldShowLanguageSwitcher(Request $request): bool
    {
        return self::isPublicMarketingPath($request);
    }

    public static function urlForLocale(string $path, ?string $locale = null): string
    {
        $locale = $locale ?? App::getLocale();
        $path = ltrim((string) $path, '/');
        if (class_exists(LocalizedPublicPath::class)) {
            $path = LocalizedPublicPath::localize($path, $locale);
        }

        if (! self::isSupported($locale) || $locale === self::default()) {
            return $path === '' ? url('/') : url($path);
        }

        return $path === '' ? url($locale) : url($locale.'/'.$path);
    }

    public static function switchUrl(Request $request, string $targetLocale): string
    {
        $path = self::pathWithoutLocale($request);
        if (class_exists(LocalizedPublicPath::class)) {
            $path = LocalizedPublicPath::canonicalize($path);
        }

        if (self::isEnglishOnlyPath($request) || self::isEnglishOnlyMarketingPath($request)) {
            if (self::isEnglishOnlyMarketingPath($request) && $targetLocale === self::default()) {
                return $path === '' ? url('/') : url($path);
            }

            return self::urlForLocale('', $targetLocale);
        }

        if (preg_match('#^blog/([^/]+)$#', $path, $matches) === 1) {
            $path = self::blogPathForLocale($matches[1], $targetLocale);
        }

        return self::urlForLocale($path, $targetLocale);
    }

    /**
     * Swap /blog/{slug} to the published slug for $targetLocale when we know the post.
     */
    public static function blogPathForLocale(string $slug, string $targetLocale): string
    {
        try {
            if (! Schema::hasTable('blog_translations')) {
                return 'blog/'.$slug;
            }

            $hit = BlogTranslation::query()
                ->where('slug', $slug)
                ->where('is_published', true)
                ->first();

            $blog = null;
            if ($hit) {
                $blog = Blog::published()
                    ->with(['translations' => function ($query) {
                        $query->where('is_published', true);
                    }])
                    ->where('id', $hit->blog_id)
                    ->first();
            }

            if (! $blog) {
                $blog = Blog::published()
                    ->with(['translations' => function ($query) {
                        $query->where('is_published', true);
                    }])
                    ->where('slug', $slug)
                    ->first();
            }

            if (! $blog) {
                return 'blog';
            }

            $display = $blog->translationFor($targetLocale, null);
            if ($display && filled($display->slug)) {
                return 'blog/'.$display->slug;
            }

            return 'blog';
        } catch (\Throwable) {
            return 'blog/'.$slug;
        }
    }

    /**
     * @return list<array{hreflang: string, href: string}>
     */
    public static function hreflangTags(
        Request $request,
        ?string $xDefaultLocale = null,
        ?array $locales = null,
        ?string $pathOverride = null,
        ?array $pathByLocale = null
    ): array {
        if (! self::isPublicMarketingPath($request)) {
            return [];
        }

        $path = $pathOverride !== null ? ltrim($pathOverride, '/') : self::pathWithoutLocale($request);
        if (class_exists(LocalizedPublicPath::class)) {
            $path = LocalizedPublicPath::canonicalize($path);
        }

        $first = $path === '' ? '' : explode('/', $path, 2)[0];
        if ($locales === null && $first !== '' && in_array($first, self::englishOnlyMarketingSlugs(), true)) {
            $locales = [self::default()];
            $xDefaultLocale = self::default();
        }

        $tags = [];
        $targetLocales = $locales ?: self::supported();
        $targetLocales = array_values(array_filter($targetLocales, fn ($locale) => self::isSupported($locale)));

        if ($targetLocales === []) {
            $targetLocales = self::supported();
        }

        foreach ($targetLocales as $locale) {
            $fallbackPath = class_exists(LocalizedPublicPath::class)
                ? LocalizedPublicPath::localize($path, $locale)
                : $path;
            $localePath = ltrim((string) ($pathByLocale[$locale] ?? $fallbackPath), '/');
            $tags[] = [
                'hreflang' => self::hreflang($locale),
                'href' => self::urlForLocale($localePath, $locale),
            ];
        }

        $xDefault = self::isSupported($xDefaultLocale) ? $xDefaultLocale : self::default();
        $xDefaultFallback = class_exists(LocalizedPublicPath::class)
            ? LocalizedPublicPath::localize($path, $xDefault)
            : $path;
        $xDefaultPath = ltrim((string) ($pathByLocale[$xDefault] ?? $xDefaultFallback), '/');

        $tags[] = [
            'hreflang' => 'x-default',
            'href' => self::urlForLocale($xDefaultPath, $xDefault),
        ];

        return $tags;
    }

    /**
     * Short admin / fallback label (en → UK).
     */
    public static function shortLabel(string $locale): string
    {
        return $locale === 'en' ? 'UK' : strtoupper($locale);
    }

    public static function preferredFromBrowser(Request $request): ?string
    {
        foreach ($request->getLanguages() as $tag) {
            $mapped = self::fromBrowserTag($tag);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }

    public static function rememberedPublicLocale(Request $request): string
    {
        $cookie = $request->cookie(config('i18n.cookie', 'public_locale'));

        return self::isSupported($cookie) ? $cookie : self::default();
    }
}
