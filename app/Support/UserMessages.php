<?php

namespace App\Support;

class UserMessages
{
    public static function get(string $key, ?string $default = null): string
    {
        $line = __('errors.'.$key);
        if (! is_string($line) || $line === '' || $line === 'errors.'.$key) {
            return $default ?? (string) __('errors.generic.retry');
        }

        return $line;
    }
}
