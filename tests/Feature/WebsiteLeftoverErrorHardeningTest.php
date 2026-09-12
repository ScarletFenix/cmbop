<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\SiteFileVerificationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebsiteLeftoverErrorHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Leftover News Daily',
            'site_url' => 'https://leftover-news.example',
            'domain' => 'leftover-news.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => '3',
            'description' => 'A publisher site for leftover error tests',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    /**
     * @param  TestResponse  $response
     */
    private function assertSafeJsonFailure($response): void
    {
        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('<html', false);

        $message = (string) $response->json('message');
        $this->assertNotSame('', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('Unknown column', $message);
    }

    public function test_claim_submit_returns_json_when_claims_table_is_gone(): void
    {
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $this->siteFor($owner);

        Schema::dropIfExists('site_claims');

        $this->assertSafeJsonFailure(
            $this->actingAs($claimer)->postJson(route('publisher.sites.claim'), [
                'website_url' => 'https://www.leftover-news.example',
                'website_name' => 'Leftover News Daily',
                'proof_message' => 'I own this domain via registrar account and CMS admin access.',
                'contact_email' => $claimer->email,
            ])
        );
    }

    public function test_admin_records_partial_returns_json_when_sites_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($admin)->getJson(route('admin.sites.records', ['partial' => 1]))
        );
    }

    public function test_marketing_queue_counts_return_json_when_sites_table_is_gone(): void
    {
        $marketer = $this->userWithRole('marketing');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($marketer)->getJson(route('marketing.dashboard.queue-counts'));

        $this->assertSafeJsonFailure($response);
        $response->assertJsonPath('ready_sites', 0)
            ->assertJsonPath('bulk_waiting', 0);
    }

    public function test_order_timeline_returns_json_when_activities_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-LEFT-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 80,
            'content_link' => 'https://example.com/draft-article',
            'anchor_text' => 'best seo tools',
            'target_url' => 'https://advertiser.example',
            'publisher_status' => 'pending',
        ]);

        Schema::dropIfExists('order_activities');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->getJson(route('notifications.order-timeline', $order->id))
        );
    }

    public function test_content_moderation_scan_returns_json_when_scanner_throws(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $this->mock(ContentModerationService::class, function ($mock) {
            $mock->shouldReceive('scan')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: scan boom'));
        });

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.content-moderation.scan'), [
                'url' => 'https://docs.google.com/document/d/leftover-scan/edit',
            ])
        );
    }

    public function test_publisher_archive_returns_json_when_save_throws(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $this->failNextSiteUpdate();

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->postJson(route('publisher.sites.archive', $site->id))
        );
    }

    public function test_publisher_unarchive_returns_json_when_save_throws(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, ['archived_at' => now()]);
        $this->failNextSiteUpdate();

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->postJson(route('publisher.sites.unarchive', $site->id))
        );
    }

    private function failNextSiteUpdate(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite trigger used to force a website save failure');
        }

        DB::unprepared(
            'CREATE TRIGGER leftover_fail_site_update BEFORE UPDATE ON sites
             BEGIN SELECT RAISE(ABORT, \'SQLSTATE[HY000]: General error: disk full\'); END'
        );
    }

    public function test_verification_start_returns_json_when_service_throws(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, [
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);

        $this->mock(SiteFileVerificationService::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: verify boom'));
        });

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->postJson(route('publisher.sites.verification.start', $site->id))
        );
    }
}
