<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\WelcomeBonusClaim;
use App\Models\WelcomeBonusSetting;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Schema;

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
        if ($ip !== null && $this->ipAlreadyClaimed($ip)) {
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

        if (! Schema::hasTable((new WelcomeBonusClaim)->getTable())) {
            return true;
        }

        $ip = $this->normalizedIp($request);

        if ($ip !== null && $this->ipAlreadyClaimed($ip)) {
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

    public function normalizedIp(Request $request): ?string
    {
        $ip = trim((string) $request->ip());
        if ($ip === '' || strlen($ip) > 45) {
            return null;
        }

        return $ip;
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
