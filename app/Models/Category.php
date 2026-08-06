<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Category extends Model
{
    protected $fillable = ['name', 'group'];

    public function sites()
    {
        return $this->hasMany(Site::class, 'category', 'name');
    }

    /**
     * Resolve submitted niche labels to canonical Category::name values.
     *
     * Accepts exact niche names and group aliases (e.g. "Technology" →
     * "Technology & Gadgets"). HTML entities are decoded first so Blade-escaped
     * multi-select values still match.
     *
     * @param  iterable<int, mixed>|string|null  $raw
     * @return array{resolved: list<string>, unknown: list<string>}
     */
    public static function resolveNicheNames(iterable|string|null $raw): array
    {
        $inputs = self::normalizeNicheInputs($raw);
        if ($inputs === []) {
            return ['resolved' => [], 'unknown' => []];
        }

        $maps = self::nicheLookupMaps();
        $resolved = [];
        $unknown = [];

        foreach ($inputs as $input) {
            $key = strtolower($input);
            if (isset($maps['by_name'][$key])) {
                $resolved[] = $maps['by_name'][$key];

                continue;
            }
            if (isset($maps['by_group'][$key])) {
                $resolved[] = $maps['by_group'][$key];

                continue;
            }
            $unknown[] = $input;
        }

        return [
            'resolved' => array_values(array_unique($resolved)),
            'unknown' => array_values(array_unique($unknown)),
        ];
    }

    /**
     * @param  iterable<int, mixed>|string|null  $raw
     * @return list<string>
     */
    public static function normalizeNicheInputs(iterable|string|null $raw): array
    {
        $normalize = static function ($v): string {
            $decoded = html_entity_decode(trim((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return trim($decoded);
        };

        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $str = $normalize($raw);
            if ($str === '') {
                return [];
            }

            return array_values(array_filter(array_map($normalize, preg_split('/\|/', $str) ?: [])));
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                foreach (self::normalizeNicheInputs($item) as $nested) {
                    $out[] = $nested;
                }

                continue;
            }
            $name = $normalize($item);
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return array_values($out);
    }

    /**
     * @return array{by_name: array<string, string>, by_group: array<string, string>}
     */
    public static function nicheLookupMaps(): array
    {
        return Cache::remember('category_niche_lookup_maps', 300, function () {
            $categories = static::query()
                ->orderBy('id')
                ->get(['id', 'name', 'group']);

            $byName = [];
            $byGroup = [];
            $grouped = [];

            foreach ($categories as $category) {
                $name = (string) $category->name;
                $group = trim((string) ($category->group ?? ''));
                $byName[strtolower($name)] = $name;
                if ($group !== '') {
                    $grouped[$group][] = $category;
                }
            }

            foreach ($grouped as $group => $rows) {
                $exact = null;
                $prefix = null;
                foreach ($rows as $row) {
                    $name = (string) $row->name;
                    if (strcasecmp($name, $group) === 0) {
                        $exact = $name;
                        break;
                    }
                    if ($prefix === null && str_starts_with(strtolower($name), strtolower($group))) {
                        $prefix = $name;
                    }
                }
                $byGroup[strtolower($group)] = $exact
                    ?? $prefix
                    ?? (string) $rows[0]->name;
            }

            return [
                'by_name' => $byName,
                'by_group' => $byGroup,
            ];
        });
    }

    /**
     * Forget cached niche lookup maps (tests / after category changes).
     */
    public static function flushNicheLookupCache(): void
    {
        Cache::forget('category_niche_lookup_maps');
    }
}
