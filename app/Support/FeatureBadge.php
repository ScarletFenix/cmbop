<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class FeatureBadge
{
    public static function active(string $key): bool
    {
        $row = config('feature_badges.'.$key);
        if (! is_array($row) || ($row['enabled'] ?? true) === false) {
            return false;
        }

        $until = $row['until'] ?? null;
        if ($until === null || $until === '') {
            return true;
        }

        try {
            return now()->lessThanOrEqualTo(Carbon::parse((string) $until)->endOfDay());
        } catch (\Throwable) {
            return false;
        }
    }

    public static function label(string $key): string
    {
        $label = config('feature_badges.'.$key.'.label');

        return is_string($label) && $label !== '' ? $label : 'New';
    }
}
