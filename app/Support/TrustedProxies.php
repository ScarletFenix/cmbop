<?php

namespace App\Support;

/**
 * Who may set X-Forwarded-* / CF-Connecting-IP.
 * "*" is never honored — that let anyone reset login limits and mint extra €20.
 */
class TrustedProxies
{
    /**
     * @return list<string>
     */
    public static function addresses(): array
    {
        $raw = self::rawSetting();
        if ($raw === '' || strtolower($raw) === 'none' || $raw === '*') {
            return [];
        }

        if (strtolower($raw) === 'cloudflare') {
            return self::cloudflareCidrs();
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $ip) => $ip !== '' && $ip !== '*'
        ));
    }

    private static function rawSetting(): string
    {
        if (function_exists('app') && app()->bound('config')) {
            return trim((string) config('app.trusted_proxies', env('TRUSTED_PROXIES', '')));
        }

        return trim((string) env('TRUSTED_PROXIES', ''));
    }

    /**
     * @return list<string>
     */
    private static function cloudflareCidrs(): array
    {
        if (function_exists('app') && app()->bound('config')) {
            $cidrs = config('welcome_bonus.cloudflare_cidrs', []);

            return is_array($cidrs) ? array_values(array_filter($cidrs, 'is_string')) : [];
        }

        $file = dirname(__DIR__, 2).'/config/welcome_bonus.php';
        if (! is_file($file)) {
            return [];
        }

        $config = require $file;
        $cidrs = is_array($config['cloudflare_cidrs'] ?? null) ? $config['cloudflare_cidrs'] : [];

        return array_values(array_filter($cidrs, 'is_string'));
    }
}
