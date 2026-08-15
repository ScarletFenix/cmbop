<?php

namespace Tests\Feature;

use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Models\ContentSubmission;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AdminContentLibraryTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Library Staff Site',
            'site_url' => 'https://library-staff.example',
            'domain' => 'library-staff.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function orderFor(User $advertiser, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-LIB-'.uniqid(),
            'reference_code' => 'REF-LIB-'.uniqid(),
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'unpaid',
            'status' => 'pending',
        ], $attrs));
    }

    private function attachToOrder(ContentSubmission $submission, Order $order, Site $site, array $itemAttrs = []): OrderItem
    {
        $item = OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 40,
            'content_link' => 'https://example.com/article.docx',
            'content_submission_id' => $submission->id,
        ], $itemAttrs));

        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        return $item;
    }

    private function claimByItemOnly(ContentSubmission $submission, Order $order, Site $site): OrderItem
    {
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 40,
            'content_link' => 'https://example.com/article.docx',
            'content_submission_id' => $submission->id,
        ]);

        $submission->update([
            'order_id' => null,
            'order_item_id' => null,
        ]);

        return $item;
    }

    public function test_unused_expired_approved_is_only_in_expired_chip(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $expired = $this->createApprovedSubmission($advertiser);
        $expired->update(['title' => 'Expired Unused Piece', 'expires_at' => now()->subDay()]);
        $fresh = $this->createApprovedSubmission($advertiser);
        $fresh->update(['title' => 'Fresh Approved Piece']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index'))
            ->assertOk()
            ->assertSee('Fresh Approved Piece')
            ->assertDontSee('Expired Unused Piece');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'available']))
            ->assertOk()
            ->assertSee('Fresh Approved Piece')
            ->assertDontSee('Expired Unused Piece');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'expired']))
            ->assertOk()
            ->assertSee('Expired Unused Piece')
            ->assertDontSee('Fresh Approved Piece');
    }

    public function test_expired_article_on_open_order_is_not_in_expired_chip(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Expired But Owned', 'expires_at' => now()->subDay()]);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($submission, $order, $site);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'expired']))
            ->assertOk()
            ->assertDontSee('Expired But Owned');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertSee('Expired But Owned')
            ->assertSee('In progress');

        $this->assertSame('in_progress', $submission->fresh()->load(['order', 'orderItems.order'])->libraryAvailability());
        $this->assertTrue($submission->fresh()->isReadyToFulfill((int) $order->id));
    }

    public function test_rejected_owned_article_is_needs_fix_not_in_progress(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Rejected Owned Piece']);
        $order = $this->orderFor($advertiser);
        $this->attachToOrder($submission, $order, $site);
        $submission->update(['moderation_status' => ContentSubmission::STATUS_REJECTED]);

        $this->assertSame('needs_fix', $submission->fresh()->load(['order', 'orderItems.order'])->libraryAvailability());
        $this->assertFalse(
            ContentSubmission::query()->whereKey($submission->id)->inProgressInLibrary()->exists()
        );

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertDontSee('Rejected Owned Piece');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'needs_fix']))
            ->assertOk()
            ->assertSee('Rejected Owned Piece');
    }

    public function test_expired_rejected_leftover_stays_in_needs_fix_not_expired(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Expired Rejected Leftover',
            'expires_at' => now()->subDay(),
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
        ]);
        $order = $this->orderFor($advertiser);
        $this->attachToOrder($submission, $order, $site);

        $fresh = $submission->fresh()->load(['order', 'orderItems.order']);
        $this->assertSame('needs_fix', $fresh->libraryAvailability());
        $this->assertTrue(
            ContentSubmission::query()->whereKey($submission->id)->needsLibraryFix()->exists()
        );

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'needs_fix']))
            ->assertOk()
            ->assertSee('Expired Rejected Leftover');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'expired']))
            ->assertOk()
            ->assertDontSee('Expired Rejected Leftover');
    }

    public function test_owned_leftover_missing_file_is_needs_fix_not_in_progress(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Purged Leftover File',
            'path' => '',
        ]);
        $order = $this->orderFor($advertiser);
        $this->attachToOrder($submission, $order, $site);

        $fresh = $submission->fresh()->load(['order', 'orderItems.order']);
        $this->assertSame('needs_fix', $fresh->libraryAvailability());
        $this->assertTrue(
            ContentSubmission::query()->whereKey($submission->id)->needsLibraryFix()->exists()
        );
        $this->assertFalse(
            ContentSubmission::query()->whereKey($submission->id)->inProgressInLibrary()->exists()
        );

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'needs_fix']))
            ->assertOk()
            ->assertSee('Purged Leftover File');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertDontSee('Purged Leftover File');
    }

    public function test_expired_item_only_leftover_is_needs_fix_not_expired(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Expired Item Leftover',
            'expires_at' => now()->subDay(),
        ]);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $this->claimByItemOnly($submission, $order, $site);

        $fresh = $submission->fresh()->load(['order', 'orderItems.order']);
        $this->assertNull($fresh->order_id);
        $this->assertTrue($fresh->isClaimedByAnotherOrder());
        $this->assertSame('needs_fix', $fresh->libraryAvailability());
        $this->assertTrue($fresh->isReadyToFulfill((int) $order->id));
        $this->assertTrue($fresh->isUsableAfterStaffApproval());
        $this->assertFalse(
            ContentSubmission::query()->whereKey($submission->id)->expiredUnused()->exists()
        );
        $this->assertTrue(
            ContentSubmission::query()->whereKey($submission->id)->needsLibraryFix()->exists()
        );

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'needs_fix']))
            ->assertOk()
            ->assertSee('Expired Item Leftover');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'expired']))
            ->assertOk()
            ->assertDontSee('Expired Item Leftover');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index'))
            ->assertOk()
            ->assertSee('Expired Item Leftover');
    }

    public function test_item_only_leftover_show_and_index_link_the_open_order(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Item Leftover Order Link']);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $this->claimByItemOnly($submission, $order, $site);

        $fresh = $submission->fresh()->load(['order', 'orderItems.order', 'orderItems.site']);
        $this->assertNull($fresh->order_id);
        $this->assertSame((int) $order->id, (int) $fresh->libraryOrder()?->id);

        $this->actingAs($admin)
            ->get(route('admin.content-library.show', $submission))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee(route('admin.orders.show', $order), false)
            ->assertSee($site->site_name);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'needs_fix']))
            ->assertOk()
            ->assertSee('Item Leftover Order Link')
            ->assertSee($order->order_number)
            ->assertSee(route('admin.orders.show', $order), false);
    }

    public function test_cancelled_owner_order_id_is_not_the_library_order_link(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Ghost Cancelled Owner']);
        $cancelled = $this->orderFor($advertiser, [
            'payment_status' => 'failed',
            'status' => 'cancelled',
        ]);
        $open = $this->orderFor($advertiser, [
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $submission->update(['order_id' => $cancelled->id, 'order_item_id' => null]);
        $this->claimByItemOnly($submission, $open, $site);
        $submission->update(['order_id' => $cancelled->id]);

        $fresh = $submission->fresh()->load(['order', 'orderItems.order']);
        $this->assertSame((int) $cancelled->id, (int) $fresh->order_id);
        $this->assertSame((int) $open->id, (int) $fresh->libraryOrder()?->id);

        $this->actingAs($admin)
            ->get(route('admin.content-library.show', $submission))
            ->assertOk()
            ->assertSee($open->order_number)
            ->assertSee(route('admin.orders.show', $open), false)
            ->assertDontSee($cancelled->order_number)
            ->assertDontSee(route('admin.orders.show', $cancelled), false);

        $unused = $this->createApprovedSubmission($advertiser);
        $unused->update(['title' => 'Ghost Unused Cancelled']);
        $ghost = $this->orderFor($advertiser, [
            'payment_status' => 'failed',
            'status' => 'cancelled',
        ]);
        $unused->update(['order_id' => $ghost->id]);
        $this->assertNull($unused->fresh()->load('order')->libraryOrder());

        $this->actingAs($admin)
            ->get(route('admin.content-library.show', $unused))
            ->assertOk()
            ->assertDontSee($ghost->order_number)
            ->assertDontSee(route('admin.orders.show', $ghost), false);
    }

    public function test_expired_item_only_leftover_stays_editable_and_staff_can_retry(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Expired Item Retry',
            'expires_at' => now()->subDay(),
        ]);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $this->claimByItemOnly($submission, $order, $site);

        $fresh = $submission->fresh()->load(['order', 'orderItems.order']);
        $this->assertTrue($fresh->canEditArticle());
        $this->assertTrue($fresh->canDownloadOriginal());

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $submission))
            ->post(route('admin.content-library.retry', $submission))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_unused_expired_article_cannot_be_retried(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Expired Unused Retry',
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($submission->fresh()->canEditArticle());

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $submission))
            ->post(route('admin.content-library.retry', $submission))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_unused_approved_missing_file_is_needs_fix(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Missing File Unused',
            'path' => '',
        ]);

        $fresh = $submission->fresh();
        $this->assertSame('needs_fix', $fresh->libraryAvailability());
        $this->assertTrue(
            ContentSubmission::query()->whereKey($submission->id)->needsLibraryFix()->exists()
        );

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'needs_fix']))
            ->assertOk()
            ->assertSee('Missing File Unused');
    }

    public function test_legacy_status_approved_maps_to_available_chip(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Legacy Approved Filter']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['status' => 'approved']))
            ->assertOk()
            ->assertSee('Legacy Approved Filter')
            ->assertSee('btn-primary', false);
    }

    public function test_needs_fix_includes_rejected_and_missing_image_rights(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $rejected = $this->createApprovedSubmission($advertiser);
        $rejected->update([
            'title' => 'Rejected Staff Piece',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
        ]);
        $rights = $this->createApprovedSubmission($advertiser);
        $rights->update([
            'title' => 'Missing Rights Piece',
            'preview_html' => '<p>Hello</p><img src="/storage/x.jpg" alt="x">',
            'image_rights' => null,
            'image_rights_source' => null,
        ]);
        $ready = $this->createApprovedSubmission($advertiser);
        $ready->update(['title' => 'Ready Approved Piece']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'needs_fix']))
            ->assertOk()
            ->assertSee('Rejected Staff Piece')
            ->assertSee('Missing Rights Piece')
            ->assertDontSee('Ready Approved Piece');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'available']))
            ->assertOk()
            ->assertSee('Ready Approved Piece')
            ->assertDontSee('Rejected Staff Piece')
            ->assertDontSee('Missing Rights Piece');
    }

    public function test_completed_chip_lists_live_placements(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Live Library Piece']);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($submission, $order, $site, [
            'live_url' => 'https://live.example/guest-post',
            'live_url_submitted_at' => now(),
            'publisher_status' => 'completed',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'completed']))
            ->assertOk()
            ->assertSee('Live Library Piece')
            ->assertSee('Completed/LIVE');
    }

    public function test_paid_item_only_live_placement_is_completed_not_needs_fix(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Paid Item Only Live']);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $item = $this->claimByItemOnly($submission, $order, $site);
        $item->update([
            'live_url' => 'https://live.example/item-only-admin',
            'live_url_submitted_at' => now(),
            'publisher_status' => 'completed',
        ]);

        $fresh = $submission->fresh()->load(['order', 'orderItems.order']);
        $this->assertSame('published', $fresh->libraryAvailability());
        $this->assertSame('https://live.example/item-only-admin', $fresh->liveUrl());

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'completed']))
            ->assertOk()
            ->assertSee('Paid Item Only Live')
            ->assertSee('Completed/LIVE');

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['availability' => 'needs_fix']))
            ->assertOk()
            ->assertDontSee('Paid Item Only Live');

        $this->actingAs($admin)
            ->get(route('admin.content-library.show', $submission))
            ->assertOk()
            ->assertSee('https://live.example/item-only-admin')
            ->assertSee((string) $order->id);
    }

    public function test_user_id_filter_survives_chip_and_view_links(): void
    {
        $admin = $this->admin();
        $owner = $this->advertiser();
        $other = $this->advertiser();
        $owned = $this->createApprovedSubmission($owner);
        $owned->update(['title' => 'Owner Library Piece']);
        $stranger = $this->createApprovedSubmission($other);
        $stranger->update(['title' => 'Other Advertiser Piece']);

        $html = $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['user_id' => $owner->id]))
            ->assertOk()
            ->assertSee('Owner Library Piece')
            ->assertDontSee('Other Advertiser Piece')
            ->assertSee('Advertiser filter')
            ->assertSee($owner->email)
            ->getContent();

        $this->assertStringContainsString('name="user_id"', $html);
        $this->assertStringContainsString('value="'.$owner->id.'"', $html);
        $this->assertStringContainsString('availability=available', $html);
        $this->assertStringContainsString('user_id='.$owner->id, $html);
        $this->assertStringContainsString('/admin/content-library/'.$owned->id, $html);
    }

    public function test_search_requires_every_word_in_title(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $playbook = $this->createApprovedSubmission($advertiser);
        $playbook->update(['title' => 'Growth Playbook']);
        $onlyGrowth = $this->createApprovedSubmission($advertiser);
        $onlyGrowth->update(['title' => 'Growth Only']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.index', ['q' => 'growth play']))
            ->assertOk()
            ->assertSee('Growth Playbook')
            ->assertDontSee('Growth Only');
    }

    public function test_show_page_links_user_order_and_strips_script_preview(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Support Detail Piece',
            'preview_html' => '<p>Safe intro</p><script>alert(1)</script><img src="/storage/x.jpg" alt="x">',
            'image_rights' => null,
            'anchor_text' => 'click',
            'target_url' => 'javascript:alert(1)',
            'evaluation_report' => [
                'summary' => 'Casino terms found',
                'matched_terms' => ['casino'],
                'blocked_urls' => ['https://bad.example/bet'],
                'checks' => [
                    ['status' => 'fail', 'detail' => 'Blocked gambling language'],
                ],
            ],
        ]);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($submission, $order, $site);

        $html = $this->actingAs($admin)
            ->get(route('admin.content-library.show', [
                'submission' => $submission,
                'user_id' => $advertiser->id,
                'availability' => 'in_progress',
            ]))
            ->assertOk()
            ->assertSee('Support Detail Piece')
            ->assertSee($advertiser->email)
            ->assertSee(route('admin.users.index', ['user' => $advertiser->id]), false)
            ->assertSee(route('admin.orders.show', $order), false)
            ->assertSee('Library Staff Site')
            ->assertSee('Images are not covered by a rights claim')
            ->assertSee('Blocked gambling language')
            ->assertSee('casino')
            ->assertSee('https://bad.example/bet')
            ->assertSee('Override approve')
            ->assertDontSee('Override reject')
            ->assertDontSee('>Re-evaluate<', false)
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('href="javascript:alert(1)"', $html);
        $this->assertStringContainsString('availability=in_progress', $html);
        $this->assertStringContainsString('user_id='.$advertiser->id, $html);
    }

    public function test_download_rejects_path_traversal(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'path' => '../content-uploads/escaped.docx',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-library.download', $submission))
            ->assertNotFound();
    }

    public function test_download_unknown_disk_is_404_not_500(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Bad Disk Piece',
            'disk' => 'not-a-real-disk',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-library.download', $submission))
            ->assertNotFound();
    }

    public function test_show_hides_download_when_file_missing_on_disk(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Missing File Piece',
            'path' => 'content-uploads/missing-file.docx',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-library.show', $submission))
            ->assertOk()
            ->assertSee('Original file missing on disk')
            ->assertDontSee(route('admin.content-library.download', $submission), false);
    }

    public function test_override_approve_updates_article_and_linked_scan_log(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $log = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'document_url' => 'https://example.com/doc.docx',
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'scan_token' => 'scan-lib-1',
            'word_count' => 20,
        ]);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'False Positive Piece',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'moderation_log_id' => $log->id,
            'scan_token' => 'scan-lib-1',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'approved',
                'notes' => 'False positive on brand name.',
            ])
            ->assertRedirect();

        $this->assertSame(ContentSubmission::STATUS_APPROVED, $submission->fresh()->moderation_status);
        $this->assertTrue((bool) $log->fresh()->admin_override);
        $this->assertTrue((bool) $log->fresh()->passed);
        $this->assertNotEmpty($log->fresh()->signals['override_fingerprint'] ?? null);
        $this->assertTrue($submission->fresh()->isReadyForCheckout());
        $this->assertTrue(app(ContentModerationService::class)->usableAdminOverride($submission->fresh()->load('moderationLog')));
    }

    public function test_reject_while_paid_is_forbidden(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($submission, $order, $site);

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $submission))
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'rejected',
                'notes' => 'Trying to reject a paid placement.',
            ])
            ->assertRedirect(route('admin.content-library.show', $submission))
            ->assertSessionHas('error');

        $this->assertSame(ContentSubmission::STATUS_APPROVED, $submission->fresh()->moderation_status);
    }

    public function test_archive_blocked_while_in_progress_and_allowed_when_unused(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $inProgress = $this->createApprovedSubmission($advertiser);
        $inProgress->update(['title' => 'In Progress Archive']);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($inProgress, $order, $site);

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $inProgress))
            ->post(route('admin.content-library.archive', $inProgress))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNull($inProgress->fresh()->archived_at);

        $unused = $this->createApprovedSubmission($advertiser);
        $this->actingAs($admin)
            ->post(route('admin.content-library.archive', $unused))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertNotNull($unused->fresh()->archived_at);

        $this->actingAs($admin)
            ->post(route('admin.content-library.restore', $unused))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertNull($unused->fresh()->archived_at);
    }

    public function test_retry_on_paid_article_is_forbidden(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($submission, $order, $site);

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $submission))
            ->post(route('admin.content-library.retry', $submission))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(ContentSubmission::STATUS_APPROVED, $submission->fresh()->moderation_status);
    }

    public function test_override_approve_does_not_claim_unready_article_is_orderable(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Still Unready Piece',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'preview_html' => '<p>Hello</p><img src="/storage/x.jpg" alt="x">',
            'image_rights' => null,
            'evaluation_report' => [
                'summary' => 'Casino terms found',
                'matched_terms' => ['casino'],
                'checks' => [
                    ['status' => 'fail', 'detail' => 'Blocked gambling language'],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $submission))
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'approved',
                'notes' => 'Brand name is fine here.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', function ($message) {
                return is_string($message) && str_contains($message, 'still not checkout-ready');
            });

        $fresh = $submission->fresh();
        $this->assertSame(ContentSubmission::STATUS_APPROVED, $fresh->moderation_status);
        $this->assertFalse($fresh->isReadyForCheckout());
        $this->assertSame([], $fresh->evaluationMatchedTerms());
        $this->assertSame([], $fresh->evaluationReasonGroups()['blocking']);

        $this->actingAs($admin)
            ->get(route('admin.content-library.show', $submission))
            ->assertOk()
            ->assertDontSee('Blocked gambling language')
            ->assertSee('Images are not covered by a rights claim');

        $bell = InAppNotification::query()->where('user_id', $advertiser->id)->latest('id')->first();
        $this->assertNotNull($bell);
        $this->assertStringNotContainsString('You can attach it in the catalog', (string) $bell->message);
        $this->assertStringContainsString('availability=needs_fix', (string) $bell->action_url);
    }

    public function test_override_approve_of_rejected_unpaid_leftover_is_usable_not_needs_fix(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Leftover after decline',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'evaluation_status' => ContentSubmission::STATUS_REJECTED,
        ]);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'unpaid',
            'status' => 'pending',
        ]);
        $this->attachToOrder($submission, $order, $site);

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $submission))
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'approved',
                'notes' => 'Staff restore leftover',
            ])
            ->assertRedirect(route('admin.content-library.show', $submission))
            ->assertSessionHas('success', function ($message) {
                return is_string($message) && str_contains($message, 'stays on the open order');
            });

        $fresh = $submission->fresh()->load(['order', 'orderItems.order']);
        $this->assertSame(ContentSubmission::STATUS_APPROVED, $fresh->moderation_status);
        $this->assertTrue($fresh->isUsableAfterStaffApproval());
        $this->assertFalse($fresh->isReadyForCheckout());
        $this->assertSame('in_progress', $fresh->libraryAvailability());
        $this->assertSame(['status' => 'all', 'availability' => 'in_progress'], $fresh->staffApprovalLibraryParams());

        $bell = InAppNotification::query()->where('user_id', $advertiser->id)->latest('id')->first();
        $this->assertNotNull($bell);
        $this->assertStringContainsString('continue the open order', (string) $bell->message);
        $this->assertStringNotContainsString('still needs a fix', (string) $bell->message);
        $this->assertStringContainsString('availability=in_progress', (string) $bell->action_url);
    }

    public function test_override_approve_of_item_only_leftover_is_usable_not_needs_fix_copy(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Item leftover after decline',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'evaluation_status' => ContentSubmission::STATUS_REJECTED,
        ]);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $this->claimByItemOnly($submission, $order, $site);

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $submission))
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'approved',
                'notes' => 'Staff restore item leftover',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', function ($message) {
                return is_string($message) && str_contains($message, 'stays on the open order');
            });

        $fresh = $submission->fresh()->load(['order', 'orderItems.order']);
        $this->assertNull($fresh->order_id);
        $this->assertTrue($fresh->isUsableAfterStaffApproval());
        $this->assertSame('needs_fix', $fresh->libraryAvailability());
        $this->assertSame(['status' => 'all', 'availability' => 'needs_fix'], $fresh->staffApprovalLibraryParams());

        $bell = InAppNotification::query()->where('user_id', $advertiser->id)->latest('id')->first();
        $this->assertNotNull($bell);
        $this->assertStringContainsString('continue the open order', (string) $bell->message);
        $this->assertStringNotContainsString('still needs a fix', (string) $bell->message);
        $this->assertStringContainsString('availability=needs_fix', (string) $bell->action_url);
    }

    public function test_override_approve_of_unused_expired_article_points_at_expired_chip(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Expired Unused Override',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.content-library.show', $submission))
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'approved',
                'notes' => 'False positive after expiry.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', function ($message) {
                return is_string($message) && str_contains($message, 'still not checkout-ready');
            });

        $fresh = $submission->fresh();
        $this->assertFalse($fresh->isUsableAfterStaffApproval());
        $this->assertSame('expired', $fresh->libraryAvailability());
        $this->assertSame(['status' => 'all', 'availability' => 'expired'], $fresh->staffApprovalLibraryParams());

        $bell = InAppNotification::query()->where('user_id', $advertiser->id)->latest('id')->first();
        $this->assertNotNull($bell);
        $this->assertStringContainsString('availability=expired', (string) $bell->action_url);
        $this->assertStringNotContainsString('availability=needs_fix', (string) $bell->action_url);
    }

    public function test_library_override_approve_is_honored_at_checkout_until_edit(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $body = 'Play at the best online casino and claim your no deposit bonus for slots and roulette today.';
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();
        $submission->update([
            'title' => 'Casino guide',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
        ]);

        $scan = app(ContentModerationService::class)->scanExtractedContent(
            text: $body,
            html: '<p>'.$body.'</p>',
            sourceLabel: 'upload:'.$submission->id,
            user: $advertiser,
            title: 'Casino guide',
            links: [],
            contentSubmissionId: (int) $submission->id,
        );
        $submission->update([
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'moderation_log_id' => $scan['log']?->id,
            'scan_token' => $scan['scan_token'],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'approved',
                'notes' => 'News piece about regulation, not a promo.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $submission->fresh()->load('moderationLog');
        $moderation = app(ContentModerationService::class);
        $this->assertTrue($moderation->usableAdminOverride($fresh));
        $check = $moderation->assertSubmissionsApproved([$fresh], $advertiser);
        $this->assertTrue($check['ok'], json_encode($check['failures']));

        $fresh->update([
            'extracted_text' => $body.' Extra casino bonus codes.',
        ]);
        $afterEdit = $moderation->assertSubmissionsApproved([$fresh->fresh()], $advertiser);
        $this->assertFalse($afterEdit['ok']);
        $this->assertSame(ContentSubmission::STATUS_REJECTED, $fresh->fresh()->moderation_status);
    }

    public function test_library_override_approve_without_scan_log_still_honors_checkout(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();

        $body = 'Play at the best online casino and claim your no deposit bonus for slots and roulette today.';
        $submission = $this->createApprovedSubmission($advertiser);
        config(['content_moderation.enabled' => true]);
        ContentModerationSetting::clearCache();
        $submission->update([
            'title' => 'Casino guide no log',
            'extracted_text' => $body,
            'preview_html' => '<p>'.$body.'</p>',
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'moderation_log_id' => null,
            'scan_token' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'approved',
                'notes' => 'Allow this wording for this advertiser.',
            ])
            ->assertRedirect();

        $fresh = $submission->fresh()->load('moderationLog');
        $this->assertNotNull($fresh->moderation_log_id);
        $moderation = app(ContentModerationService::class);
        $this->assertTrue($moderation->usableAdminOverride($fresh));
        $check = $moderation->assertSubmissionsApproved([$fresh], $advertiser);
        $this->assertTrue($check['ok'], json_encode($check['failures']));
    }

    public function test_moderation_override_does_not_flip_log_when_article_is_archived(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $log = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'document_url' => 'https://example.com/doc.docx',
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'scan_token' => 'scan-archived-1',
            'word_count' => 20,
        ]);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'moderation_log_id' => $log->id,
            'archived_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.moderation.index'))
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Trying to override an archived article.',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(ContentSubmission::STATUS_REJECTED, $submission->fresh()->moderation_status);
        $this->assertFalse((bool) $log->fresh()->admin_override);
        $this->assertSame(ContentModerationLog::STATUS_REJECTED, $log->fresh()->status);
    }

    public function test_moderation_override_does_not_approve_another_users_scan_token(): void
    {
        $admin = $this->admin();
        $owner = $this->advertiser();
        $other = $this->advertiser();
        $log = ContentModerationLog::create([
            'user_id' => $owner->id,
            'document_url' => 'https://example.com/doc.docx',
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'scan_token' => 'shared-token',
            'word_count' => 20,
        ]);
        $stranger = $this->createApprovedSubmission($other);
        $stranger->update([
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'scan_token' => 'shared-token',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.moderation.index'))
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'Approve this scan only.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(ContentSubmission::STATUS_REJECTED, $stranger->fresh()->moderation_status);
        $this->assertTrue((bool) $log->fresh()->admin_override);
    }

    public function test_retry_reevaluates_error_article(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'title' => 'Retry Error Piece',
            'moderation_status' => ContentSubmission::STATUS_ERROR,
            'evaluation_status' => 'error',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.content-library.retry', $submission))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotSame(ContentSubmission::STATUS_ERROR, $submission->fresh()->moderation_status);
    }

    public function test_moderation_override_updates_linked_article(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $log = ContentModerationLog::create([
            'user_id' => $advertiser->id,
            'document_url' => 'https://example.com/doc.docx',
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'scan_token' => 'scan-mod-1',
            'word_count' => 20,
        ]);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update([
            'moderation_status' => ContentSubmission::STATUS_REJECTED,
            'moderation_log_id' => $log->id,
            'scan_token' => 'scan-mod-1',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.moderation.index'))
            ->post(route('admin.moderation.override', $log), [
                'notes' => 'False positive on brand name.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(ContentSubmission::STATUS_APPROVED, $submission->fresh()->moderation_status);
        $this->assertTrue((bool) $log->fresh()->admin_override);
    }

    public function test_advertiser_cannot_use_staff_library_actions(): void
    {
        $advertiser = $this->advertiser();
        $submission = $this->createApprovedSubmission($advertiser);

        $this->actingAs($advertiser)
            ->get(route('admin.content-library.index'))
            ->assertForbidden();

        $this->actingAs($advertiser)
            ->post(route('admin.content-library.override', $submission), [
                'decision' => 'approved',
                'notes' => 'Should not work.',
            ])
            ->assertForbidden();
    }

    public function test_users_and_orders_link_into_content_library(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $order = $this->orderFor($advertiser, [
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $this->attachToOrder($submission, $order, $site);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee(route('admin.content-library.index', ['user_id' => $advertiser->id]), false);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee(route('admin.content-library.show', $submission), false)
            ->assertSee('View in Content Library');
    }

    public function test_dead_preview_json_route_is_gone(): void
    {
        $this->assertFalse(Route::has('admin.content-library.preview'));
    }
}
