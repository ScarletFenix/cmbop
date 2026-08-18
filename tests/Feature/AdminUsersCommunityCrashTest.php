<?php

namespace Tests\Feature;

use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\WebsiteSuggestion;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminUsersCommunityCrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
    }

    private function admin(): User
    {
        return $this->makeUser('admin');
    }

    private function makeUser(string $roleName, array $overrides = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->attach($role->id);

        return $user->fresh();
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
            'description' => 'A publisher site for leftover tests',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function pendingClaim(User $claimer, Site $site): SiteClaim
    {
        return SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $claimer->id,
            'website_name' => 'Owned News Daily',
            'website_url' => 'https://owned-news.example',
            'domain' => 'owned-news.example',
            'name_matches' => true,
            'proof_message' => 'WHOIS matches my account.',
            'contact_email' => $claimer->email,
            'status' => 'pending',
        ]);
    }

    private function dropColumnOrSkip(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            $this->markTestSkipped($table.'.'.$column.' is already absent');
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropColumn($column);
            });
        } catch (\Throwable) {
            $this->markTestSkipped('Could not drop '.$table.'.'.$column.' on this driver');
        }

        if (Schema::hasColumn($table, $column)) {
            $this->markTestSkipped($table.'.'.$column.' is still present after drop');
        }
    }

    private function restoreColumn(string $table, string $column, string $type = 'timestamp'): void
    {
        if (Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $type) {
            if ($type === 'string') {
                $blueprint->string($column)->nullable();

                return;
            }
            if ($type === 'text') {
                $blueprint->text($column)->nullable();

                return;
            }

            $blueprint->timestamp($column)->nullable();
        });
    }

    private function restoreCommunityTables(): void
    {
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_07_16_270000_add_community_feedback_claims_suggestions.php',
            '--force' => true,
        ]);
    }

    private function restoreOrdersTable(): void
    {
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_04_21_070134_create_orders_table.php',
            '--force' => true,
        ]);
    }

    public function test_users_index_and_mutations_work(): void
    {
        $admin = $this->admin();
        $member = $this->makeUser('advertiser', [
            'name' => 'Ada Buyer',
            'email' => 'ada.buyer@example.com',
            'company_name' => 'Ada Co',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('User Management', false)
            ->assertSee('Ada Buyer', false)
            ->assertSee('ada.buyer@example.com', false)
            ->assertSee('function companyUpdateUrl', false)
            ->assertSee('function payoutUpdateUrl', false);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['user' => $member->id]))
            ->assertOk()
            ->assertSee('Ada Buyer', false);

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateCompany', $member->id), [
                'company_name' => 'Ada Ltd',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('Ada Ltd', $member->fresh()->company_name);

        $this->actingAs($admin)
            ->postJson(route('admin.users.updatePayoutProfile', $member->id), [
                'payment_method' => 'paypal',
                'paypal_email' => 'ada.payout@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('ada.payout@example.com', $member->fresh()->payout_paypal_email);

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateRoles', $member->id), [
                'marketing' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('marketing', true);
    }

    public function test_users_index_survives_missing_orders_table(): void
    {
        $admin = $this->admin();
        $this->makeUser('advertiser', ['name' => 'No Orders User']);

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('orders');
        Schema::enableForeignKeyConstraints();
        $this->assertFalse(Schema::hasTable('orders'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.users.index'))
                ->assertOk()
                ->assertSee('User Management', false)
                ->assertSee('No Orders User', false);
        } finally {
            $this->restoreOrdersTable();
        }
    }

    public function test_users_index_survives_leftover_created_at(): void
    {
        $admin = $this->admin();
        $member = $this->makeUser('advertiser', ['name' => 'Leftover Date User']);
        DB::table('users')->where('id', $member->id)->update([
            'created_at' => 'not-a-date',
            'updated_at' => 'also-not-a-date',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('User Management', false)
            ->assertSee('Leftover Date User', false);
    }

    public function test_company_update_is_422_without_company_name_column(): void
    {
        $admin = $this->admin();
        $member = $this->makeUser('advertiser');

        $this->dropColumnOrSkip('users', 'company_name');

        try {
            $this->actingAs($admin)
                ->postJson(route('admin.users.updateCompany', $member->id), [
                    'company_name' => 'Ghost Co',
                ])
                ->assertStatus(422)
                ->assertJsonPath('success', false);
        } finally {
            $this->restoreColumn('users', 'company_name', 'string');
        }
    }

    public function test_payout_update_is_422_without_payout_columns(): void
    {
        $admin = $this->admin();
        $member = $this->makeUser('publisher');

        $this->dropColumnOrSkip('users', 'payout_preferred_method');
        $this->dropColumnOrSkip('users', 'payout_paypal_email');
        $this->dropColumnOrSkip('users', 'payout_profile_locked_at');

        try {
            $this->actingAs($admin)
                ->postJson(route('admin.users.updatePayoutProfile', $member->id), [
                    'payment_method' => 'paypal',
                    'paypal_email' => 'ghost@example.com',
                ])
                ->assertStatus(422)
                ->assertJsonPath('success', false);
        } finally {
            $this->restoreColumn('users', 'payout_preferred_method', 'string');
            $this->restoreColumn('users', 'payout_paypal_email', 'string');
            $this->restoreColumn('users', 'payout_profile_locked_at');
        }
    }

    public function test_community_tabs_and_status_updates_work(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $claimer = $this->makeUser('advertiser', ['name' => 'Claimer User']);
        $site = $this->siteFor($publisher);
        $claim = $this->pendingClaim($claimer, $site);

        $report = ProblemReport::create([
            'user_id' => $advertiser->id,
            'name' => $advertiser->name,
            'email' => $advertiser->email,
            'subject' => 'Checkout broken',
            'message' => 'The pay button does nothing.',
            'status' => 'pending',
        ]);
        $suggestion = Suggestion::create([
            'user_id' => $advertiser->id,
            'name' => $advertiser->name,
            'email' => $advertiser->email,
            'category' => 'feature',
            'message' => 'Add CSV export.',
            'status' => 'pending',
        ]);
        $website = WebsiteSuggestion::create([
            'user_id' => $advertiser->id,
            'website_name' => 'Fresh Tech Blog',
            'website_url' => 'https://fresh-tech.example',
            'domain' => 'fresh-tech.example',
            'notes' => 'Please add this.',
            'status' => 'pending',
        ]);

        foreach (['problems', 'suggestions', 'websites', 'claims'] as $tab) {
            $this->actingAs($admin)
                ->get(route('admin.community.index', ['tab' => $tab]))
                ->assertOk()
                ->assertDontSee('Something went wrong');
        }

        $this->actingAs($admin)
            ->patchJson(route('admin.community.problems.update', $report->id), [
                'status' => 'resolved',
                'admin_notes' => 'Fixed.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertSame('resolved', $report->fresh()->status);

        $this->actingAs($admin)
            ->patchJson(route('admin.community.suggestions.update', $suggestion->id), [
                'status' => 'reviewed',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($admin)
            ->patchJson(route('admin.community.websites.update', $website->id), [
                'status' => 'accepted',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($admin)
            ->postJson(route('admin.community.claims.reject', $claim->id), [
                'admin_notes' => 'Not enough proof.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertSame('rejected', $claim->fresh()->status);
    }

    public function test_community_index_survives_missing_tables(): void
    {
        $admin = $this->admin();

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('problem_reports');
        Schema::dropIfExists('suggestions');
        Schema::dropIfExists('website_suggestions');
        Schema::dropIfExists('site_claims');
        Schema::enableForeignKeyConstraints();

        try {
            foreach (['problems', 'suggestions', 'websites', 'claims'] as $tab) {
                $this->actingAs($admin)
                    ->get(route('admin.community.index', ['tab' => $tab]))
                    ->assertOk()
                    ->assertDontSee('Something went wrong');
            }

            $this->actingAs($admin)
                ->patchJson(route('admin.community.problems.update', 1), [
                    'status' => 'resolved',
                ])
                ->assertNotFound();

            $this->actingAs($admin)
                ->postJson(route('admin.community.claims.approve', 1))
                ->assertNotFound();

            $this->actingAs($admin)
                ->postJson(route('admin.community.claims.reject', 1))
                ->assertNotFound();
        } finally {
            $this->restoreCommunityTables();
        }
    }

    public function test_community_index_survives_leftover_dates(): void
    {
        $admin = $this->admin();
        $advertiser = $this->makeUser('advertiser');
        $report = ProblemReport::create([
            'user_id' => $advertiser->id,
            'name' => $advertiser->name,
            'email' => $advertiser->email,
            'subject' => 'Leftover date report',
            'message' => 'Junk timestamps should not 500.',
            'status' => 'pending',
        ]);
        DB::table('problem_reports')->where('id', $report->id)->update([
            'created_at' => 'not-a-date',
            'reviewed_at' => 'also-not-a-date',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'problems']))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->assertSee('Leftover date report', false);
    }

    public function test_problem_update_still_works_without_reviewed_at(): void
    {
        $admin = $this->admin();
        $report = ProblemReport::create([
            'name' => 'Guest',
            'email' => 'guest@example.com',
            'subject' => 'No reviewed_at',
            'message' => 'Still mark resolved.',
            'status' => 'pending',
        ]);

        $this->dropColumnOrSkip('problem_reports', 'reviewed_at');

        try {
            $this->actingAs($admin)
                ->patchJson(route('admin.community.problems.update', $report->id), [
                    'status' => 'resolved',
                    'admin_notes' => 'Done.',
                ])
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame('resolved', $report->fresh()->status);
        } finally {
            $this->restoreColumn('problem_reports', 'reviewed_at');
        }
    }

    public function test_website_tab_survives_missing_sites_table(): void
    {
        $admin = $this->admin();
        WebsiteSuggestion::create([
            'website_name' => 'Ghost Site',
            'website_url' => 'https://ghost-site.example',
            'domain' => 'ghost-site.example',
            'status' => 'pending',
        ]);

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('site_claims');
        Schema::dropIfExists('sites');
        Schema::enableForeignKeyConstraints();
        $this->assertFalse(Schema::hasTable('sites'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.community.index', ['tab' => 'websites']))
                ->assertOk()
                ->assertDontSee('Something went wrong')
                ->assertSee('Ghost Site', false);
        } finally {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_04_06_094704_create_sites_table.php',
                '--force' => true,
            ]);
            $this->restoreCommunityTables();
        }
    }

    public function test_claims_tab_survives_missing_order_tables(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher');
        $claimer = $this->makeUser('advertiser', ['name' => 'Orderless Claimer']);
        $site = $this->siteFor($publisher);
        $this->pendingClaim($claimer, $site);

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('order_item_disputes');
        Schema::dropIfExists('order_items');
        Schema::enableForeignKeyConstraints();
        $this->assertFalse(Schema::hasTable('order_items'));

        try {
            $this->actingAs($admin)
                ->get(route('admin.community.index', ['tab' => 'claims']))
                ->assertOk()
                ->assertSee('Community feedback', false)
                ->assertSee('Orderless Claimer', false);
        } finally {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_04_21_070217_create_order_items_table.php',
                '--force' => true,
            ]);
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_31_150200_create_order_item_disputes_table.php',
                '--force' => true,
            ]);
        }
    }

    public function test_claim_approve_still_transfers_without_reviewed_at(): void
    {
        $admin = $this->admin();
        $publisher = $this->makeUser('publisher');
        $claimer = $this->makeUser('advertiser');
        $claimer->assignRole('publisher');
        $site = $this->siteFor($publisher);
        $claim = $this->pendingClaim($claimer, $site);

        $this->dropColumnOrSkip('site_claims', 'reviewed_at');

        try {
            $this->actingAs($admin)
                ->postJson(route('admin.community.claims.approve', $claim->id))
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame('approved', $claim->fresh()->status);
            $this->assertSame($claimer->id, $site->fresh()->publisher_id);
        } finally {
            $this->restoreColumn('site_claims', 'reviewed_at');
        }
    }

    public function test_community_search_array_filter_does_not_500(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.community.index', [
                'tab' => ['claims'],
                'q' => ['injected'],
                'status' => ['pending'],
            ]))
            ->assertOk()
            ->assertDontSee('Something went wrong');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['user' => ['12']]))
            ->assertOk()
            ->assertSee('User Management', false);
    }
}
