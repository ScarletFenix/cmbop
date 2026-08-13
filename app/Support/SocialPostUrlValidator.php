<?php

namespace App\Support;

/**
 * Normalize and soft-validate optional social post URLs on live-URL delivery.
 *
 * Channels come from the order-item snapshot (never from the live site offer).
 * Empty values are ignored; present values must be URLs. Host mismatch is a
 * soft warning only — proof v1 still only requires the article live URL.
 */
class SocialPostUrlValidator
{
    /**
     * @param  list<string>|null  $enabledChannels  Snapshotted channels on the order item
     * @param  array<string, mixed>|null  $submitted  Request map channel => url
     * @return array{
     *   urls: array<string, string>,
     *   warnings: list<string>
     * }
     */
    public function normalize(?array $enabledChannels, ?array $submitted): array
    {
        $allowed = array_values(array_filter(
            array_map(
                static fn ($c) => strtolower(trim((string) $c)),
                is_array($enabledChannels) ? $enabledChannels : []
            ),
            static fn (string $c) => $c !== ''
                && in_array($c, config('site_placement.social_channels', ['facebook', 'instagram', 'x']), true)
        ));

        $urls = [];
        $warnings = [];

        if ($allowed === [] || ! is_array($submitted) || $submitted === []) {
            return ['urls' => [], 'warnings' => []];
        }

        $hostsByChannel = config('site_placement.social_hosts', []);

        foreach ($allowed as $channel) {
            $raw = $submitted[$channel] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            if (! is_string($raw) && ! is_numeric($raw)) {
                continue;
            }

            $url = trim((string) $raw);
            if ($url === '') {
                continue;
            }

            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                $warnings[] = $this->channelLabel($channel).' post URL does not look like a valid link.';

                continue;
            }

            $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));
            if (! in_array($scheme, ['http', 'https'], true)) {
                $warnings[] = $this->channelLabel($channel).' post URL must start with http:// or https://.';

                continue;
            }

            $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
            $expected = is_array($hostsByChannel[$channel] ?? null)
                ? array_map('strtolower', $hostsByChannel[$channel])
                : [];

            if ($expected !== [] && $host !== '' && ! $this->hostMatches($host, $expected)) {
                $warnings[] = $this->channelLabel($channel)
                    .' post URL does not look like a '.$this->channelLabel($channel)
                    .' link (saved anyway).';
            }

            $urls[$channel] = $url;
        }

        return ['urls' => $urls, 'warnings' => $warnings];
    }

    /**
     * @param  list<string>  $allowedHosts
     */
    private function hostMatches(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($allowed === '') {
                continue;
            }
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    public function channelLabel(string $channel): string
    {
        return match (strtolower($channel)) {
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'x' => 'X',
            default => ucfirst($channel),
        };
    }
}
