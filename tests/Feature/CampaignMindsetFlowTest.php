<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CampaignMindsetFlowTest extends TestCase
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

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function site(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Campaign Pub',
            'site_url' => 'https://camp-pub.example',
            'domain' => 'camp-pub.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 50.00,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Campaign test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_campaigns_index_shows_real_zero_counts(): void
    {
        $advertiser = $this->advertiser();
        $project = $this->createCampaign($advertiser, 'Client A', 'https://client-a.example');

        $response = $this->actingAs($advertiser)->get(route('advertiser.campaigns'));

        $response->assertOk();
        $response->assertSee('Client A');
        $response->assertSee('Guest Posting');
        $response->assertDontSee('rand(');
    }

    public function test_activate_campaign_sets_session_and_redirects_to_catalog(): void
    {
        $advertiser = $this->advertiser();
        $project = $this->createCampaign($advertiser);

        $response = $this->actingAs($advertiser)
            ->post(route('advertiser.campaigns.activate', $project));

        $response->assertRedirect(route('advertiser.catalog'));
        $this->assertSame($project->id, (int) session('active_campaign_id'));
    }

    public function test_checkout_requires_campaign_when_project_column_exists(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->site($publisher);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wise',
                'reference_code' => 'NOCAMP',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'needs_campaign' => true,
        ]);
        $this->assertSame(0, Order::count());
    }

    public function test_checkout_stamps_project_id_on_order_package(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->site($publisher);
        $campaign = $this->createCampaign($advertiser);
        $submission = $this->createApprovedSubmission($advertiser, $site->id);

        $advRole = Role::where('name', 'advertiser')->firstOrFail();
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 500,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                ]],
                'active_campaign_id' => $campaign->id,
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'CAMP1',
                'project_id' => $campaign->id,
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $order = Order::where('reference_code', 'CAMP1')->first();
        $this->assertNotNull($order);
        $this->assertSame($campaign->id, (int) $order->project_id);

        $show = $this->actingAs($advertiser)->get(route('advertiser.campaigns.show', $campaign));
        $show->assertOk();
        $show->assertSee('#'.$order->order_number);
    }
}
