<?php

namespace App\Services\Catalog;

use Illuminate\Http\Request;

/**
 * Catalog listing query string — the allowlisted source of truth for
 * refresh, share links, filter chips, and (later) live results fetches.
 */
class CatalogUrlQuery
{
    /**
     * Keys that define catalog listing state.
     *
     * @var list<string>
     */
    public const KEYS = [
        'search',
        'category',
        'country',
        'language',
        'price_min',
        'price_max',
        'da_min',
        'da_max',
        'dr_min',
        'dr_max',
        'traffic_min',
        'traffic_max',
        'sponsored',
        'favorites_filter',
        'blacklist_filter',
        'new_badge',
        'verified',
        'quality',
        'site',
        'sort',
        'page',
        // Contextual chrome — keep across filter navigation when present.
        'wizard',
    ];

    /** Server / form default sort — omit from the URL when unchanged. */
    public const DEFAULT_SORT = 'dr_desc';

    /**
     * @return array<string, string>
     */
    public static function fromRequest(Request $request): array
    {
        return self::canonicalize($request->query());
    }

    /**
     * Keep allowlisted keys with non-empty scalar values.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function fromArray(array $input): array
    {
        $out = [];

        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];
            if ($value === null || is_array($value)) {
                continue;
            }

            $string = trim((string) $value);
            if ($string === '') {
                continue;
            }

            $out[$key] = $string;
        }

        return $out;
    }

    /**
     * Drop empty defaults so refresh/share URLs stay stable and short.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function canonicalize(array $input): array
    {
        $params = self::fromArray($input);

        if (($params['sort'] ?? null) === self::DEFAULT_SORT) {
            unset($params['sort']);
        }

        if (($params['page'] ?? null) === '1') {
            unset($params['page']);
        }

        return $params;
    }

    /**
     * Build query for chip-remove / clear-filter links.
     *
     * @param  array<string, mixed>  $query
     * @param  list<string>  $drop
     * @return array<string, string>
     */
    public static function except(array $query, array $drop, bool $dropPage = true): array
    {
        $clean = self::fromArray($query);

        foreach ($drop as $key) {
            unset($clean[$key]);
        }

        if ($dropPage) {
            unset($clean['page']);
        }

        return self::canonicalize($clean);
    }
}
