<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Word-aware activity description match so "activate" does not hit "deactivated".
 */
class ActivityLogTextSearch
{
    public static function whereDescriptionHasWord(Builder $q, string $needle): void
    {
        $needle = mb_strtolower(trim($needle));
        if ($needle === '') {
            $q->whereRaw('0 = 1');

            return;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $needle);
        $pattern = '% '.$escaped.' %';
        $driver = $q->getConnection()->getDriverName();
        $haystack = in_array($driver, ['sqlite', 'pgsql'], true)
            ? "(' ' || LOWER(COALESCE(description, '')) || ' ')"
            : "CONCAT(' ', LOWER(COALESCE(description, '')), ' ')";

        $q->whereRaw($haystack.' LIKE ? ESCAPE ?', [$pattern, '\\']);
    }
}
