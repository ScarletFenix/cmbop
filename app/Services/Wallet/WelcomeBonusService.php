<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\WelcomeBonusClaim;
use App\Models\WelcomeBonusSetting;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\IpUtils;

class WelcomeBonusService
{
    public function isEnabled(): bool
    {
        return WelcomeBonusSetting::isEnabled();
    }

    public function setEnabled(bool $enabled, ?int $updatedBy = null): void
    {
        WelcomeBonusSetting::setEnabled($enabled, $updatedBy);
    }

    public function amount(): float
    {
        return round(max(0, (float) config('welcome_bonus.amount', 20)), 2);
    }

    /**
     * Preview the grant for this request. Does not write a claim.
     * Call recordClaim() inside the signup transaction before crediting the wallet.
     */
    public function amountFor(Request $request, string $role): float
    {
        if (! $this->isEnabled()) {
            return 0.0;
        }

        if ($role !== 'advertiser') {
            return 0.0;
        }

        $amount = $this->amount();
        if ($amount <= 0) {
            return 0.0;
        }

        $cookieName = (string) config('welcome_bonus.cookie_name', 'slb_welcome_claimed');
        $cookie = trim((string) $request->cookie($cookieName, ''));
        if ($cookie !== '') {
            return 0.0;
        }

        $ip = $this->normalizedIp($request);
        if ($ip === null || $this->ipAlreadyClaimed($ip)) {
            return 0.0;
        }

        return $amount;
    }

    /**
     * Persist the claim. Must run inside the same DB transaction as the wallet credit.
     * Returns false when this IP (or user) already claimed — caller must grant 0.
     * Missing table: fail open (return true) so Hostinger drift cannot block signup.
     */
    public function recordClaim(User $user, Request $request, float $amount, string $source): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $write = function () use ($user, $request, $amount, $source): bool {
            if (! WelcomeBonusSetting::isEnabledForGrant()) {
                return false;
            }

            if (! Schema::hasTable((new WelcomeBonusClaim)->getTable())) {
                return true;
            }

            $ip = $this->normalizedIp($request);
            if ($ip === null || $this->ipAlreadyClaimed($ip)) {
                return false;
            }

            try {
                WelcomeBonusClaim::query()->create([
                    'user_id' => $user->id,
                    'ip_address' => $ip,
                    'user_agent' => $request->userAgent(),
                    'source' => $source,
                    'amount' => $amount,
                ]);

                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    return false;
                }

                throw $e;
            }
        };

        // The settings-row lock only holds until this transaction ends.
        if (DB::transactionLevel() > 0) {
            return $write();
        }

        return DB::transaction($write);
    }

    public function queueClaimCookie(): void
    {
        $name = (string) config('welcome_bonus.cookie_name', 'slb_welcome_claimed');

        Cookie::queue(cookie(
            $name,
            '1',
            (int) config('welcome_bonus.cookie_minutes', 525600),
            '/',
            null,
            (bool) config('session.secure', false),
            true,
            false,
            'lax'
        ));
    }

    /**
     * Place key for the once-per-IP lock.
     *
     * Do not use Request::ip() while the app trusts all proxies — that reads
     * client-controlled X-Forwarded-For and lets anyone collect €20 per spoof.
     * CF-Connecting-IP is trusted only when REMOTE_ADDR is a Cloudflare edge.
     */
    public function normalizedIp(Request $request): ?string
    {
        $remote = $this->sanitizeIp($request->server->get('REMOTE_ADDR'));
        $cfConnecting = $this->sanitizeIp($request->headers->get('CF-Connecting-IP'));
        if ($remote !== null && $cfConnecting !== null && $this->isCloudflarePeer($remote)) {
            return $cfConnecting;
        }

        return $remote;
    }

    private function sanitizeIp(mixed $raw): ?string
    {
        $ip = trim((string) $raw);
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }
        if ($ip === '' || strlen($ip) > 45) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        // IPv4-mapped IPv6 (::ffff:1.2.3.4) must match the IPv4 claim key.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\x00", 10)."\xff\xff") {
            $packed = substr($packed, 12);
        }

        $normalized = inet_ntop($packed);

        return $normalized !== false ? $normalized : null;
    }

    private function isCloudflarePeer(string $ip): bool
    {
        $cidrs = config('welcome_bonus.cloudflare_cidrs', []);
        if (! is_array($cidrs) || $cidrs === []) {
            return false;
        }

        return IpUtils::checkIp($ip, $cidrs);
    }

    public function ipAlreadyClaimed(string $ip): bool
    {
        if (! Schema::hasTable((new WelcomeBonusClaim)->getTable())) {
            return false;
        }

        return WelcomeBonusClaim::query()->where('ip_address', $ip)->exists();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $message = $e->getMessage();

        return $sqlState === '23000'
            || str_contains($message, 'UNIQUE')
            || str_contains($message, 'unique');
    }
}
