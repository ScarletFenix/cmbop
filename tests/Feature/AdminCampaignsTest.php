<?php

namespace Tests\Feature;

use App\Mail\AudienceCampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailNotificationPreference;
use App\Models\Order;
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
            ->assertSee('data-slb-confirm="Send this campaign', false)
            ->assertSee('requestSubmit() throws if the submitter is disabled', false)
            ->assertSee("Accept': 'application/json, text/html'", false)
            ->assertSee('name="include_unverified" value="0"', false)
            ->assertSee('Advertisers: never checked out', false)
            ->assertSee('value="advertisers_no_paid_orders"', false);
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
                'unverified_excluded' => 0,
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

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', ['audience' => 'not-a-segment']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['audience']);
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

    public function test_preview_strips_javascript_links(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.preview'), [
                'subject' => 'Safe preview',
                'body_html' => '<p>Go <a href="javascript:alert(1)">here</a></p>',
            ])
            ->assertOk()
            ->assertDontSee('javascript:', false)
            ->assertSee('here', false);
    }

    public function test_preview_rejects_javascript_cta_url(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->postJson(route('admin.campaigns.preview'), [
                'subject' => 'Bad CTA',
                'body_html' => '<p>Hello</p>',
                'cta_label' => 'Click',
                'cta_url' => 'javascript:alert(1)',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cta_url']);
    }

    public function test_unverified_advertisers_are_excluded_by_default(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $verified = $this->makeUser('advertiser');
        $unverified = $this->makeUser('advertiser');
        $unverified->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', ['audience' => 'advertisers']))
            ->assertOk()
            ->assertJson([
                'count' => 1,
                'unverified_excluded' => 1,
            ]);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'respect_preferences' => '0',
                'include_unverified' => '0',
            ]))
            ->assertRedirect(route('admin.campaigns.index'));

        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($verified->email));
        Mail::assertNotQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($unverified->email));
    }

    public function test_include_unverified_sends_to_unverified_users(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $unverified = $this->makeUser('advertiser');
        $unverified->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'respect_preferences' => '0',
                'include_unverified' => '1',
            ]))
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success');

        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($unverified->email));
    }

    public function test_selected_audience_rejects_admin_ids(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $otherAdmin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'audience' => 'selected',
                'user_ids' => [$otherAdmin->id],
                'respect_preferences' => '0',
            ]))
            ->assertRedirect()
            ->assertSessionHas('error', 'No recipients found for that audience.');

        Mail::assertNothingQueued();
    }

    public function test_no_paid_orders_excludes_paid_but_keeps_abandoned_checkout(): void
    {
        $admin = $this->makeUser('admin');
        $neverCheckedOut = $this->makeUser('advertiser');
        $abandoned = $this->makeUser('advertiser');
        $paid = $this->makeUser('advertiser');

        Order::create([
            'user_id' => $abandoned->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-ABANDON-'.random_int(1000, 9999),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        Order::create([
            'user_id' => $paid->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-PAID-'.random_int(1000, 9999),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        $inventory = app(AudienceInventoryService::class);

        $neverIds = $inventory->collect('advertisers_never_checked_out')->pluck('id')->all();
        $this->assertContains($neverCheckedOut->id, $neverIds);
        $this->assertNotContains($abandoned->id, $neverIds);
        $this->assertNotContains($paid->id, $neverIds);
        $this->assertSame(
            $inventory->collect('advertisers_no_orders')->pluck('id')->all(),
            $neverIds
        );

        $noPaidIds = $inventory->collect('advertisers_no_paid_orders')->pluck('id')->all();
        $this->assertContains($neverCheckedOut->id, $noPaidIds);
        $this->assertContains($abandoned->id, $noPaidIds);
        $this->assertNotContains($paid->id, $noPaidIds);

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'no_paid_orders']))
            ->assertOk()
            ->assertSee($abandoned->email, false)
            ->assertDontSee($paid->email, false)
            ->assertSee(route('admin.campaigns.index', ['audience' => 'advertisers_no_paid_orders'], false), false);
    }
}
