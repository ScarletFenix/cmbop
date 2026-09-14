<?php

namespace App\Support;

use App\Services\Wallet\WelcomeBonusService;

class WelcomeBonusCopy
{
    /**
     * Live public offer. Leftover or a throw is not a grant.
     *
     * @return array{can_grant: bool, amount: float, euro: string}
     */
    public static function offer(): array
    {
        try {
            $bonus = app(WelcomeBonusService::class);
            if (! $bonus->canGrant()) {
                return ['can_grant' => false, 'amount' => 0.0, 'euro' => ''];
            }

            $amount = $bonus->amount();

            return [
                'can_grant' => true,
                'amount' => $amount,
                'euro' => $bonus->formatEuro($amount),
            ];
        } catch (\Throwable) {
            return ['can_grant' => false, 'amount' => 0.0, 'euro' => ''];
        }
    }

    public static function canGrant(): bool
    {
        return self::offer()['can_grant'];
    }

    public static function euro(): string
    {
        return self::offer()['euro'];
    }

    public static function mentionsOfferAmount(string $text): bool
    {
        return str_contains($text, '€20') || str_contains($text, '20 €');
    }

    public static function replaceAmount(string $text, string $euro): string
    {
        return str_replace(['€20', '20 €'], $euro, $text);
    }

    /**
     * When grants are live, swap the hardcoded €20 / 20 € token for the admin amount.
     * When they are not, use the _off key if the on-copy still advertises a grant.
     */
    public static function message(string $key, ?string $offKey = null): string
    {
        $on = (string) __('messages.'.$key);
        $offer = self::offer();

        if ($offer['can_grant'] && $offer['euro'] !== '') {
            return self::replaceAmount($on, $offer['euro']);
        }

        if ($offKey && self::mentionsOfferAmount($on) && trans()->has('messages.'.$offKey)) {
            return (string) __('messages.'.$offKey);
        }

        return $on;
    }

    /**
     * Rewrite the grant line in the llms.txt template so crawlers
     * do not see €20 after Disable or an amount change.
     */
    public static function applyToLlmsTxt(string $body): string
    {
        $grantLine = '- New advertisers: €20 welcome credit for first orders (spend-only, not withdrawable).';
        $offLine = '- New advertisers: promotional welcome credit is spend-only when granted; it is not always offered.';
        $offer = self::offer();
        $line = ($offer['can_grant'] && $offer['euro'] !== '')
            ? '- New advertisers: '.$offer['euro'].' welcome credit for first orders (spend-only, not withdrawable).'
            : $offLine;

        if (str_contains($body, $grantLine) || str_contains($body, $offLine)) {
            return str_replace([$grantLine, $offLine], $line, $body);
        }

        return $body;
    }
}
