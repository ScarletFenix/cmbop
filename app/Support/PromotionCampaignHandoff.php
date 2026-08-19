<?php

namespace App\Support;

use App\Models\SiteAnnouncement;
use App\Services\AudienceInventoryService;

class PromotionCampaignHandoff
{
    public static function campaignAudience(?string $promotionAudience): ?string
    {
        return match (trim((string) $promotionAudience)) {
            'publisher' => AudienceInventoryService::AUDIENCE_PUBLISHERS,
            'advertiser' => AudienceInventoryService::AUDIENCE_ADVERTISERS,
            'all' => AudienceInventoryService::AUDIENCE_BOTH,
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function query(SiteAnnouncement $announcement): array
    {
        $audience = self::campaignAudience(scalar_text($announcement->audience));
        if ($audience === null) {
            return [];
        }

        $query = [
            'audience' => $audience,
            'subject' => scalar_text($announcement->title),
        ];

        $body = CampaignHtml::sanitize(scalar_text($announcement->message));
        if ($body !== '' && ! CampaignHtml::isBlank($body)) {
            $query['body_html'] = $body;
        }

        $ctaLabel = scalar_text($announcement->cta_label);
        if ($ctaLabel !== '') {
            $query['cta_label'] = $ctaLabel;
        }

        $ctaUrl = PromotionUrl::href($announcement->cta_url);
        if (is_string($ctaUrl) && $ctaUrl !== '') {
            $query['cta_url'] = $ctaUrl;
        }

        return $query;
    }
}
