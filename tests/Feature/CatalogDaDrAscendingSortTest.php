<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CatalogDaDrAscendingSortTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'advertiser'],
            ['guard_name' => 'web']
        );
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function publisher(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'publisher'],
            ['guard_name' => 'web']
        );
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function site(User $publisher, array $extra = []): Site
    {
        $domain = $extra['domain'] ?? ('sort-'.uniqid('', true).'.test');

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Sort Site',
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 100,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'DA/DR ascending sort fixture',
            'verified' => true,
            'active' => true,
        ], $extra));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_sort_dropdown_offers_da_and_dr_ascending(): void
    {
        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="dr_asc"', $html);
        $this->assertStringContainsString('DR (low → high)', $html);
        $this->assertStringContainsString('value="da_asc"', $html);
        $this->assertStringContainsString('DA (low → high)', $html);
    }

    public function test_dr_asc_orders_low_to_high(): void
    {
        $publisher = $this->publisher();
        $this->site($publisher, ['site_name' => 'DR High', 'dr' => 90, 'da' => 50]);
        $this->site($publisher, ['site_name' => 'DR Low', 'dr' => 20, 'da' => 50]);
        $this->site($publisher, ['site_name' => 'DR Mid', 'dr' => 55, 'da' => 50]);

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog', ['sort' => 'dr_asc']))
            ->assertOk()
            ->getContent();

        $low = strpos($html, 'DR Low');
        $mid = strpos($html, 'DR Mid');
        $high = strpos($html, 'DR High');

        $this->assertNotFalse($low);
        $this->assertNotFalse($mid);
        $this->assertNotFalse($high);
        $this->assertTrue($low < $mid && $mid < $high, 'Expected DR Low → Mid → High order');
        $this->assertMatchesRegularExpression(
            '/id="catalogSort"[^>]*>[\s\S]*value="dr_asc"[^>]*selected/',
            $html
        );
    }

    public function test_da_asc_orders_low_to_high_on_live_results(): void
    {
        $publisher = $this->publisher();
        $this->site($publisher, ['site_name' => 'DA High', 'da' => 80, 'dr' => 40]);
        $this->site($publisher, ['site_name' => 'DA Low', 'da' => 15, 'dr' => 40]);

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog.results', ['sort' => 'da_asc']))
            ->assertOk()
            ->getContent();

        $low = strpos($html, 'DA Low');
        $high = strpos($html, 'DA High');

        $this->assertNotFalse($low);
        $this->assertNotFalse($high);
        $this->assertTrue($low < $high, 'Expected DA Low before DA High');
    }
}
