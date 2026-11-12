<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\LinkInsertionProduct;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LinkInsertionStaffSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $publisher;

    private Country $country;

    private Language $language;

    private string $niche;

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

        $this->country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $this->language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $this->niche = (string) Category::query()->orderBy('name')->value('name');
    }

    public function test_admin_create_form_includes_link_insertion_fields(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Accept link insertions', $html);
        $this->assertStringContainsString('Link insertion price (€)', $html);
        $this->assertStringContainsString('Allow homepage / high-traffic posts', $html);
        $this->assertStringContainsString('name="link_insertion_form"', $html);
    }

    public function test_admin_can_create_site_with_link_insertion_fields(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)->post(route('admin.sites.store'), $this->staffPayload([
            'site_url' => 'https://staff-li-create.example',
            'example_url' => 'https://staff-li-create.example/sample',
            'site_name' => 'Staff LI Create',
            'accepts_link_insertions' => 1,
            'link_insertion_price' => 42.5,
            'link_insertion_delivery_days' => 5,
            'link_insertion_min_article_age_days' => 30,
            'link_insertion_link_type' => 'nofollow',
            'link_insertion_allow_high_traffic' => 1,
            'link_insertion_form' => 1,
        ]))->assertRedirect()->assertSessionHas('success');

        $site = Site::where('domain', 'staff-li-create.example')->first();
        $this->assertNotNull($site);
        $this->assertTrue((bool) $site->accepts_link_insertions);
        $this->assertTrue($site->offersLinkInsertions());
        $this->assertEquals(42.5, (float) $site->listing_link_insertion_price);
        $this->assertEquals(42.5, (float) $site->link_insertion_price);
        $this->assertSame(5, (int) $site->link_insertion_delivery_days);
        $this->assertSame(30, (int) $site->link_insertion_min_article_age_days);
        $this->assertSame('nofollow', $site->link_insertion_link_type);
        $this->assertTrue((bool) $site->link_insertion_allow_high_traffic);
    }

    public function test_admin_can_edit_link_insertion_on_a_listing(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.sites.store'), $this->staffPayload([
            'site_url' => 'https://staff-li-edit.example',
            'example_url' => 'https://staff-li-edit.example/sample',
            'site_name' => 'Staff LI Edit',
        ]))->assertRedirect();

        $site = Site::where('domain', 'staff-li-edit.example')->firstOrFail();
        $this->assertFalse($site->offersLinkInsertions());

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => 'Staff LI Edit',
                'site_url' => 'https://staff-li-edit.example',
                'example_url' => 'https://staff-li-edit.example/sample',
                'da' => 40,
                'dr' => 45,
                'traffic' => 12000,
                'country' => strtolower($this->country->code),
                'language' => strtolower($this->language->code),
                'price' => 99,
                'link_type' => 'dofollow',
                'publication_time' => 'permanent',
                'link_insertion_form' => 1,
                'accepts_link_insertions' => 1,
                'link_insertion_price' => 55,
                'link_insertion_delivery_days' => 4,
                'link_insertion_link_type' => 'dofollow',
                'link_insertion_allow_high_traffic' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertTrue($site->offersLinkInsertions());
        $this->assertEquals(55.0, (float) $site->listing_link_insertion_price);
        $this->assertEquals(55.0, (float) $site->link_insertion_price);
        $this->assertSame(4, (int) $site->link_insertion_delivery_days);
        $this->assertTrue((bool) $site->link_insertion_allow_high_traffic);
    }

    public function test_admin_site_list_shows_link_insertion_column_and_filter(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.sites.store'), $this->staffPayload([
            'site_url' => 'https://staff-li-list.example',
            'example_url' => 'https://staff-li-list.example/sample',
            'site_name' => 'Staff LI List',
            'accepts_link_insertions' => 1,
            'link_insertion_price' => 33,
            'link_insertion_form' => 1,
        ]))->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.sites.store'), $this->staffPayload([
            'site_url' => 'https://staff-gp-list.example',
            'example_url' => 'https://staff-gp-list.example/sample',
            'site_name' => 'Staff GP List',
        ]))->assertRedirect();

        $index = $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['all' => 1]))
            ->assertOk()
            ->assertSee('data-staff-filter="link_insertion"', false)
            ->assertSee('Staff LI List', false)
            ->assertSee('Staff GP List', false)
            ->getContent();
        $this->assertStringContainsString('>LI</th>', $index);
        $this->assertStringContainsString('badge text-bg-success">LI', $index);
        $this->assertStringContainsString('€33.00', $index);

        $filtered = $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['all' => 1, 'link_insertion' => 1]))
            ->assertOk()
            ->assertSee('Staff LI List', false)
            ->assertDontSee('Staff GP List', false)
            ->getContent();
        $this->assertStringContainsString('Link insertions', $filtered);
    }

    public function test_csv_template_includes_optional_link_insertion_columns(): void
    {
        $csv = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.bulk-template'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('link_insertion_price', $csv);
        $this->assertStringContainsString('accepts_link_insertions', $csv);
        $this->assertStringContainsString('link_insertion_link_type', $csv);
        $this->assertStringContainsString('link_insertion_delivery_days', $csv);
    }

    public function test_agency_csv_import_accepts_link_insertion_columns(): void
    {
        $this->uploadCsv([
            $this->csvRow('li-csv.example', 'LI CSV', [
                '40', '1', 'nofollow', '5', '14', '1',
            ]),
        ])->assertRedirect();

        $site = Site::where('domain', 'li-csv.example')->first();
        $this->assertNotNull($site);
        $this->assertTrue($site->offersLinkInsertions());
        $this->assertEquals(40.0, (float) $site->listing_link_insertion_price);
        $this->assertEquals(40.0, (float) $site->link_insertion_price);
        $this->assertSame('nofollow', $site->link_insertion_link_type);
        $this->assertSame(5, (int) $site->link_insertion_delivery_days);
        $this->assertSame(14, (int) $site->link_insertion_min_article_age_days);
        $this->assertTrue((bool) $site->link_insertion_allow_high_traffic);
    }

    public function test_agency_csv_blank_link_insertion_columns_mean_not_accepting(): void
    {
        $this->uploadCsv([
            $this->csvRow('blank-li.example', 'Blank LI', ['', '', '', '', '', '']),
        ])->assertRedirect();

        $site = Site::where('domain', 'blank-li.example')->first();
        $this->assertNotNull($site);
        $this->assertFalse($site->offersLinkInsertions());
        $this->assertFalse((bool) $site->accepts_link_insertions);
        $this->assertNull($site->listing_link_insertion_price);
        $this->assertNull($site->link_insertion_price);
    }

    public function test_agency_csv_price_without_toggle_still_accepts(): void
    {
        $this->uploadCsv([
            $this->csvRow('price-only-li.example', 'Price Only LI', ['25', '', 'dofollow', '7', '', '']),
        ])->assertRedirect();

        $site = Site::where('domain', 'price-only-li.example')->first();
        $this->assertNotNull($site);
        $this->assertTrue($site->offersLinkInsertions());
        $this->assertEquals(25.0, (float) $site->link_insertion_price);
    }

    public function test_blank_csv_is_not_accepting_when_toggle_has_no_price(): void
    {
        $this->uploadCsv([
            $this->csvRow('toggle-only-li.example', 'Toggle Only LI', ['', '1', 'dofollow', '7', '', '']),
        ])->assertRedirect();

        $site = Site::where('domain', 'toggle-only-li.example')->first();
        $this->assertNotNull($site);
        $this->assertFalse($site->offersLinkInsertions());
    }

    public function test_staff_bulk_named_csv_persists_link_insertion(): void
    {
        Mail::fake();
        $code = strtolower($this->country->code);
        $lang = strtolower($this->language->code);
        $description = str_repeat('Quality editorial site for guest posts. ', 4);
        $csv = implode("\n", [
            'url,price,da,dr,traffic,country,language,site_name,example_url,turnaround,publication,link_type,tag,niches,description,link_insertion_price,accepts_link_insertions,link_insertion_link_type,link_insertion_delivery_days',
            "https://staff-bulk-li.example,80,40,35,12000,{$code},{$lang},Bulk LI,https://staff-bulk-li.example/post,3days,permanent,dofollow,,{$this->niche},\"{$description}\",48,1,dofollow,6",
        ]);

        $this->actingAs($this->admin)->post(route('admin.sites.bulk-store'), [
            'publisher_id' => $this->publisher->id,
            'rows' => $csv,
            'written_request' => 1,
        ])->assertRedirect()->assertSessionHas('success');

        $site = Site::where('domain', 'staff-bulk-li.example')->first();
        $this->assertNotNull($site);
        $this->assertTrue($site->offersLinkInsertions());
        $this->assertEquals(48.0, (float) $site->listing_link_insertion_price);
        $this->assertSame(6, (int) $site->link_insertion_delivery_days);
    }

    public function test_persistence_helper_does_not_expose_markup(): void
    {
        $payload = LinkInsertionProduct::persistenceFromInput([
            'accepts_link_insertions' => 1,
            'link_insertion_price' => 40,
        ]);
        $this->assertArrayHasKey('listing_link_insertion_price', $payload);
        $this->assertArrayHasKey('link_insertion_price', $payload);
        $this->assertSame(40.0, $payload['listing_link_insertion_price']);
        $this->assertSame(40.0, $payload['link_insertion_price']);
        $this->assertArrayNotHasKey('advertiser_price', $payload);
        $this->assertArrayNotHasKey('markup', $payload);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function staffPayload(array $overrides = []): array
    {
        return array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Staff Added News',
            'site_url' => 'https://staff-added-news.example',
            'example_url' => 'https://staff-added-news.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => strtolower($this->country->code),
            'language' => strtolower($this->language->code),
            'categories' => $this->niche,
            'price' => 99,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Quality editorial site for guest posts. ', 4),
            'written_request' => 1,
        ], $overrides);
    }

    /**
     * @param  list<string>  $li
     * @return list<string>
     */
    private function csvRow(string $domain, string $name, array $li): array
    {
        return [
            $name,
            'https://'.$domain,
            'https://'.$domain.'/sample-post',
            '45',
            '40',
            '15000',
            'de',
            'de',
            $this->niche,
            '120',
            '3days',
            'permanent',
            'dofollow',
            'High-quality editorial site covering business and technology topics for professional audiences.',
            '0',
            '0',
            '0',
            ...$li,
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function uploadCsv(array $rows)
    {
        Storage::fake('local');
        $headers = [
            'site_name', 'site_url', 'example_url', 'da', 'dr', 'traffic',
            'country', 'language', 'categories', 'price', 'turnaround_time',
            'publication_time', 'link_type', 'description',
            'sponsored', 'partner_material', 'as_you_prefer',
            ...LinkInsertionProduct::csvColumnNames(),
        ];
        $lines = [implode(',', $headers)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(function ($v) {
                $v = (string) $v;
                if (str_contains($v, ',') || str_contains($v, '"')) {
                    return '"'.str_replace('"', '""', $v).'"';
                }

                return $v;
            }, $row));
        }
        $file = UploadedFile::fake()->createWithContent('agency-sites.csv', implode("\n", $lines)."\n");

        return $this->actingAs($this->publisher)
            ->post(route('publisher.sites.bulk-import'), ['csv_file' => $file]);
    }
}
