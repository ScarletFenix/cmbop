<?php

namespace App\Services;

use App\Models\CheckoutIntent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Durable Stripe-first checkout packages and leftover-bonus holds.
 * Cache alone expires before Stripe Checkout (6–12h vs ~24h) and dies on flush.
 */
class CheckoutIntentService
{
    public static function pendingCheckoutCacheKey(string $referenceCode): string
    {
        return 'pending_card_checkout:'.$referenceCode;
    }

    public static function bonusCacheKey(int $userId, string $referenceCode): string
    {
        return 'checkout_bonus:'.$userId.':'.$referenceCode;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    public function storePackage(string $referenceCode, array $package, int $hours = 48): void
    {
        Cache::put(self::pendingCheckoutCacheKey($referenceCode), $package, now()->addHours($hours));

        $packageBonus = round((float) ($package['bonus_applied'] ?? 0), 2);
        $userId = isset($package['user_id']) ? (int) $package['user_id'] : null;
        $existing = $this->findIntent($referenceCode);
        $liveHold = $userId
            ? $this->heldBonus($userId, $referenceCode)
            : ($existing ? round((float) $existing->bonus_applied, 2) : 0.0);

        $attributes = [
            'user_id' => $userId,
            'package' => $package,
            'expires_at' => now()->addHours($hours),
        ];

        // takeBonus zeros the live hold but leaves package.bonus_applied for
        // late pay. Copying that snapshot back onto the row/cache made
        // heldBonus() look reserved again, so a later cancel/expiry/late-pay
        // could spend another checkout's wallet reserve.
        if ($existing && $liveHold <= 0.009) {
            $attributes['bonus_applied'] = 0;
        } else {
            $attributes['bonus_applied'] = $packageBonus;
            if ($userId && $packageBonus > 0) {
                Cache::put(self::bonusCacheKey($userId, $referenceCode), $packageBonus, now()->addHours($hours));
            }
        }

        $this->upsertIntent($referenceCode, $attributes);
    }

    public function rememberBonus(int $userId, string $referenceCode, float $amount, int $hours = 720): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }

        Cache::put(self::bonusCacheKey($userId, $referenceCode), $amount, now()->addHours($hours));

        $existing = $this->findIntent($referenceCode);
        $expiresAt = now()->addHours($hours);
        if ($existing?->expires_at && $existing->expires_at->greaterThan($expiresAt)) {
            $expiresAt = $existing->expires_at;
        }

        $this->upsertIntent($referenceCode, [
            'user_id' => $userId,
            'bonus_applied' => $amount,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPackage(string $referenceCode): ?array
    {
        $cached = Cache::get(self::pendingCheckoutCacheKey($referenceCode));
        if (is_array($cached)) {
            return $cached;
        }

        $intent = $this->findIntent($referenceCode);
        $package = is_array($intent?->package) ? $intent->package : null;
        if ($package) {
            $ttl = $intent->expires_at ? now()->diffInSeconds($intent->expires_at, false) : 3600;
            if ($ttl > 0) {
                Cache::put(self::pendingCheckoutCacheKey($referenceCode), $package, now()->addSeconds($ttl));
            }
        }

        return $package;
    }

    /**
     * Bonus still held for this reference (cache / intent row, not the package snapshot).
     */
    public function heldBonus(int $userId, string $referenceCode): float
    {
        if ($userId <= 0 || $referenceCode === '') {
            return 0.0;
        }

        $fromCache = round((float) Cache::get(self::bonusCacheKey($userId, $referenceCode), 0), 2);
        $intent = $this->findIntent($referenceCode);
        $fromRow = $intent ? round((float) $intent->bonus_applied, 2) : 0.0;

        return max($fromCache, $fromRow);
    }

    /**
     * Live leftover checkout bonus for this reference, plus an explicit fallback.
     * Package JSON is a snapshot, not a hold — after takeBonus it must not
     * cap or release another checkout's reserved promo.
     */
    public function peekBonus(int $userId, string $referenceCode, ?float $fallback = null): float
    {
        return max(
            $this->heldBonus($userId, $referenceCode),
            round((float) ($fallback ?? 0), 2)
        );
    }

    /**
     * Pull the reserved bonus for this reference (cache, durable row, then fallback).
     * Leaves package.bonus_applied intact so a late paid webhook can re-reserve.
     */
    public function takeBonus(int $userId, string $referenceCode, ?float $fallback = null): float
    {
        $bonus = $this->peekBonus($userId, $referenceCode, $fallback);
        Cache::forget(self::bonusCacheKey($userId, $referenceCode));
        $intent = $this->findIntent($referenceCode);
        if ($intent && (float) $intent->bonus_applied > 0) {
            $intent->update(['bonus_applied' => 0]);
        }

        return $bonus;
    }

    /**
     * Reduce leftover checkout bonus without clearing the rest of the reference.
     */
    public function decrementBonus(int $userId, string $referenceCode, float $amount): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || $userId <= 0 || $referenceCode === '') {
            return;
        }

        $left = max(0, round($this->heldBonus($userId, $referenceCode) - $amount, 2));
        $intent = $this->findIntent($referenceCode);
        if ($intent) {
            $intent->update(['bonus_applied' => $left]);
        }

        $key = self::bonusCacheKey($userId, $referenceCode);
        if ($left > 0) {
            Cache::put($key, $left, now()->addHours(720));
        } else {
            Cache::forget($key);
        }
    }

    public function forgetBonus(int $userId, string $referenceCode): void
    {
        Cache::forget(self::bonusCacheKey($userId, $referenceCode));
        $intent = $this->findIntent($referenceCode);
        if ($intent && (float) $intent->bonus_applied > 0) {
            $intent->update(['bonus_applied' => 0]);
        }
    }

    public function forget(string $referenceCode, ?int $userId = null): void
    {
        Cache::forget(self::pendingCheckoutCacheKey($referenceCode));
        if ($userId) {
            Cache::forget(self::bonusCacheKey($userId, $referenceCode));
        }

        $intent = $this->findIntent($referenceCode);
        if ($intent) {
            if (! $userId && $intent->user_id) {
                Cache::forget(self::bonusCacheKey((int) $intent->user_id, $referenceCode));
            }
            $intent->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertIntent(string $referenceCode, array $attributes): void
    {
        if (! $this->tableReady()) {
            return;
        }

        try {
            $existing = CheckoutIntent::query()->where('reference_code', $referenceCode)->first();
            if ($existing) {
                if (array_key_exists('package', $attributes) && $attributes['package'] === null) {
                    unset($attributes['package']);
                }
                $existing->fill($attributes)->save();

                return;
            }

            CheckoutIntent::query()->create(array_merge(
                ['reference_code' => $referenceCode],
                $attributes
            ));
        } catch (\Throwable $e) {
            Log::warning('CheckoutIntent persist failed; cache-only fallback', [
                'reference_code' => $referenceCode,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function findIntent(string $referenceCode): ?CheckoutIntent
    {
        if (! $this->tableReady()) {
            return null;
        }

        try {
            return CheckoutIntent::query()->where('reference_code', $referenceCode)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('checkout_intents');
        } catch (\Throwable) {
            return false;
        }
    }
}
