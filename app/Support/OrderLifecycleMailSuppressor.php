<?php

namespace App\Support;

/**
 * Suppress generic OrderStatusChanged mail for audiences that already receive a
 * dedicated mailable for the same transition (accept / reject / live URL).
 *
 * Controllers call suppress() before the order update that would fan out the
 * lifecycle listener. Admin/support paths that skip the dedicated mail leave
 * the suppressor alone so the generic still fires.
 */
class OrderLifecycleMailSuppressor
{
    /** @var array<int, list<string>> */
    private array $byOrder = [];

    /**
     * @param  list<string>  $audiences  e.g. ['advertiser']
     */
    public function suppress(int $orderId, array $audiences): void
    {
        if ($orderId <= 0 || $audiences === []) {
            return;
        }

        $existing = $this->byOrder[$orderId] ?? [];
        $merged = array_values(array_unique(array_merge($existing, array_map('strval', $audiences))));
        $this->byOrder[$orderId] = $merged;
    }

    /**
     * @return list<string>
     */
    public function audiencesFor(int $orderId): array
    {
        return $this->byOrder[$orderId] ?? [];
    }

    /**
     * Read and clear suppressions for an order (used when scheduling afterCommit work).
     *
     * @return list<string>
     */
    public function pull(int $orderId): array
    {
        $audiences = $this->audiencesFor($orderId);
        $this->forget($orderId);

        return $audiences;
    }

    public function shouldSkip(int $orderId, string $audience): bool
    {
        return in_array($audience, $this->audiencesFor($orderId), true);
    }

    public function forget(int $orderId): void
    {
        unset($this->byOrder[$orderId]);
    }

    public function flush(): void
    {
        $this->byOrder = [];
    }
}
