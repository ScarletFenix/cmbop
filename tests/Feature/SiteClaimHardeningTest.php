<?php

namespace Tests\Feature;

use App\Mail\SiteClaimOwnershipTransferred;
use App\Mail\SiteClaimReviewed;
use App\Mail\SiteClaimSubmitted;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SiteClaimHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function admin(): User
    {
        return $this->userWithRole('admin');
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Owned News Daily',
            'site_url' => 'https://owned-news.example',
            'domain' => 'owned-news.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => '3',
            'description' => 'A publisher site for claim tests',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function pendingClaimFor(Site $site, User $claimer, bool $nameMatches = true): SiteClaim
    {
        return SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $claimer->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'name_matches' => $nameMatches,
            'proof_message' => 'Domain WHOIS matches my company email and I have CMS access.',
            'contact_email' => $claimer->email,
            'status' => 'pending',
        ]);
    }

    public function test_submitting_a_claim_notifies_admins(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);

        $this->actingAs($claimer)->postJson(route('publisher.sites.claim'), [
            'website_url' => 'https://owned-news.example',
            'website_name' => 'Owned News Daily',
            'proof_message' => 'I own this domain via registrar account and CMS admin access.',
            'contact_email' => $claimer->email,
        ])->assertOk()->assertJson(['success' => true]);

        Mail::assertQueued(SiteClaimSubmitted::class);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $admin->id,
            'audience' => InAppNotification::AUDIENCE_ADMIN,
            'title' => 'New site ownership claim',
        ]);
    }

    public function test_approve_transfers_ownership_and_grants_publisher_role_to_advertiser(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('advertiser');
        // Ensure a publisher role exists so it can be granted on approval.
        Role::firstOrCreate(['name' => 'publisher']);

        $site = $this->siteFor($owner);
        if (Site::hasSitesColumn('assigned_by_user_id')) {
            $site->forceFill(['assigned_by_user_id' => $owner->id])->save();
        }

        $claim = $this->pendingClaimFor($site, $claimer);

        $this->actingAs($admin)->postJson(route('admin.community.claims.approve', $claim->id), [
            'admin_notes' => 'Verified via domain email.',
        ])->assertOk()->assertJson(['success' => true]);

        $site->refresh();
        $claim->refresh();
        $claimer->refresh();

        $this->assertSame($claimer->id, (int) $site->publisher_id);
        $this->assertSame('approved', $claim->status);
        $this->assertTrue($claimer->hasRole('publisher'));

        if (Site::hasSitesColumn('publisher_accepted_at')) {
            $this->assertNotNull($site->publisher_accepted_at);
        }
        if (Site::hasSitesColumn('assigned_by_user_id')) {
            $this->assertNull($site->assigned_by_user_id);
        }

        Mail::assertQueued(SiteClaimReviewed::class);
        Mail::assertQueued(SiteClaimOwnershipTransferred::class);
    }

    public function test_approve_blocked_when_site_has_open_order_item(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $owner = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $claimer = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-OPEN-1',
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

        $claim = $this->pendingClaimFor($site, $claimer);

        $this->actingAs($admin)->postJson(route('admin.community.claims.approve', $claim->id), [])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'open_orders' => 1]);

        $site->refresh();
        $claim->refresh();
        $this->assertSame($owner->id, (int) $site->publisher_id, 'Ownership must not transfer while orders are open.');
        $this->assertSame('pending', $claim->status);

        Mail::assertNotQueued(SiteClaimReviewed::class);
    }

    public function test_reject_notifies_claimer(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);
        $claim = $this->pendingClaimFor($site, $claimer);

        $this->actingAs($admin)->postJson(route('admin.community.claims.reject', $claim->id), [
            'admin_notes' => 'Could not verify ownership.',
        ])->assertOk()->assertJson(['success' => true]);

        $claim->refresh();
        $this->assertSame('rejected', $claim->status);
        $this->assertSame($owner->id, (int) $site->fresh()->publisher_id);

        Mail::assertQueued(SiteClaimReviewed::class);
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $claimer->id,
        ]);
    }

    public function test_sibling_pending_claims_are_rejected_on_approve(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $owner = $this->userWithRole('publisher');
        $claimerA = $this->userWithRole('publisher');
        $claimerB = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);

        $claimA = $this->pendingClaimFor($site, $claimerA);
        $claimB = $this->pendingClaimFor($site, $claimerB);

        $this->actingAs($admin)->postJson(route('admin.community.claims.approve', $claimA->id), [])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame('approved', $claimA->fresh()->status);
        $this->assertSame('rejected', $claimB->fresh()->status);
        $this->assertSame(
            'Closed because another claim was approved.',
            $claimB->fresh()->admin_notes
        );

        // Winner + previous owner + closed sibling each get a reviewed/transfer mail.
        Mail::assertQueued(SiteClaimReviewed::class, function (SiteClaimReviewed $mail) use ($claimerA) {
            return (int) $mail->claim->claimer_id === (int) $claimerA->id
                && $mail->claim->status === 'approved';
        });
        Mail::assertQueued(SiteClaimReviewed::class, function (SiteClaimReviewed $mail) use ($claimerB) {
            return (int) $mail->claim->claimer_id === (int) $claimerB->id
                && $mail->claim->status === 'rejected';
        });

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $claimerB->id,
        ]);
    }

    public function test_stale_in_memory_claim_cannot_overwrite_already_reviewed_sibling(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $owner = $this->userWithRole('publisher');
        $claimerA = $this->userWithRole('publisher');
        $claimerB = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);

        $claimA = $this->pendingClaimFor($site, $claimerA);
        $claimB = $this->pendingClaimFor($site, $claimerB);

        $this->actingAs($admin)->postJson(route('admin.community.claims.approve', $claimA->id), [])
            ->assertOk();

        // Stale in-memory model still says pending — must not resurrect as approved.
        $this->assertSame('pending', $claimB->status);

        $this->actingAs($admin)->postJson(route('admin.community.claims.approve', $claimB->id), [])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame('approved', $claimA->fresh()->status);
        $this->assertSame('rejected', $claimB->fresh()->status);
        $this->assertSame($claimerA->id, (int) $site->fresh()->publisher_id);
    }

    public function test_admin_queue_counts_include_pending_claims(): void
    {
        $admin = $this->admin();
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);
        $this->pendingClaimFor($site, $claimer);

        $this->actingAs($admin)->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJson(['success' => true, 'pending_claims' => 1]);
    }

    public function test_publisher_websites_shows_claims_panel(): void
    {
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);
        $this->pendingClaimFor($site, $claimer);

        $this->actingAs($claimer)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertSee('Your ownership claims');
    }

    public function test_claimer_can_view_their_claims_page(): void
    {
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('advertiser');
        $site = $this->siteFor($owner);
        $this->pendingClaimFor($site, $claimer);

        $this->actingAs($claimer)
            ->get(route('site-claims.index'))
            ->assertOk()
            ->assertSee('Your ownership claims');
    }
}
