<?php

namespace Tests\Feature;

use App\Mail\AdminAssignedSiteNotification;
use App\Models\Category;
use App\Models\Country;
use App\Models\InAppNotification;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminAssignSiteForPublisherTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    public function test_admin_can_create_site_for_publisher_pending_acceptance(): void
    {
        Mail::fake();

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $response = $this->actingAs($this->admin)->post(route('admin.sites.store'), [
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Staff Added News',
            'site_url' => 'https://staff-added-news.example',
            'example_url' => 'https://staff-added-news.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $niche,
            'price' => 99,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Quality editorial site for guest posts. ', 4),
            'site_tag' => 'as_you_prefer',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $site = Site::where('domain', 'staff-added-news.example')->first();
        $this->assertNotNull($site);
        $this->assertSame((int) $this->publisher->id, (int) $site->publisher_id);
        $this->assertSame((int) $this->admin->id, (int) $site->assigned_by_user_id);
        $this->assertNull($site->publisher_accepted_at);
        $this->assertFalse((bool) $site->active);
        $this->assertFalse((bool) $site->verified);
        $this->assertTrue($site->isPendingPublisherAcceptance());
        $this->assertFalse($site->needsAdminReview());

        Mail::assertQueued(AdminAssignedSiteNotification::class, function ($mail) {
            return $mail->hasTo($this->publisher->email);
        });

        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('title', 'Please accept a website we added for you')
                ->exists()
        );

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->assertDontSee('staff-added-news.example', false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'invites']))
            ->assertOk()
            ->assertSee('staff-added-news.example', false)
            ->assertSee('Accept', false);
    }

    public function test_publisher_accept_moves_site_into_my_sites_pending(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => null,
            'site_name' => 'Invite Site',
            'site_url' => 'https://invite-site.example',
            'domain' => 'invite-site.example',
            'example_url' => 'https://invite-site.example/post',
            'da' => 30,
            'dr' => 30,
            'traffic' => 5000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Invite site description for acceptance. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.accept-assignment', $site->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        $site->refresh();
        $this->assertNotNull($site->publisher_accepted_at);
        $this->assertFalse($site->isPendingPublisherAcceptance());
        $this->assertTrue($site->needsAdminReview());

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('invite-site.example', false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'invites']))
            ->assertOk()
            ->assertDontSee('invite-site.example', false);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_publisher_reject_deletes_pending_invite(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => null,
            'site_name' => 'Decline Site',
            'site_url' => 'https://decline-site.example',
            'domain' => 'decline-site.example',
            'example_url' => 'https://decline-site.example/post',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Decline this invite site description. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.reject-assignment', $site->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    public function test_publisher_self_created_sites_are_accepted_immediately(): void
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->orderBy('name')->value('name');

        $this->actingAs($this->publisher)->post(route('publisher.sites.store'), [
            'siteName' => 'Self Added',
            'siteUrl' => 'self-added.example',
            'exampleUrl' => 'https://self-added.example/post',
            'da' => 33,
            'dr' => 34,
            'traffic' => 8000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $niche,
            'price' => 70,
            'turnaround_time' => '3days',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => str_repeat('Self created site description here. ', 4),
            'site_tag' => 'as_you_prefer',
        ])->assertRedirect();

        $site = Site::where('domain', 'self-added.example')->first();
        $this->assertNotNull($site);
        $this->assertNotNull($site->publisher_accepted_at);
        $this->assertFalse($site->isPendingPublisherAcceptance());
    }
}
