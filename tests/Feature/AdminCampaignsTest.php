<?php

namespace Tests\Feature;

use App\Mail\AudienceCampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailNotificationPreference;
use App\Models\Role;
use App\Models\User;
use App\Services\AudienceInventoryService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminCampaignsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function campaignPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Spring update',
            'subject' => 'Platform update',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
            'respect_preferences' => '1',
        ], $overrides);
    }

    public function test_guest_is_redirected_from_campaigns(): void
    {
        $this->get(route('admin.campaigns.index'))
            ->assertRedirect(route('login'));
    }

    public function test_advertiser_cannot_access_campaigns(): void
    {
        $this->actingAs($this->makeUser('advertiser'))
            ->get(route('admin.campaigns.index'))
            ->assertForbidden();
    }

    public function test_marketing_is_redirected_from_campaigns(): void
    {
        $this->actingAs($this->makeUser('marketing'))
            ->get(route('admin.campaigns.index'))
            ->assertRedirect(route('marketing.dashboard'));
    }

    public function test_admin_index_loads_and_preselects_audience(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeUser('advertiser');

        $this->actingAs($admin)
            ->get(route('admin.campaigns.index', ['audience' => 'publishers_no_sites']))
            ->assertOk()
            ->assertSee('value="publishers_no_sites"', false)
            ->assertSee('name="respect_preferences" value="0"', false)
            ->assertSee('id="previewStatus"', false)
            ->assertSee('data-slb-confirm="Send this campaign', false);
    }

    public function test_preview_returns_html_for_valid_payload(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.preview'), [
                'subject' => 'Preview subject',
                'body_html' => '<p>Preview body</p>',
            ])
            ->assertOk()
            ->assertSee('Preview body', false);
    }

    public function test_preview_rejects_empty_body(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->postJson(route('admin.campaigns.preview'), [
                'subject' => 'Has a subject',
                'body_html' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body_html']);
    }

    public function test_recipient_count_matches_collect_for_core_audiences(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $both = $this->makeUser('advertiser');
        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $both->roles()->attach($pubRole->id);

        $inventory = app(AudienceInventoryService::class);

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', ['audience' => 'advertisers']))
            ->assertOk()
            ->assertJson([
                'count' => $inventory->collect('advertisers')->count(),
                'label' => 'Advertisers',
            ]);

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', ['audience' => 'publishers']))
            ->assertOk()
            ->assertJson([
                'count' => $inventory->collect('publishers')->count(),
                'label' => 'Publishers',
            ]);

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', ['audience' => 'both']))
            ->assertOk()
            ->assertJson([
                'count' => $inventory->collect('both')->count(),
                'label' => 'Advertisers + Publishers',
            ]);

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', [
                'audience' => 'selected',
                'user_ids' => [$advertiser->id, $publisher->id],
            ]))
            ->assertOk()
            ->assertJson([
                'count' => 2,
                'label' => 'Selected users',
            ]);

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', ['audience' => 'selected']))
            ->assertOk()
            ->assertJson([
                'count' => 0,
                'label' => 'Selected users',
            ]);
    }

    public function test_hidden_zero_disables_preference_gate(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $optedOut = $this->makeUser('advertiser');
        EmailNotificationPreference::create([
            'user_id' => $optedOut->id,
            'preference_key' => 'marketing_emails',
            'enabled' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'respect_preferences' => '0',
            ]))
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success', fn ($msg) => str_contains((string) $msg, 'Queued for 1 recipient'));

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertFalse($campaign->respect_preferences);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(0, $campaign->skipped_count);

        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($optedOut->email));
    }

    public function test_preference_checkbox_one_skips_opted_out_users(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $optedOut = $this->makeUser('advertiser');
        $optedIn = $this->makeUser('advertiser');
        EmailNotificationPreference::create([
            'user_id' => $optedOut->id,
            'preference_key' => 'marketing_emails',
            'enabled' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'respect_preferences' => '1',
            ]))
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success', fn ($msg) => str_contains((string) $msg, 'Queued for 1 recipient')
                && str_contains((string) $msg, 'Skipped 1'));

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertTrue($campaign->respect_preferences);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(1, $campaign->skipped_count);

        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($optedIn->email));
        Mail::assertNotQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($optedOut->email));
    }

    public function test_campaign_routes_are_throttled(): void
    {
        $routes = collect(app('router')->getRoutes());

        $preview = $routes->first(fn ($route) => $route->getName() === 'admin.campaigns.preview');
        $send = $routes->first(fn ($route) => $route->getName() === 'admin.campaigns.send');
        $count = $routes->first(fn ($route) => $route->getName() === 'admin.campaigns.recipient-count');

        $this->assertNotNull($preview);
        $this->assertNotNull($send);
        $this->assertNotNull($count);
        $this->assertContains('throttle:20,1', $preview->gatherMiddleware());
        $this->assertContains('throttle:6,1', $send->gatherMiddleware());
        $this->assertContains('throttle:30,1', $count->gatherMiddleware());
    }
}
