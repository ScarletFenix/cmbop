<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogSearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogSearchWordAndTest extends TestCase
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
            'description' => 'Word-AND search fixture.',
            'verified' => true, 'active' => true,
        ], $attrs));
    }

    public function test_tokens_split_on_whitespace_and_ignore_order(): void
    {
        $search = app(CatalogSearchQuery::class);

        $this->assertSame(['Northern', 'Marketing'], $search->tokens('Northern Marketing'));
        $this->assertSame(['Marketing', 'Northern'], $search->tokens('  Marketing   Northern '));
        $this->assertSame(['blog.example'], $search->tokens('blog.example'));
    }

    public function test_multi_word_search_is_order_independent_word_and(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $this->site($publisher, [
            'site_name' => 'Marketing Weekly Northern',
            'category' => 'news',
            'categories' => ['news'],
        ]);
        $this->site($publisher, [
            'site_name' => 'Southern Finance Digest',
            'category' => 'marketing',
            'categories' => ['marketing'],
        ]);

        // Contiguous phrase would miss "Marketing Weekly Northern".
        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Northern Marketing']))
            ->assertOk()
            ->assertSee('Marketing Weekly Northern')
            ->assertDontSee('Southern Finance Digest');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Marketing Northern']))
            ->assertOk()
            ->assertSee('Marketing Weekly Northern');
    }

    public function test_words_can_span_name_and_category(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $this->site($publisher, [
            'site_name' => 'Northern Lights Blog',
            'category' => 'marketing',
            'categories' => ['marketing'],
        ]);
        $this->site($publisher, [
            'site_name' => 'Northern Lights Blog Alone',
            'category' => 'travel',
            'categories' => ['travel'],
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Northern Marketing']))
            ->assertOk()
            ->assertSee('Northern Lights Blog')
            ->assertDontSee('Northern Lights Blog Alone');
    }

    public function test_partial_niche_matches_json_category_without_mid_token_hits(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $this->site($publisher, [
            'site_name' => 'Web3 Outlook',
            'category' => 'other',
            'categories' => ['Crypto & Web3'],
        ]);
        $this->site($publisher, [
            'site_name' => 'Quiet Name Weekly',
            'category' => 'marketing',
            'categories' => ['marketing'],
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Crypto']))
            ->assertOk()
            ->assertSee('Web3 Outlook')
            ->assertDontSee('Quiet Name Weekly');

        // Old unanchored %ket"% matched inside "marketing".
        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'ket']))
            ->assertOk()
            ->assertDontSee('Quiet Name Weekly')
            ->assertDontSee('Web3 Outlook');
    }

    public function test_multi_word_niche_tokens_and_across_category_json(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $this->site($publisher, [
            'site_name' => 'Ledger Daily',
            'category' => 'other',
            'categories' => ['Crypto & Web3'],
        ]);
        $this->site($publisher, [
            'site_name' => 'Crypto News Only',
            'category' => 'news',
            'categories' => ['Crypto News'],
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Crypto Web3']))
            ->assertOk()
            ->assertSee('Ledger Daily')
            ->assertDontSee('Crypto News Only');
    }
}
