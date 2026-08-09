<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogSearchRelevanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $u = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $roleModel->id]);
        $u->roles()->attach($roleModel->id);

        return $u->fresh();
    }

    private function site(User $publisher, array $attrs): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Listing '.uniqid(),
            'site_url' => 'https://example-'.uniqid().'.test',
            'domain' => 'example-'.uniqid().'.test',
            'da' => 40, 'dr' => 45, 'traffic' => 12000,
            'country' => 'us', 'language' => 'en',
            'countries' => ['us'], 'languages' => ['en'],
            'category' => 'marketing', 'price' => 150,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent', 'link_type' => 'dofollow',
            'description' => 'Search relevance fixture.',
            'verified' => true, 'active' => true,
        ], $attrs));
    }

    public function test_short_query_does_not_substring_match_categories(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $this->site($publisher, [
            'site_name' => 'Quiet Name Weekly',
            'category' => 'marketing', // contains "et" / "ket"
            'categories' => ['marketing'],
            'dr' => 90,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'et']))
            ->assertOk()
            ->assertDontSee('Quiet Name Weekly');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'ket']))
            ->assertOk()
            ->assertDontSee('Quiet Name Weekly');
    }

    public function test_metric_tokens_in_search_apply_range_filters(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $high = $this->site($publisher, [
            'site_name' => 'High DA Alpha',
            'da' => 55,
            'price' => 80,
        ]);
        $low = $this->site($publisher, [
            'site_name' => 'Low DA Beta',
            'da' => 20,
            'price' => 80,
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'da>=50']))
            ->assertOk()
            ->assertSee('High DA Alpha')
            ->assertDontSee('Low DA Beta')
            ->getContent();

        // Parsed into the range inputs so Filter / chips stay honest.
        $this->assertStringContainsString('name="da_min"', $html);
        $this->assertMatchesRegularExpression('/name="da_min"[^>]*value="50"/', $html);

        unset($high, $low);
    }

    public function test_name_matches_rank_above_category_only_hits(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        // Weaker category hit with a higher DR — without relevance it would lead.
        $this->site($publisher, [
            'site_name' => 'Omega Daily Notes',
            'category' => 'Technology',
            'categories' => ['Technology'],
            'dr' => 99,
        ]);
        $this->site($publisher, [
            'site_name' => 'Tech Insider Journal',
            'category' => 'marketing',
            'categories' => ['marketing'],
            'dr' => 10,
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Tech']))
            ->assertOk()
            ->assertSee('Tech Insider Journal')
            ->assertSee('Omega Daily Notes')
            ->getContent();

        $namePos = strpos($html, 'Tech Insider Journal');
        $categoryPos = strpos($html, 'Omega Daily Notes');

        $this->assertNotFalse($namePos);
        $this->assertNotFalse($categoryPos);
        $this->assertLessThan($categoryPos, $namePos, 'Name match should appear before category-only match');
    }

    public function test_category_filter_matches_json_categories_once(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $this->site($publisher, [
            'site_name' => 'JSON Niche Site',
            'category' => 'other',
            'categories' => ['Crypto & Web3'],
        ]);
        $this->site($publisher, [
            'site_name' => 'Unrelated Niche Site',
            'category' => 'marketing',
            'categories' => ['marketing'],
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['category' => 'Crypto & Web3']))
            ->assertOk()
            ->assertSee('JSON Niche Site')
            ->assertDontSee('Unrelated Niche Site');
    }
}
