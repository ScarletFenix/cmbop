<?php

namespace App\Models\Concerns;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Hostinger leftovers sometimes store non-datetime strings in timestamp
 * columns. Laravel's datetime cast throws on read, dirty-diff, save, and
 * JSON serialization — 500ing staff edits and publisher promo AJAX.
 */
trait ToleratesUnparseableDates
{
    /**
     * SQL leftover dates compare as strings (SQLite) or zero-dates (MySQL).
     * Bound comparisons to this window so filters match PHP fail-closed helpers.
     */
    public const PLAUSIBLE_SQL_DATETIME_CEIL = '9999-12-31 23:59:59';

    public const PLAUSIBLE_SQL_DATETIME_FLOOR = '1970-01-01 00:00:01';

    /**
     * @param  mixed  $value
     * @return Carbon|null
     */
    protected function asDateTime($value)
    {
        try {
            return parent::asDateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Leftover timestamp strings become null in asDateTime(). Laravel still
     * passes that into serializeDate() for created_at / updated_at.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function addDateAttributesToArray(array $attributes)
    {
        foreach ($this->getDates() as $key) {
            if (is_null($key) || ! isset($attributes[$key])) {
                continue;
            }

            $date = $this->asDateTime($attributes[$key]);
            $attributes[$key] = $date instanceof DateTimeInterface
                ? $this->serializeDate($date)
                : null;
        }

        return $attributes;
    }

    /**
     * @param  mixed  $value
     */
    public function fromDateTime($value)
    {
        try {
            if (empty($value)) {
                return $value;
            }

            $date = $this->asDateTime($value);

            return $date instanceof DateTimeInterface
                ? $date->format($this->getDateFormat())
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True when the column still has a stored value, including leftover
     * strings that asDateTime() maps to null.
     */
    public function hasRawDateValue(string $attribute): bool
    {
        $raw = $this->getAttributes()[$attribute] ?? null;

        return $raw !== null && $raw !== '';
    }
}
