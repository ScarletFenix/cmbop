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
}
