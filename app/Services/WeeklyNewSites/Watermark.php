<?php

namespace App\Services\WeeklyNewSites;

use App\Models\WeeklyNewSitesSetting;
use Illuminate\Support\Carbon;

/**
 * One-time activation watermark. Sites first catalogued at or before this
 * instant are never "new" for the digest, so installing the feature cannot
 * blast the existing catalogue.
 */
class Watermark
{
    public const KEY = 'activated_at';

    public function establishedAt(): ?Carbon
    {
        $raw = WeeklyNewSitesSetting::getValue(self::KEY);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    public function establish(?Carbon $at = null): Carbon
    {
        $existing = $this->establishedAt();
        if ($existing) {
            return $existing;
        }

        $stamp = $at ? $at->copy() : now();
        WeeklyNewSitesSetting::putValue(self::KEY, $stamp->toIso8601String());

        return $stamp;
    }

    public function replace(Carbon $at): Carbon
    {
        WeeklyNewSitesSetting::putValue(self::KEY, $at->copy()->toIso8601String());

        return $at->copy();
    }
}
