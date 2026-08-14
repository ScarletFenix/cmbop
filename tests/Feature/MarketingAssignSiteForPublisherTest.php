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
            ->assertSee('Click to toggle; type to search; Enter adds the highlighted match. Max 7.', false)
            ->assertDontSee('Click niches one by one', false)
            ->assertSee('maxlength="5000"', false)
            ->assertSee('max 500 words (5000 characters)', false)
            ->assertSee('name="price_homepage[7]"', false)
            ->assertSee('name="social[facebook]"', false)
            ->assertSee('name="sensitive[crypto]"', false)
            ->assertSee('name="price_sensitive[crypto]"', false)
            ->assertSee('optional homepage, social, and sensitive-topic prices', false)
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

    public function test_marketing_store_rejects_live_duplicate_domain(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Live Duplicate',
            'site_url' => 'https://live-dup.example',
            'domain' => 'live-dup.example',
            'example_url' => 'https://live-dup.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Existing live listing description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://www.live-dup.example',
                'example_url' => 'https://www.live-dup.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertSame(
            'This website domain is already registered.',
            (string) session('errors')->first('site_url')
        );
        $this->assertSame(1, Site::where('domain', 'live-dup.example')->count());
    }

    public function test_marketing_store_rejects_archived_domain_with_restore_message(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Archived Duplicate',
            'site_url' => 'https://archived-dup.example',
            'domain' => 'archived-dup.example',
            'example_url' => 'https://archived-dup.example/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'categories' => ['News'],
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Archived listing description text here. ', 3),
            'verified' => false,
            'active' => false,
            'archived_at' => now(),
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://archived-dup.example',
                'example_url' => 'https://archived-dup.example/sample',
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('site_url');

        $this->assertSame(
            'This domain is already registered (including archived). Ask an admin to restore or hard-delete.',
            (string) session('errors')->first('site_url')
        );
        $this->assertSame(1, Site::where('domain', 'archived-dup.example')->count());
    }

    public function test_marketing_store_rejects_description_over_character_max(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://long-desc.example',
                'example_url' => 'https://long-desc.example/sample',
                'description' => str_repeat('a', 5001),
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('description');

        $this->assertNull(Site::where('domain', 'long-desc.example')->first());
    }

    public function test_marketing_store_rejects_description_over_word_max(): void
    {
        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.create'))
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://wordy-desc.example',
                'example_url' => 'https://wordy-desc.example/sample',
                'description' => implode(' ', array_fill(0, 501, 'word')),
            ]))
            ->assertRedirect(route('marketing.sites.create'))
            ->assertSessionHasErrors('description');

        $this->assertStringContainsString(
            'at most 500 words',
            (string) session('errors')->first('description')
        );
        $this->assertNull(Site::where('domain', 'wordy-desc.example')->first());
    }

    public function test_marketing_store_persists_homepage_social_and_sensitive_prices(): void
    {
        Mail::fake();

        $this->actingAs($this->marketer)
            ->post(route('marketing.sites.store'), $this->validPayload([
                'site_url' => 'https://extras-listing.example',
                'example_url' => 'https://extras-listing.example/sample',
                'homepage' => ['7' => '1'],
                'price_homepage' => ['7' => '25'],
                'social' => ['facebook' => '1', 'x' => '1'],
                'sensitive' => ['crypto' => '1'],
                'price_sensitive' => ['crypto' => '15'],
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $site = Site::where('domain', 'extras-listing.example')->first();
        $this->assertNotNull($site);
        $this->assertSame([7 => 25.0], $site->homepagePlacementOptions());
        $this->assertSame(['facebook', 'x'], $site->enabledSocialChannels());
        $this->assertEqualsWithDelta(15.0, (float) ($site->sensitive_prices['crypto'] ?? 0), 0.001);
        $this->assertTrue($site->isPendingPublisherAcceptance());
    }

    public function test_marketing_update_coerces_da_dr_traffic_from_noisy_input(): void
    {
        $niche = Category::query()->where('name', 'News')->value('name')
            ?? Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Metrics Update Site',
            'site_url' => 'https://mkt-metrics-update.example',
            'domain' => 'mkt-metrics-update.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => $niche,
            'categories' => [$niche],
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => str_repeat('Marketing metrics update listing. ', 3),
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.sites.edit', $site->id))
            ->put(route('marketing.sites.update', $site->id), [
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'da' => ' 52 ',
                'dr' => '48.0',
                'traffic' => '15,000',
                'country' => 'de',
                'language' => 'de',
                'categories' => $niche,
                'price' => 40,
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionDoesntHaveErrors();

        $site->refresh();
        $this->assertSame(52, (int) $site->da);
        $this->assertSame(48, (int) $site->dr);
        $this->assertSame(15000, (int) $site->traffic);
    }
}
