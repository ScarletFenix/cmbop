<?php

namespace Tests\Feature;

use App\Mail\AdminAssignedSiteNotification;
use App\Models\Category;
use App\Models\Country;
use App\Models\InAppNotification;
use App\Models\Language;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            'written_request' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $site = Site::where('domain', 'staff-added-news.example')->first();
        $this->assertNotNull($site);
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('publisher='.$this->publisher->id, $location);
        $this->assertStringContainsString('site='.$site->id, $location);
        $this->assertSame((int) $this->publisher->id, (int) $site->publisher_id);
        $this->assertSame((int) $this->admin->id, (int) $site->assigned_by_user_id);
        $this->assertNull($site->publisher_accepted_at);
        $this->assertSame(40, (int) $site->da);
        $this->assertSame(45, (int) $site->dr);
        $this->assertSame(12000, (int) $site->traffic);
        $this->assertFalse((bool) $site->active);
        $this->assertFalse((bool) $site->verified);
        $this->assertTrue($site->isPendingPublisherAcceptance());
        $this->assertFalse($site->needsAdminReview());
        $this->assertStringContainsString('Invites', (string) session('success'));

        Mail::assertQueued(AdminAssignedSiteNotification::class, function ($mail) {
            if (! $mail->hasTo($this->publisher->email)) {
                return false;
            }
            $mail->build();

            $html = $mail->render();

            return str_contains((string) ($mail->viewData['acceptUrl'] ?? ''), 'status=invites')
                && str_contains($html, 'Catalog Activate is not automatic')
                && ! str_contains($html, 'Our team can activate it for the catalog when ready');
        });

        $bell = InAppNotification::query()
            ->where('user_id', $this->publisher->id)
            ->where('title', 'Please accept a website we added for you')
            ->first();
        $this->assertNotNull($bell);
        $this->assertStringContainsString('status=invites', (string) $bell->action_url);
        $this->assertStringContainsString('staff review', (string) $bell->message);
        $this->assertStringNotContainsString('You can still verify ownership with the TXT file', (string) $bell->message);

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
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'site.assignment_accepted',
            'subject_type' => Site::class,
            'subject_id' => $site->id,
        ]);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('invite-site.example', false);

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'invites']))
            ->assertOk()
            ->assertDontSee('invite-site.example', false);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.verify', $site->id), ['verified' => 1])
            ->assertOk();

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

    public function test_publisher_reject_does_not_delete_invite_with_order_items(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => null,
            'site_name' => 'Ordered Invite',
            'site_url' => 'https://ordered-invite.example',
            'domain' => 'ordered-invite.example',
            'example_url' => 'https://ordered-invite.example/post',
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
            'description' => str_repeat('Invite that already has an order item. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-INVITE-ORD',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/a',
            'price' => 40,
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.reject-assignment', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        $this->assertDatabaseHas('order_items', ['site_id' => $site->id]);
    }

    public function test_create_page_survives_array_old_language_and_prices(): void
    {
        $this->actingAs($this->admin)
            ->withSession([
                '_old_input' => [
                    'language' => ['de'],
                    'country' => ['de'],
                    'price_homepage' => ['7' => ['25']],
                    'price_sensitive' => ['crypto' => ['15']],
                    'categories' => 1,
                ],
            ])
            ->get(route('admin.sites.create'))
            ->assertOk()
            ->assertDontSee('htmlspecialchars(): Argument #1', false)
            ->assertDontSee('TypeError', false);
    }

    public function test_admin_store_does_not_500_on_integer_categories(): void
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();

        $this->actingAs($this->admin)
            ->from(route('admin.sites.create'))
            ->post(route('admin.sites.store'), [
                'publisher_id' => $this->publisher->id,
                'site_name' => 'Integer Niche',
                'site_url' => 'https://int-niche.example',
                'example_url' => 'https://int-niche.example/sample',
                'da' => 40,
                'dr' => 45,
                'traffic' => 12000,
                'country' => strtolower((string) $country->code),
                'language' => strtolower((string) $language->code),
                'categories' => 1,
                'price' => 90,
                'turnaround_time' => '3days',
                'publication_time' => 'permanent',
                'link_type' => 'dofollow',
                'description' => str_repeat('Integer niche leftover store guard. ', 4),
                'site_tag' => 'as_you_prefer',
                'written_request' => 1,
            ])
            ->assertRedirect(route('admin.sites.create'))
            ->assertSessionHasErrors('categories');

        $this->assertNull(Site::where('domain', 'int-niche.example')->first());
    }

    public function test_create_page_array_publisher_id_does_not_select_user_one(): void
    {
        $html = $this->actingAs($this->admin)
            ->withSession(['_old_input' => ['publisher_id' => ['not-an-id']]])
            ->get(route('admin.sites.create'))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/<option value="\d+"[^>]*\bselected\b/', $html);

        $selected = $this->actingAs($this->admin)
            ->withSession(['_old_input' => ['publisher_id' => [(string) $this->publisher->id]]])
            ->get(route('admin.sites.create'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="'.$this->publisher->id.'"[^>]*\bselected\b/',
            $selected
        );
    }

    public function test_create_page_array_publisher_query_does_not_select_user_one(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.create', ['publisher' => ['not-an-id']]))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/<option value="\d+"[^>]*\bselected\b/', $html);

        $selected = $this->actingAs($this->admin)
            ->get(route('admin.sites.create', ['publisher' => [(string) $this->publisher->id]]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="'.$this->publisher->id.'"[^>]*\bselected\b/',
            $selected
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<option value="'.$this->admin->id.'"[^>]*\bselected\b/',
            $selected
        );
    }

    public function test_admin_store_flattens_array_site_url_instead_of_https_array(): void
    {
        Mail::fake();

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $this->actingAs($this->admin)
            ->post(route('admin.sites.store'), [
                'publisher_id' => $this->publisher->id,
                'site_name' => 'Array URL Site',
                'site_url' => ['https://array-url.example'],
                'example_url' => ['https://array-url.example/sample'],
                'da' => 40,
                'dr' => 45,
                'traffic' => 12000,
                'country' => strtolower((string) $country->code),
                'language' => strtolower((string) $language->code),
                'categories' => $niche,
                'price' => 90,
                'turnaround_time' => '3days',
                'publication_time' => 'permanent',
                'link_type' => 'dofollow',
                'description' => str_repeat('Array URL leftover store guard. ', 4),
                'site_tag' => 'as_you_prefer',
                'written_request' => 1,
            ])
            ->assertRedirect();

        $this->assertNull(Site::where('domain', 'array')->first());
        $site = Site::where('domain', 'array-url.example')->first();
        $this->assertNotNull($site);
        $this->assertSame('https://array-url.example', $site->site_url);
    }

    public function test_admin_store_rejects_price_that_would_overflow_decimal(): void
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $this->actingAs($this->admin)
            ->from(route('admin.sites.create'))
            ->post(route('admin.sites.store'), [
                'publisher_id' => $this->publisher->id,
                'site_name' => 'Overflow Price',
                'site_url' => 'https://overflow-price.example',
                'example_url' => 'https://overflow-price.example/sample',
                'da' => 40,
                'dr' => 45,
                'traffic' => 12000,
                'country' => strtolower((string) $country->code),
                'language' => strtolower((string) $language->code),
                'categories' => $niche,
                'price' => '100000000000',
                'turnaround_time' => '3days',
                'publication_time' => 'permanent',
                'link_type' => 'dofollow',
                'description' => str_repeat('Overflow price leftover store guard. ', 4),
                'site_tag' => 'as_you_prefer',
                'written_request' => 1,
            ])
            ->assertRedirect(route('admin.sites.create'))
            ->assertSessionHasErrors('price')
            ->assertSessionDoesntHaveErrors('site_url');

        $this->assertNull(Site::where('domain', 'overflow-price.example')->first());
    }

    public function test_admin_update_ignores_array_domain_field(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Keep Domain',
            'site_url' => 'https://keep-domain.example',
            'domain' => 'keep-domain.example',
            'da' => 40,
            'dr' => 42,
            'traffic' => 15000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Keep domain leftover update guard. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => 'Keep Domain',
                'domain' => ['spoofed.example'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('keep-domain.example', $site->fresh()->domain);
    }

    public function test_admin_update_ignores_array_category_field(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Keep Category',
            'site_url' => 'https://keep-category.example',
            'domain' => 'keep-category.example',
            'da' => 40,
            'dr' => 42,
            'traffic' => 15000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Keep category leftover update guard. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => 'Keep Category',
                'category' => ['spoofed', 'niches'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('News', $site->fresh()->category);
    }

    public function test_admin_update_fits_category_that_would_overflow_varchar(): void
    {
        Cache::put('sites_category_column_max_length', 50, 60);

        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Fit Category',
            'site_url' => 'https://fit-category.example',
            'domain' => 'fit-category.example',
            'da' => 40,
            'dr' => 42,
            'traffic' => 15000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Fit category leftover update guard. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $tooLong = str_repeat('OverflowNicheName', 8);
        $this->assertGreaterThan(50, strlen($tooLong));

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => 'Fit Category',
                'category' => $tooLong,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $saved = (string) $site->fresh()->category;
        $this->assertSame(substr($tooLong, 0, 50), $saved);
        $this->assertLessThanOrEqual(50, strlen($saved));

        Site::flushSchemaColumnCache();
    }

    public function test_admin_edit_survives_array_old_language(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Edit Old Language',
            'site_url' => 'https://edit-old-language.example',
            'domain' => 'edit-old-language.example',
            'da' => 40,
            'dr' => 42,
            'traffic' => 15000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Edit old language leftover guard. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $this->actingAs($this->admin)
            ->withSession([
                '_old_input' => [
                    'language' => ['de'],
                    'country' => ['de'],
                    'site_name' => ['Edit Old Language'],
                ],
            ])
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertDontSee('htmlspecialchars(): Argument #1', false)
            ->assertDontSee('TypeError', false)
            ->assertSee('const preferredLang = "de"', false);
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

    public function test_publisher_store_rejects_traffic_that_would_overflow_unsigned_int(): void
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.sites.store'), [
                'siteName' => 'Overflow Traffic',
                'siteUrl' => 'overflow-traffic.example',
                'exampleUrl' => 'https://overflow-traffic.example/post',
                'da' => 33,
                'dr' => 34,
                'traffic' => '5000000000',
                'country' => strtolower((string) $country->code),
                'language' => strtolower((string) $language->code),
                'categories' => $niche,
                'price' => 70,
                'turnaround_time' => '3days',
                'publicationTime' => 'permanent',
                'link_type' => 'dofollow',
                'siteDescription' => str_repeat('Overflow traffic leftover store guard. ', 4),
                'site_tag' => 'as_you_prefer',
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHasErrors('traffic')
            ->assertSessionDoesntHaveErrors('siteUrl');

        $this->assertNull(Site::where('domain', 'overflow-traffic.example')->first());
    }

    public function test_admin_create_coerces_da_dr_traffic_from_noisy_input(): void
    {
        Mail::fake();

        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $niche = Category::query()->orderBy('name')->value('name');
        $this->assertNotEmpty($niche);

        $this->actingAs($this->admin)->post(route('admin.sites.store'), [
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Metrics Coerce News',
            'site_url' => 'https://metrics-coerce.example',
            'example_url' => 'https://metrics-coerce.example/sample',
            'da' => ' 52 ',
            'dr' => '48.0',
            'traffic' => '15,000',
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => $niche,
            'price' => 90,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Metrics coerce site description text. ', 4),
            'site_tag' => 'as_you_prefer',
            'written_request' => 1,
        ])->assertRedirect();

        $site = Site::where('domain', 'metrics-coerce.example')->first();
        $this->assertNotNull($site);
        $this->assertSame(52, (int) $site->da);
        $this->assertSame(48, (int) $site->dr);
        $this->assertSame(15000, (int) $site->traffic);
        $this->assertTrue($site->isPendingPublisherAcceptance());
    }

    public function test_heal_migration_reopens_staff_invites_wiped_by_backfill(): void
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Wiped Invite',
            'site_url' => 'https://wiped-invite.example',
            'domain' => 'wiped-invite.example',
            'example_url' => 'https://wiped-invite.example/post',
            'da' => 25,
            'dr' => 25,
            'traffic' => 2000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Wiped invite site description text. ', 3),
            'verified' => false,
            'active' => false,
        ]);

        // Simulate the old migration backfill that stamped acceptance = created_at.
        DB::table('sites')->where('id', $site->id)->update([
            'assigned_by_user_id' => $this->admin->id,
            'publisher_accepted_at' => DB::raw('created_at'),
        ]);

        $site->refresh();
        $this->assertFalse($site->isPendingPublisherAcceptance());

        $migration = require database_path('migrations/2026_08_08_111500_heal_staff_assigned_site_invites.php');
        $migration->up();

        $site->refresh();
        $this->assertNull($site->publisher_accepted_at);
        $this->assertSame((int) $this->admin->id, (int) $site->assigned_by_user_id);
        $this->assertTrue($site->isPendingPublisherAcceptance());
    }

    public function test_invites_ajax_empty_state_mentions_accept(): void
    {
        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'invites']))
            ->assertOk()
            ->assertSee('No site invites waiting', false)
            ->assertSee('Accept / Decline', false);
    }

    public function test_admin_create_page_uses_verify_first_copy_and_posts_language(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.create'))
            ->assertOk()
            ->assertSee('Add site for publisher', false)
            ->assertSee('catalog Activate is not automatic', false)
            ->assertSee('Accept ≠ Verified', false)
            ->assertDontSee('Activate / Deactivate as usual', false)
            ->assertSee('id="selectedLanguage"', false)
            ->assertSee('name="language"', false)
            ->assertSee('Select a language', false)
            ->assertSee('data-max-kb', false)
            ->assertSee('Site image must be under', false)
            ->assertSee('id="publisherFilter"', false)
            ->assertSee('written_request', false)
            ->assertSee('This emails and bells the publisher', false)
            ->getContent();

        $this->assertStringNotContainsString('required disabled', $html);
        $this->assertMatchesRegularExpression('/<select[^>]+id="language"[^>]*required/', $html);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]+id="language"[^>]*disabled/', $html);
    }
}
