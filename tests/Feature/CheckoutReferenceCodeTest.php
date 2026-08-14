<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CheckoutReferenceCodeTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    public function test_checkout_blade_does_not_use_mt_rand_placeholders(): void
    {
        $blade = file_get_contents(resource_path('views/advertiser/checkout.blade.php'));

        $this->assertIsString($blade);
        $this->assertStringNotContainsString('mt_rand(', $blade);
        $this->assertStringNotContainsString('Math.random()', $blade);
        $this->assertStringContainsString('checkoutReferenceCode', $blade);
    }

    public function test_checkout_page_shows_one_stable_server_reference(): void
    {
        config(['content_moderation.enabled' => false]);

        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $advertiser->roles()->attach($role->id);

        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Ref Site',
            'site_url' => 'https://ref-site.example',
            'domain' => 'ref-site.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test',
            'verified' => true,
            'active' => true,
        ]);
        $sub = $this->createApprovedSubmission($advertiser, null);

        $first = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                    'language' => 'en',
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk();

        $html = $first->getContent();
        $this->assertMatchesRegularExpression('/id="referenceCode"[^>]*>\s*(\d{6})\s*</', $html);
        preg_match('/id="referenceCode"[^>]*>\s*(\d{6})\s*</', $html, $match);
        $ref = $match[1];
        $this->assertStringContainsString('REF'.$ref, $html);

        $reload = $this->actingAs($advertiser)
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="referenceCode"', $reload);
        $this->assertStringContainsString($ref, $reload);
        $this->assertStringContainsString('REF'.$ref, $reload);
    }
}
