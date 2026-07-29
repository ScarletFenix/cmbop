<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherEditClearsAwaitingDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $publisher;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $this->category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();
    }

    private function makeAwaitingDetailsSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Stuck Draft Site',
            'site_url' => 'https://stuck-draft.example',
            'domain' => 'stuck-draft.example',
            'example_url' => 'https://stuck-draft.example/guest',
            'da' => 30,
            'dr' => 35,
            'traffic' => 8000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'languages' => ['de'],
            'category' => $this->category->name,
            'categories' => [$this->category->name],
            'price' => 120,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Quality editorial site for guest posts. ', 4),
            'verified' => false,
            'active' => false,
            'as_you_prefer' => true,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ], $overrides));
    }

    public function test_publisher_edit_promotes_awaiting_details_to_ready_for_review(): void
    {
        $site = $this->makeAwaitingDetailsSite([
            'description' => 'Please replace this placeholder with a real site description (at least 50 characters) before submitting for review.',
        ]);

        $this->actingAs($this->publisher)
            ->put(route('publisher.sites.update', $site->id), [
                'exampleUrl' => 'https://stuck-draft.example/sample-post',
                'da' => 30,
                'dr' => 35,
                'traffic' => 8000,
                'country' => 'de',
                'language' => 'de',
                'categories' => [$this->category->name],
                'price' => 120,
                'turnaround_time' => '48h',
                'publicationTime' => '1year',
                'link_type' => 'dofollow',
                'site_tag' => 'as_you_prefer',
                'siteDescription' => str_repeat('Updated quality editorial site for guest posts. ', 3),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $site->refresh();
        $this->assertSame(Site::ONBOARDING_READY_FOR_REVIEW, $site->onboarding_status);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue((bool) $site->fresh()->active);
    }

    public function test_admin_can_activate_stale_awaiting_details_site_when_details_already_complete(): void
    {
        $site = $this->makeAwaitingDetailsSite();

        $this->assertTrue($site->awaitsPublisherDetails());
        $this->assertTrue($site->hasCompletedPublisherDetails());

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertSame(Site::ONBOARDING_READY_FOR_REVIEW, $site->onboarding_status);
        $this->assertTrue((bool) $site->active);
    }

    public function test_admin_still_cannot_activate_incomplete_awaiting_details_site(): void
    {
        $site = $this->makeAwaitingDetailsSite([
            'description' => 'Please replace this placeholder with a real site description (at least 50 characters) before submitting for review.',
            'categories' => null,
            'category' => 'Pending',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse((bool) $site->fresh()->active);
        $this->assertSame(Site::ONBOARDING_AWAITING_DETAILS, $site->fresh()->onboarding_status);
    }
}
