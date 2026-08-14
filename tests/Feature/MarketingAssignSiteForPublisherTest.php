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

class MarketingAssignSiteForPublisherTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->where('name', 'News')->value('name')
            ?? Category::query()->orderBy('name')->value('name');

        return array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Marketing Added News',
            'site_url' => 'https://marketing-added-news.example',
            'example_url' => 'https://marketing-added-news.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => strtolower((string) $country->code),
            'language' => strtolower((string) $language->code),
            'categories' => $niche,
            'price' => 99,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Quality editorial site for guest posts. ', 4),
            'site_tag' => 'as_you_prefer',
            'written_request' => 1,
        ], $overrides);
    }

    public function test_marketing_create_page_uses_verify_first_copy_and_quality_bar(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create'))
            ->assertOk()
            ->assertSee('Add site for publisher', false)
            ->assertSee(__('messages.staff_handbook_title'), false)
            ->assertSee('admin verifies first', false)
            ->assertSee('You Activate only after that', false)
            ->assertSee('Marketing Activate needs DA ≥ '.Site::GOOD_MIN_DA, false)
            ->assertSee('id="qualityBarWarn"', false)
            ->assertDontSee('Activate / Deactivate as usual', false)
            ->assertSee('id="selectedLanguage"', false)
            ->assertSee('id="publisherFilter"', false)
            ->assertSee('written_request', false)
            ->assertSee('I have a written request', false)
            ->assertSee('This emails and bells the publisher', false)
            ->getContent();

        $this->assertStringContainsString('data-min-da="'.Site::GOOD_MIN_DA.'"', $html);
        $this->assertStringContainsString('data-min-traffic="'.Site::GOOD_MIN_TRAFFIC.'"', $html);
        $this->assertStringContainsString('href="'.e(route('marketing.sites.index')).'"', $html);
    }

    public function test_marketing_create_page_prefills_publisher_query(): void
    {
        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create', ['publisher' => $this->publisher->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option[^>]+value="'.$this->publisher->id.'"[^>]+selected/',
            $html
        );
        $this->assertStringContainsString('publisher='.$this->publisher->id, $html);
        $this->assertMatchesRegularExpression(
            '/class="[^"]*d-none[^"]*" id="unverifiedPublisherWarn"/',
            $html
        );
    }

    public function test_marketing_can_create_site_for_publisher_pending_acceptance(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload());

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $site = Site::where('domain', 'marketing-added-news.example')->first();
        $this->assertNotNull($site);

        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/marketing/sites', $location);
        $this->assertStringContainsString('publisher='.$this->publisher->id, $location);
        $this->assertStringContainsString('site='.$site->id, $location);
        $this->assertSame((int) $this->publisher->id, (int) $site->publisher_id);
        $this->assertSame((int) $this->marketer->id, (int) $site->assigned_by_user_id);
        $this->assertNull($site->publisher_accepted_at);
        $this->assertFalse((bool) $site->active);
        $this->assertFalse((bool) $site->verified);
        $this->assertTrue($site->isPendingPublisherAcceptance());
        $this->assertNull($site->sensitive_prices);
        $this->assertStringContainsString('Invites', (string) session('success'));
        $this->assertStringNotContainsString('below the marketing Activate bar', (string) session('success'));

        Mail::assertQueued(AdminAssignedSiteNotification::class, function ($mail) {
            return $mail->hasTo($this->publisher->email);
        });

        $bell = InAppNotification::query()
            ->where('user_id', $this->publisher->id)
            ->where('title', 'Please accept a website we added for you')
            ->first();
        $this->assertNotNull($bell);
        $this->assertStringContainsString('status=invites', (string) $bell->action_url);
    }

    public function test_marketing_store_resolves_technology_alias_to_canonical_niche(): void
    {
        Mail::fake();

        $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://tech-alias.example',
                'example_url' => 'https://tech-alias.example/sample',
                'categories' => 'Technology',
            ]))
            ->assertRedirect();

        $site = Site::where('domain', 'tech-alias.example')->first();
        $this->assertNotNull($site);
        $this->assertContains('Technology & Gadgets', $site->categories ?? []);
        $this->assertStringContainsString('Technology & Gadgets', (string) $site->category);
    }

    public function test_marketing_store_rejects_unknown_niche(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://unknown-niche.example',
                'example_url' => 'https://unknown-niche.example/sample',
                'categories' => 'Not A Real Niche',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('categories');

        $this->assertNull(Site::where('domain', 'unknown-niche.example')->first());
    }

    public function test_marketing_store_below_quality_bar_still_saves_with_flash(): void
    {
        Mail::fake();

        $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://below-bar.example',
                'example_url' => 'https://below-bar.example/sample',
                'da' => 5,
                'dr' => 8,
                'traffic' => 100,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $site = Site::where('domain', 'below-bar.example')->first();
        $this->assertNotNull($site);
        $this->assertFalse($site->hasGoodMetrics());
        $this->assertTrue($site->isPendingPublisherAcceptance());
        $this->assertStringContainsString('below the marketing Activate bar', (string) session('success'));
    }

    public function test_marketing_store_blocks_when_marketplace_languages_are_empty(): void
    {
        $payload = $this->validPayload([
            'site_url' => 'https://empty-geo.example',
            'example_url' => 'https://empty-geo.example/sample',
        ]);
        config(['markets.allowed_language_codes' => []]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $payload)
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('country');

        $this->assertNull(Site::where('domain', 'empty-geo.example')->first());
        $this->assertStringContainsString(
            'not configured',
            (string) session('errors')->first('country')
        );
    }

    public function test_marketing_create_hides_unverified_publishers_unless_prefilled(): void
    {
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $unverified = User::factory()->create([
            'name' => 'Unverified Pub',
            'email' => 'unverified-pub@example.com',
            'email_verified_at' => null,
            'active_role_id' => $publisherRole->id,
        ]);
        $unverified->roles()->attach($publisherRole->id);

        $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create'))
            ->assertOk()
            ->assertDontSee('unverified-pub@example.com', false);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create', ['publisher' => $unverified->id]))
            ->assertOk()
            ->assertSee('unverified-pub@example.com', false)
            ->assertSee('cannot log in to Accept', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option[^>]+value="'.$unverified->id.'"[^>]+selected/',
            $html
        );
        $this->assertStringContainsString('publisher='.$unverified->id, $html);
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*d-none[^"]*" id="unverifiedPublisherWarn"/',
            $html
        );
    }

    public function test_validation_error_keeps_posted_publisher_on_back_and_cancel(): void
    {
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $other = User::factory()->create([
            'name' => 'Other Verified Pub',
            'email' => 'other-verified-pub@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $other->roles()->attach($publisherRole->id);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create', ['publisher' => $this->publisher->id]))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'publisher_id' => $other->id,
                'categories' => 'Not A Real Niche',
            ]))
            ->assertRedirect(route('marketing.sites.create', ['publisher' => $this->publisher->id]))
            ->assertSessionHasErrors('categories');

        $create = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.create', ['publisher' => $this->publisher->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option[^>]+value="'.$other->id.'"[^>]+selected/',
            $create
        );
        $this->assertStringContainsString('publisher='.$other->id, $create);
    }

    public function test_marketing_store_requires_written_request(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'written_request' => 0,
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('written_request');

        $this->assertNull(Site::where('domain', 'marketing-added-news.example')->first());
    }
}
