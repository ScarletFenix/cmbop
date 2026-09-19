<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class ViewerCountry
{
    public function code(?Request $request = null): ?string
    {
        $request ??= request();
        if (! $request instanceof Request) {
            return null;
        }

        if ($this->allowsLocalOverride()) {
            $forced = strtoupper(trim((string) config('fx.fake_country', '')));
            if ($forced === 'UK') {
                $forced = 'GB';
            }
            if ($forced !== '' && preg_match('/^[A-Z]{2}$/', $forced) === 1) {
                return $forced;
            }

            $header = $this->headerCountry($request);
            if ($header !== null) {
                return $header;
            }
        }

        $peer = $this->sanitizeIp($request->server->get('REMOTE_ADDR'));
        if ($peer === null || ! $this->isCloudflarePeer($peer)) {
            return null;
        }

        return $this->headerCountry($request);
    }

    public function displayCurrency(?Request $request = null): string
    {
        if ($this->allowsLocalOverride()) {
            $forced = $this->normalizeForcedCurrency((string) config('fx.force_display', ''));
            if ($forced !== null) {
                return $forced;
            }
        }

        $map = config('fx.country_currency', []);
        $country = $this->code($request);
        if ($country === null || ! is_array($map)) {
            return 'EUR';
        }

        $currency = strtoupper((string) ($map[$country] ?? ''));

        return $currency !== '' ? $currency : 'EUR';
    }

    public function isUs(?Request $request = null): bool
    {
        return $this->displayCurrency($request) === 'USD';
    }

    private function normalizeForcedCurrency(string $raw): ?string
    {
        $value = strtolower(trim($raw));

        return match ($value) {
            'usd', 'dollar', 'dollars', 'cad', 'aud' => 'USD',
            'gbp', 'pound', 'pounds' => 'GBP',
            'eur', 'euro', 'euros' => 'EUR',
            default => null,
        };
    }

    private function allowsLocalOverride(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    private function headerCountry(Request $request): ?string
    {
        $raw = strtoupper(trim((string) $request->headers->get('CF-IPCountry', '')));
        if ($raw === 'UK') {
            $raw = 'GB';
        }
        if (preg_match('/^[A-Z]{2}$/', $raw) !== 1 || in_array($raw, ['XX', 'T1'], true)) {
            return null;
        }

        return $raw;
    }

    private function sanitizeIp(mixed $ip): ?string
    {
        $ip = is_string($ip) ? trim($ip) : '';
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $ip;
    }

    private function isCloudflarePeer(string $ip): bool
    {
        $cidrs = config('welcome_bonus.cloudflare_cidrs', []);
        if (! is_array($cidrs) || $cidrs === []) {
            return false;
        }

        try {
            return IpUtils::checkIp($ip, $cidrs);
        } catch (\Throwable) {
            return false;
        }
    }
}
