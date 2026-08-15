<?php

namespace Tests\Feature;

use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\WebsiteSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityFeedbackTest extends TestCase
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

    public function test_guest_can_report_a_problem(): void
    {
        $this->postJson(route('feedback.problem'), [
            'name' => 'Guest User',
            'email' => 'guest@example.com',
            'subject' => 'Checkout broken',
            'message' => 'The checkout button does nothing on mobile.',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('problem_reports', [
            'email' => 'guest@example.com',
            'subject' => 'Checkout broken',
            'status' => 'pending',
        ]);
    }

    public function test_user_can_send_suggestion(): void
    {
        $user = $this->userWithRole('advertiser');

        $this->actingAs($user)->postJson(route('feedback.suggestion'), [
            'category' => 'feature',
            'message' => 'Please add CSV export for orders.',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, Suggestion::count());
    }

    public function test_advertiser_can_suggest_missing_website(): void
    {
        $user = $this->userWithRole('advertiser');

        $this->actingAs($user)->postJson(route('advertiser.website-suggestions.store'), [
            'website_name' => 'Fresh Tech Blog',
            'website_url' => 'https://fresh-tech.example',
            'notes' => 'Great niche for SaaS',
            'search_query' => 'fresh tech',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('website_suggestions', [
            'domain' => 'fresh-tech.example',
            'status' => 'pending',
        ]);
    }

    public function test_publisher_can_claim_website_with_matching_name(): void
    {
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);

        $this->actingAs($claimer)->postJson(route('publisher.sites.claim'), [
            'website_url' => 'https://owned-news.example',
            'website_name' => 'Owned News Daily',
            'proof_message' => 'I own this domain via registrar account and CMS admin access.',
            'contact_email' => $claimer->email,
        ])->assertOk()->assertJson(['success' => true, 'name_matches' => true]);

        $claim = SiteClaim::first();
        $this->assertNotNull($claim);
        $this->assertTrue($claim->name_matches);
        $this->assertSame('pending', $claim->status);
    }

    public function test_advertiser_can_claim_catalog_site_by_id(): void
    {
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('advertiser');
        $site = $this->siteFor($owner);

        $this->actingAs($claimer)->postJson(route('advertiser.sites.claim'), [
            'site_id' => $site->id,
            'proof_message' => 'I own this domain via registrar account and CMS admin access.',
            'contact_email' => $claimer->email,
        ])->assertOk()->assertJson(['success' => true, 'name_matches' => true]);

        $claim = SiteClaim::first();
        $this->assertNotNull($claim);
        $this->assertSame($site->id, (int) $claim->site_id);
        $this->assertSame($claimer->id, (int) $claim->claimer_id);
        $this->assertSame('Owned News Daily', $claim->website_name);
    }

    public function test_catalog_and_publisher_sites_both_expose_claim_entry_points(): void
    {
        $owner = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->siteFor($owner);

        $catalog = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('btn-claim-site', $catalog);
        $this->assertStringContainsString('siteClaim', $catalog);
        $this->assertMatchesRegularExpression('#advertiser\\\\?/sites\\\\?/claim#', $catalog);

        // Publishers can also claim via My Sites (URL + exact listing name form).
        $publisherPage = $this->actingAs($owner)
            ->get(route('publisher.sites.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('showClaimBtn', $publisherPage);
        $this->assertStringContainsString('Claim a website', $publisherPage);
        $this->assertStringContainsString('My claims', $publisherPage);
        $this->assertStringContainsString('site-claims', $publisherPage);
    }

    public function test_admin_can_approve_claim_and_transfer_ownership(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);

        $claim = SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $claimer->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'name_matches' => true,
            'proof_message' => 'Domain WHOIS matches my company email.',
            'contact_email' => $claimer->email,
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->postJson(route('admin.community.claims.approve', $claim->id), [
            'admin_notes' => 'Verified via domain email.',
        ])->assertOk()->assertJson(['success' => true]);

        $site->refresh();
        $claim->refresh();
        $this->assertSame($claimer->id, (int) $site->publisher_id);
        $this->assertSame('approved', $claim->status);
    }

    public function test_cannot_suggest_website_already_in_catalog(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $advertiser = $this->userWithRole('advertiser');

        $this->actingAs($advertiser)->postJson(route('advertiser.website-suggestions.store'), [
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'That website is already listed in our catalog. Try searching for “owned-news.example”.']);
    }

    public function test_cannot_suggest_website_already_on_file_but_not_in_catalog(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $site->update(['verified' => false]);
        $advertiser = $this->userWithRole('advertiser');

        $this->actingAs($advertiser)->postJson(route('advertiser.website-suggestions.store'), [
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
        ])->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'We already have this website on file. It is not currently available in the catalog.',
            ]);
    }

    public function test_admin_can_resolve_a_problem_but_not_mark_it_accepted(): void
    {
        $admin = $this->userWithRole('admin');
        $report = ProblemReport::create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'subject' => 'Checkout broken',
            'message' => 'The pay button does nothing on mobile.',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->patchJson(route('admin.community.problems.update', $report->id), [
            'status' => 'resolved',
            'admin_notes' => 'Fixed the mobile CTA.',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame('resolved', $report->fresh()->status);

        $this->actingAs($admin)->patchJson(route('admin.community.problems.update', $report->id), [
            'status' => 'accepted',
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);

        $this->actingAs($admin)->patchJson(route('admin.community.problems.update', $report->id), [
            'status' => 'approved',
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_admin_can_accept_a_website_suggestion_but_not_approve_it(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $suggestion = WebsiteSuggestion::create([
            'user_id' => $advertiser->id,
            'website_name' => 'Fresh Tech Blog',
            'website_url' => 'https://fresh-tech.example',
            'domain' => 'fresh-tech.example',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->patchJson(route('admin.community.websites.update', $suggestion->id), [
            'status' => 'accepted',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame('accepted', $suggestion->fresh()->status);

        $this->actingAs($admin)->patchJson(route('admin.community.websites.update', $suggestion->id), [
            'status' => 'approved',
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_claims_filter_includes_approved_and_ignores_accepted(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $site = $this->siteFor($owner);

        SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $claimer->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'name_matches' => true,
            'proof_message' => 'Approved-claim WHOIS proof for filter test.',
            'contact_email' => $claimer->email,
            'status' => 'approved',
        ]);
        SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $this->userWithRole('publisher')->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'name_matches' => false,
            'proof_message' => 'Pending-claim registrar screenshots for filter test.',
            'contact_email' => 'other@example.com',
            'status' => 'pending',
        ]);

        $approvedHtml = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'claims', 'status' => 'approved']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="approved"[^>]*\bselected\b/', $approvedHtml);
        $this->assertStringNotContainsString('value="accepted"', $approvedHtml);
        $this->assertStringContainsString('Approved-claim WHOIS proof for filter test.', $approvedHtml);
        $this->assertStringNotContainsString('Pending-claim registrar screenshots for filter test.', $approvedHtml);

        $acceptedFilter = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'claims', 'status' => 'accepted']))
            ->assertOk()
            ->getContent();

        // Invalid for claims → treated as All, so both rows render.
        $this->assertStringContainsString('Pending-claim registrar screenshots for filter test.', $acceptedFilter);
        $this->assertStringContainsString('Approved-claim WHOIS proof for filter test.', $acceptedFilter);
        $this->assertStringNotContainsString('value="accepted"', $acceptedFilter);
    }

    public function test_problems_filter_omits_approved_and_tab_switch_drops_it(): void
    {
        $admin = $this->userWithRole('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'problems']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="resolved"', $html);
        $this->assertStringNotContainsString('value="approved"', $html);
        $this->assertStringNotContainsString('value="accepted"', $html);
        $this->assertStringNotContainsString('${btn.dataset.notes', $html);
        $this->assertStringContainsString('notes.value = btn.dataset.notes', $html);
        $this->assertStringContainsString('Network error', $html);
        $this->assertStringNotContainsString("data.message || 'Done'", $html);

        $fromClaims = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'claims', 'status' => 'approved']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('admin.community.index', ['tab' => 'problems']),
            $fromClaims
        );
        $this->assertStringNotContainsString(
            route('admin.community.index', ['tab' => 'problems', 'status' => 'approved']),
            $fromClaims
        );
    }

    public function test_status_modal_does_not_embed_admin_notes_in_markup(): void
    {
        $admin = $this->userWithRole('admin');
        ProblemReport::create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'subject' => 'XSS check',
            'message' => 'Notes should not be interpolated into SweetAlert html.',
            'status' => 'pending',
            'admin_notes' => '</textarea><img src=x onerror=alert(1)>',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'problems']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('</textarea><img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;/textarea&gt;', $html);
    }
}
