<?php

namespace App\Support;

class MailJobPayload
{
    public static function isQueuedMailable(string $payload): bool
    {
        return str_contains($payload, 'SendQueuedMailable');
    }

    public static function containsMailable(string $payload, string $class): bool
    {
        if ($class === '') {
            return false;
        }

        return str_contains($payload, $class)
            || str_contains($payload, str_replace('\\', '\\\\', $class));
    }

    /**
     * Match a recipient or dedupe key without treating "welcome:1" as "welcome:10".
     */
    public static function containsToken(string $payload, string $token): bool
    {
        $token = trim($token);
        if ($token === '' || strcasecmp($token, 'unknown') === 0) {
            return false;
        }

        if (str_contains($payload, json_encode($token, JSON_UNESCAPED_SLASHES))) {
            return true;
        }

        return str_contains($payload, 's:'.strlen($token).':"'.$token.'"');
    }

    public static function looksIdentified(string $payload): bool
    {
        if (str_contains($payload, 'dedupe_key') || str_contains($payload, 'dedupeKey')) {
            return true;
        }

        // Ignore Laravel's "Class@method" job targets; require a host with a dot.
        return (bool) preg_match('/[^\s"\\\\]+@[^\s"\\\\]+\.[a-z]{2,}/i', $payload);
    }
}
