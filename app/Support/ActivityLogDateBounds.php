<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Inclusive calendar-day windows in the app timezone, stored as UTC bounds.
 */
class ActivityLogDateBounds
{
    /**
     * @return array{0: ?Carbon, 1: ?Carbon, 2: list<string>, 3: bool}
     */
    public static function parseRange(mixed $from, mixed $to): array
    {
        $errors = [];
        $fromBound = null;
        $toBound = null;
        $ok = true;

        if (self::provided($from)) {
            $fromBound = self::parseDay($from, true);
            if (! $fromBound) {
                $errors[] = 'Use a valid From date.';
                $ok = false;
            }
        }

        if (self::provided($to)) {
            $toBound = self::parseDay($to, false);
            if (! $toBound) {
                $errors[] = 'Use a valid To date.';
                $ok = false;
            }
        }

        if ($fromBound && $toBound && $fromBound->gt($toBound)) {
            $errors[] = 'From date must be on or before To date.';
            $ok = false;
        }

        return [$fromBound, $toBound, $errors, $ok];
    }

    /**
     * Apply a valid range to created_at. Invalid / inverted dates are ignored.
     *
     * @return list<string>
     */
    public static function apply(Builder $query, mixed $from, mixed $to): array
    {
        [$fromBound, $toBound, $errors, $ok] = self::parseRange($from, $to);

        if ($ok) {
            if ($fromBound) {
                $query->where('created_at', '>=', $fromBound);
            }
            if ($toBound) {
                $query->where('created_at', '<=', $toBound);
            }
        }

        return $errors;
    }

    public static function parseDay(mixed $value, bool $start): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $local = Carbon::createFromFormat('Y-m-d', $value, self::timezone());
        } catch (\Throwable) {
            return null;
        }

        if (! $local || $local->format('Y-m-d') !== $value) {
            return null;
        }

        return $start
            ? $local->copy()->startOfDay()->utc()
            : $local->copy()->endOfDay()->utc();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function todayBounds(): array
    {
        $today = now()->timezone(self::timezone());

        return [
            $today->copy()->startOfDay()->utc(),
            $today->copy()->endOfDay()->utc(),
        ];
    }

    public static function todayDateString(): string
    {
        return now()->timezone(self::timezone())->toDateString();
    }

    public static function timezone(): string
    {
        return config('app.timezone') ?: 'UTC';
    }

    private static function provided(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return is_array($value) && $value !== [];
    }
}
