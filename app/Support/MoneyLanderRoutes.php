<?php

namespace App\Support;

use App\Http\Controllers\MarketingPageController;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Register prefixed money-lander URLs, unique-slug 301s, and aliases.
 */
class MoneyLanderRoutes
{
    /**
     * @param  list<string>  $prefixedLocales
     */
    public static function register(
        string $locale,
        string $class,
        string $controllerMethod,
        array $prefixedLocales
    ): void {
        if (! class_exists($class) || ! method_exists($class, 'slugs')) {
            return;
        }
        if (! method_exists(MarketingPageController::class, $controllerMethod)) {
            return;
        }

        try {
            $slugs = $class::slugs();
            $aliases = method_exists($class, 'aliases') ? $class::aliases() : [];
        } catch (Throwable) {
            return;
        }

        if ($slugs === []) {
            return;
        }

        Route::group([
            'prefix' => $locale,
            'as' => 'locale.'.$locale.'.money.',
        ], function () use ($slugs, $controllerMethod, $locale) {
            foreach ($slugs as $slug) {
                Route::get('/'.$slug, [MarketingPageController::class, $controllerMethod])
                    ->defaults('slug', $slug)
                    ->defaults('landerLocale', $locale)
                    ->name($slug);
            }
        });

        foreach ($slugs as $slug) {
            if (class_exists(MoneyLanderCatalog::class)
                && method_exists(MoneyLanderCatalog::class, 'anotherOwnsSlug')
                && MoneyLanderCatalog::anotherOwnsSlug($locale, $slug)) {
                continue;
            }

            if (! self::englishOwnsUnprefixed($slug)) {
                Route::get('/'.$slug, function () use ($locale, $slug) {
                    $query = request()->getQueryString();
                    $target = '/'.$locale.'/'.$slug;

                    return Redirect::to($query ? $target.'?'.$query : $target, 301);
                });
            }

            foreach ($prefixedLocales as $other) {
                if ($other === $locale) {
                    continue;
                }
                if (class_exists(MoneyLanderCatalog::class)
                    && method_exists(MoneyLanderCatalog::class, 'localeOwnsSegment')
                    && MoneyLanderCatalog::localeOwnsSegment($other, $slug)) {
                    continue;
                }

                Route::get('/'.$other.'/'.$slug, function () use ($locale, $slug) {
                    $query = request()->getQueryString();
                    $target = '/'.$locale.'/'.$slug;

                    return Redirect::to($query ? $target.'?'.$query : $target, 301);
                });
            }
        }

        foreach ($aliases as $from => $to) {
            Route::get('/'.$locale.'/'.$from, function () use ($to) {
                $query = request()->getQueryString();

                return Redirect::to($query ? $to.'?'.$query : $to, 301);
            });
        }
    }

    /**
     * English commercial landers (and their aliases) keep the unprefixed URL.
     * Locale unique-slug 301s must not steal GET /advertorial after those
     * aliases — artisan optimize last-route-wins would send it to /de/advertorial.
     */
    public static function englishOwnsUnprefixed(string $segment): bool
    {
        $segment = trim($segment, '/');
        if ($segment === '') {
            return false;
        }
        if (! class_exists(EnglishCommercialLanders::class)) {
            return false;
        }

        try {
            if (method_exists(EnglishCommercialLanders::class, 'isSlug')
                && EnglishCommercialLanders::isSlug($segment)) {
                return true;
            }
            if (method_exists(EnglishCommercialLanders::class, 'aliases')) {
                $aliases = EnglishCommercialLanders::aliases();
                if (is_array($aliases) && array_key_exists($segment, $aliases)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
