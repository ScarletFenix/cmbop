<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\MarketingOpsQueues;
use Database\Seeders\RolesTableSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingDashboardQueuesTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
            'name' => 'Queue Marketer',
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
            'name' => 'Queue Publisher',
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    public function test_dashboard_splits_ready_sites_from_publisher_owned_work(): void
    {
        $ready = $this->makeSite([
            'site_name' => 'Ready Activate Target',
            'site_url' => 'https://ready-activate.example',
            'domain' => 'ready-activate.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);
        $awaiting = $this->makeSite([
            'site_name' => 'Awaiting Details Draft',
            'site_url' => 'https://awaiting-details.example',
            'domain' => 'awaiting-details.example',
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);
        $invite = $this->makeSite([
            'site_name' => 'Unaccepted Invite Site',
            'site_url' => 'https://unaccepted-invite.example',
            'domain' => 'unaccepted-invite.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'assigned_by_user_id' => $this->marketer->id,
            'publisher_accepted_at' => null,
        ]);

        $this->assertTrue($ready->needsAdminReview());
        $this->assertFalse($awaiting->needsAdminReview());
        $this->assertFalse($invite->needsAdminReview());
        $this->assertSame(1, MarketingOpsQueues::sitesReadyForStaff()->count());
        $this->assertSame(2, MarketingOpsQueues::sitesWaitingOnPublisher()->count());

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee('Ready to activate', false)
            ->assertSee('Waiting on you (bulk)', false)
            ->assertSee('Waiting on publisher', false)
            ->assertSee('You can add and edit listings', false)
            ->assertDontSee('Admin handles verify, activate, enrichment', false)
            ->getContent();

        $this->assertSame('1', $this->attrValue($html, 'data-stat', 'ready-to-activate', 'data-stat-value'));
        $this->assertSame('2', $this->attrValue($html, 'data-stat', 'waiting-on-publisher', 'data-stat-sites'));
        $this->assertSame('0', $this->attrValue($html, 'data-stat', 'waiting-on-publisher', 'data-stat-bulk'));

        $readyTable = $this->nodeText($html, 'data-queue', 'ready-sites');
        $waitingTable = $this->nodeText($html, 'data-queue', 'waiting-sites');

        $this->assertStringContainsString('Ready Activate Target', $readyTable);
        $this->assertStringContainsString('Ready for review', $readyTable);
        $this->assertStringNotContainsString('Awaiting Details Draft', $readyTable);
        $this->assertStringNotContainsString('Unaccepted Invite Site', $readyTable);

        $this->assertStringContainsString('Awaiting Details Draft', $waitingTable);
        $this->assertStringContainsString('Filling details', $waitingTable);
        $this->assertStringContainsString('Unaccepted Invite Site', $waitingTable);
        $this->assertStringContainsString('Waiting on accept', $waitingTable);
        $this->assertStringNotContainsString('Ready Activate Target', $waitingTable);

        $this->assertStringContainsString(route('marketing.sites.index', ['needs_review' => 1], false), $html);
    }

    public function test_dashboard_open_bulk_includes_completed_rows_still_needing_done(): void
    {
        $requested = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 3,
            'handled_by' => $this->marketer->id,
        ]);
        $awaitingPublisher = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 2,
        ]);
        $leftover = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_COMPLETED,
            'estimated_count' => 1,
            'completed_at' => now(),
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $leftover->id,
            'site_url' => 'https://leftover-done.example',
            'domain' => 'leftover-done.example',
            'price' => 40,
            'site_id' => null,
        ]);
        BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 4,
        ]);
        $seededSite = $this->makeSite([
            'site_name' => 'Already Seeded Listing',
            'site_url' => 'https://already-seeded.example',
            'domain' => 'already-seeded.example',
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);
        $trulyDone = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_COMPLETED,
            'estimated_count' => 1,
            'completed_at' => now(),
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $trulyDone->id,
            'site_url' => 'https://already-seeded.example',
            'domain' => 'already-seeded.example',
            'price' => 25,
            'site_id' => $seededSite->id,
        ]);

        $this->assertSame(2, MarketingOpsQueues::bulkWaitingOnMarketer()->count());
        $this->assertSame(1, MarketingOpsQueues::bulkWaitingOnPublisher()->count());
        $this->assertSame(3, MarketingOpsQueues::openBulkForMarketer()->count());

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee('Waiting on marketer', false)
            ->assertSee('Waiting on publisher', false)
            ->assertSee('Completed — ready to verify', false)
            ->assertDontSee('awaiting publisher', false)
            ->getContent();

        $this->assertSame('2', $this->attrValue($html, 'data-stat', 'bulk-waiting-on-you', 'data-stat-value'));
        $this->assertSame('1', $this->attrValue($html, 'data-stat', 'waiting-on-publisher', 'data-stat-bulk'));

        $bulkTable = $this->nodeText($html, 'data-queue', 'open-bulk');
        $this->assertStringContainsString('#'.$requested->id, $bulkTable);
        $this->assertStringContainsString('#'.$awaitingPublisher->id, $bulkTable);
        $this->assertStringContainsString('#'.$leftover->id, $bulkTable);
        $this->assertStringContainsString('Queue Marketer', $bulkTable);
        $this->assertStringNotContainsString('#'.$trulyDone->id, $bulkTable);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Queue Site',
            'site_url' => 'https://queue-site.example',
            'domain' => 'queue-site.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'Queue dashboard site',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    private function attrValue(string $html, string $parentAttr, string $parentValue, string $childAttr): string
    {
        $xpath = $this->xpath($html);
        $nodes = $xpath->query(sprintf('//*[@%s="%s"]//*[@%s]', $parentAttr, $parentValue, $childAttr));
        $this->assertGreaterThan(0, $nodes->length, "Missing {$childAttr} inside {$parentAttr}={$parentValue}");

        return (string) $nodes->item(0)->attributes->getNamedItem($childAttr)?->nodeValue;
    }

    private function nodeText(string $html, string $attr, string $value): string
    {
        $xpath = $this->xpath($html);
        $nodes = $xpath->query(sprintf('//*[@%s="%s"]', $attr, $value));
        $this->assertGreaterThan(0, $nodes->length, "Missing {$attr}={$value}");

        return (string) $nodes->item(0)->textContent;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
