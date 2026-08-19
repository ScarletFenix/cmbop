<?php

namespace Tests\Unit;

use App\Models\SiteAnnouncement;
use App\Support\PromotionCampaignHandoff;
use Tests\TestCase;

class PromotionCampaignHandoffTest extends TestCase
{
    public function test_everyone_notice_emails_both_roles_with_the_message(): void
    {
        $query = PromotionCampaignHandoff::query($this->announcement([
            'audience' => 'all',
            'title' => 'Site-wide offer',
            'message' => 'Save 20% this week.',
            'cta_label' => 'Shop',
            'cta_url' => '/advertiser/catalog',
        ]));

        $this->assertSame('both', $query['audience']);
        $this->assertSame('Site-wide offer', $query['subject']);
        $this->assertSame('<p>Save 20% this week.</p>', $query['body_html']);
        $this->assertSame('Shop', $query['cta_label']);
        $this->assertSame('/advertiser/catalog', $query['cta_url']);
    }

    public function test_role_notices_map_to_the_matching_campaign_audience(): void
    {
        $this->assertSame(
            'advertisers',
            PromotionCampaignHandoff::campaignAudience('advertiser')
        );
        $this->assertSame(
            'publishers',
            PromotionCampaignHandoff::campaignAudience('publisher')
        );
    }

    public function test_public_notices_cannot_be_emailed(): void
    {
        $this->assertNull(PromotionCampaignHandoff::campaignAudience('public'));
        $this->assertSame([], PromotionCampaignHandoff::query($this->announcement([
            'audience' => 'public',
        ])));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function announcement(array $overrides): SiteAnnouncement
    {
        $item = new SiteAnnouncement;
        $item->forceFill(array_merge([
            'title' => 'Notice',
            'message' => 'Hello',
            'type' => 'general',
            'style' => 'info',
            'audience' => 'all',
        ], $overrides));

        return $item;
    }
}
