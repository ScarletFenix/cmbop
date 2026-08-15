<?php

namespace Tests\Unit;

use App\Models\EmailCampaign;
use App\Services\AudienceInventoryService;
use Tests\TestCase;

class AudienceInventoryKeysTest extends TestCase
{
    public function test_tab_slugs_normalize_to_listable_keys(): void
    {
        $this->assertSame('advertisers', AudienceInventoryService::normalizeAudienceKey('advertisers'));
        $this->assertSame('publishers', AudienceInventoryService::normalizeAudienceKey('publishers'));
        $this->assertSame('both', AudienceInventoryService::normalizeAudienceKey('both'));
        $this->assertSame('advertisers_no_orders', AudienceInventoryService::normalizeAudienceKey('no_orders'));
        $this->assertSame('advertisers_no_paid_orders', AudienceInventoryService::normalizeAudienceKey('no_paid_orders'));
        $this->assertSame('publishers_no_sites', AudienceInventoryService::normalizeAudienceKey('no_sites'));
        $this->assertSame('advertisers_never_deposited', AudienceInventoryService::normalizeAudienceKey('never_deposited'));
        $this->assertSame('advertisers_paid_orders', AudienceInventoryService::normalizeAudienceKey('paid_orders'));
        $this->assertSame('advertisers_deposited_no_orders', AudienceInventoryService::normalizeAudienceKey('deposited_no_orders'));
        $this->assertSame('publishers_no_active_sites', AudienceInventoryService::normalizeAudienceKey('no_active_sites'));
    }

    public function test_never_checked_out_aliases_collapse_to_no_orders(): void
    {
        $this->assertSame(
            'advertisers_no_orders',
            AudienceInventoryService::normalizeAudienceKey('never_checked_out')
        );
        $this->assertSame(
            'advertisers_no_orders',
            AudienceInventoryService::normalizeAudienceKey('advertisers_never_checked_out')
        );
        $this->assertSame('no_orders', AudienceInventoryService::tabForAudienceKey('never_checked_out'));
        $this->assertSame('no_orders', AudienceInventoryService::tabForAudienceKey('advertisers_never_checked_out'));
    }

    public function test_unknown_key_falls_back_to_advertisers(): void
    {
        $this->assertSame('advertisers', AudienceInventoryService::normalizeAudienceKey('not-a-segment'));
        $this->assertSame('advertisers', AudienceInventoryService::normalizeAudienceKey(''));
        $this->assertSame('advertisers', AudienceInventoryService::tabForAudienceKey('not-a-segment'));
    }

    public function test_both_is_listable_and_selected_is_not(): void
    {
        $this->assertTrue(AudienceInventoryService::isListableKey('both'));
        $this->assertTrue(AudienceInventoryService::isListableKey('advertisers_no_orders'));
        $this->assertFalse(AudienceInventoryService::isListableKey('selected'));
    }

    public function test_singular_role_aliases(): void
    {
        $this->assertSame('advertisers', AudienceInventoryService::normalizeAudienceKey('advertiser'));
        $this->assertSame('publishers', AudienceInventoryService::normalizeAudienceKey('publisher'));
    }

    public function test_campaign_labels_stay_stable(): void
    {
        $this->assertSame('Advertisers (never checked out)', EmailCampaign::labelForAudience('advertisers_no_orders'));
        $this->assertSame('Advertisers (never checked out)', EmailCampaign::labelForAudience('advertisers_never_checked_out'));
        $this->assertSame('Advertisers (never deposited)', EmailCampaign::labelForAudience('advertisers_never_deposited'));
        $this->assertSame('Publishers (no sites)', EmailCampaign::labelForAudience('publishers_no_sites'));
        $this->assertSame('Advertisers + Publishers', EmailCampaign::labelForAudience('both'));
        $this->assertSame('Advertisers (paid orders)', EmailCampaign::labelForAudience('advertisers_paid_orders'));
        $this->assertSame('Advertisers (deposited, no orders)', EmailCampaign::labelForAudience('advertisers_deposited_no_orders'));
        $this->assertSame('Publishers (no active sites)', EmailCampaign::labelForAudience('publishers_no_active_sites'));
        $this->assertSame('Selected users', EmailCampaign::labelForAudience('selected'));
        $this->assertSame('Mystery', EmailCampaign::labelForAudience('mystery'));
    }

    public function test_export_labels_use_short_inventory_names(): void
    {
        $this->assertSame('Never checked out', AudienceInventoryService::exportLabel('no_orders'));
        $this->assertSame('Never deposited', AudienceInventoryService::exportLabel('advertisers_never_deposited'));
        $this->assertSame('Advertisers', AudienceInventoryService::exportLabel('advertisers'));
        $this->assertSame('Advertisers + Publishers', AudienceInventoryService::exportLabel('both'));
    }

    public function test_query_for_audience_key_accepts_inventory_tab_slugs(): void
    {
        $inventory = new AudienceInventoryService;

        $this->assertSame(
            $inventory->queryForAudienceKey('no_orders')->toSql(),
            $inventory->queryForAudienceKey('advertisers_no_orders')->toSql()
        );
        $this->assertSame(
            $inventory->queryForAudienceKey('no_active_sites')->toSql(),
            $inventory->queryForAudienceKey('publishers_no_active_sites')->toSql()
        );
    }
}
