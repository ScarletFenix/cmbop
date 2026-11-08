<?php

namespace App\Services\WeeklyNewSites;

use App\Models\Category;
use App\Models\Site;
use App\Models\User;
use App\Models\WeeklyNewSitesRecipient;
use App\Models\WeeklyNewSitesToken;
use App\Support\WeeklyNewSitesLink;
use Illuminate\Support\Collection;

class CardPresenter
{
    /**
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return Collection<int, array<string, mixed>>
     */
    public function decorate(User $user, Collection $cards, ?WeeklyNewSitesRecipient $recipient = null): Collection
    {
        $origin = rtrim(function_exists('app_public_url') ? app_public_url() : '', '/');

        return $cards->map(function (array $card) use ($user, $recipient, $origin) {
            $site = $card['site'] ?? null;
            $screenshot = $site instanceof Site ? $this->safeScreenshotUrl($site, $origin) : null;
            $card['screenshot_url'] = $screenshot;
            $card['has_screenshot'] = $screenshot !== null;
            $card['details_url'] = $this->siteClickUrl($user, $card, $recipient);
            unset($card['site']);

            return $card;
        })->values();
    }

    /**
     * @param  list<array{niche: string, country_code: string, country_label?: string}>  $pairs
     * @return list<array{niche: string, country_code: string, country_label: string, url: string}>
     */
    public function browseLinks(User $user, array $pairs, ?WeeklyNewSitesRecipient $recipient = null): array
    {
        $links = [];
        foreach ($pairs as $pair) {
            $niche = trim((string) ($pair['niche'] ?? ''));
            $country = strtolower(trim((string) ($pair['country_code'] ?? '')));
            if ($niche === '' || $country === '') {
                continue;
            }
            $path = WeeklyNewSitesLink::catalogPath([
                'category' => Category::encodeCatalogCategoryParam([$niche]),
                'country' => $country,
            ]);
            $links[] = [
                'niche' => $niche,
                'country_code' => $country,
                'country_label' => (string) ($pair['country_label'] ?? strtoupper($country)),
                'url' => WeeklyNewSitesLink::mint($user, WeeklyNewSitesToken::PURPOSE_CLICK, [
                    'kind' => 'catalog',
                    'path' => $path,
                    'recipient_id' => $recipient?->id,
                    'site_id' => null,
                ]),
            ];
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function siteClickUrl(User $user, array $card, ?WeeklyNewSitesRecipient $recipient): string
    {
        $path = WeeklyNewSitesLink::catalogPath([
            'site' => (int) ($card['site_id'] ?? 0),
        ]);

        return WeeklyNewSitesLink::mint($user, WeeklyNewSitesToken::PURPOSE_CLICK, [
            'kind' => 'site',
            'path' => $path,
            'recipient_id' => $recipient?->id,
            'site_id' => (int) ($card['site_id'] ?? 0),
        ]);
    }

    public function safeScreenshotUrl(Site $site, string $origin): ?string
    {
        $chain = $site->homepagePreviewUrlChain();
        foreach ($chain as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $url = trim($url);
            if (Site::isPlaceholderPreviewPath($url)) {
                continue;
            }
            if (preg_match('#^https?://#i', $url)) {
                if ($origin !== '' && str_starts_with($url, $origin.'/')) {
                    return $url;
                }

                continue;
            }
            if (str_starts_with($url, '/media/') || str_starts_with($url, '/storage/')) {
                return $origin !== '' ? $origin.$url : $url;
            }
        }

        return null;
    }
}
