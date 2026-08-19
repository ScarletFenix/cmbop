<?php

namespace App\Support;

class UserMessages
{
    /**
     * @param  array<string, scalar>  $replace
     */
    public static function get(string $key, array $replace = [], ?string $default = null): string
    {
        $line = __('errors.'.$key, $replace);
        if (! is_string($line) || $line === '' || $line === 'errors.'.$key) {
            return $default ?? (string) __('errors.generic.retry');
        }

        return $line;
    }
}
