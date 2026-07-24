<?php

namespace Tests\Feature;

use App\Jobs\EnrichSiteJob;
use App\Mail\SiteStatusNotification;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublisherSiteFileVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $role = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->publisher->roles()->attach($role->id);
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Verify Demo',
            'site_url' => 'https://verify-demo.example',
            'domain' => 'verify-demo.example',
            'da' => 30,
            'dr' => 40,
            'traffic' => 5000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 50,
            'publication_time' => 'permanent',
            'description' => 'A site awaiting file verification.',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    public function test_start_generates_token_and_instructions(): void
    {
        $site = $this->makeSite();

        $res = $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.start', $site->id));

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('verified', false)
            ->assertJsonPath('file_name', 'seolinkbuildings-verify.txt')
            ->assertJsonPath('file_url', 'https://verify-demo.example/seolinkbuildings-verify.txt');

        $this->assertNotEmpty($res->json('token'));
        $this->assertStringStartsWith('slb-verify-', $res->json('token'));

        $site->refresh();
        $this->assertSame($res->json('token'), $site->verify_token);
        $this->assertNotNull($site->verify_token_created_at);
    }

    public function test_check_verifies_when_file_matches(): void
    {
        Mail::fake();
        Queue::fake();

        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            'https://verify-demo.example/seolinkbuildings-verify.txt' => Http::response('slb-verify-abcdefghijklmnopqrstuvwx', 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $res = $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id));

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('verified', true);

        $site->refresh();
        $this->assertTrue((bool) $site->verified);
        $this->assertSame('file', $site->verify_method);
        $this->assertNotNull($site->verified_at);
        $this->assertNull($site->verify_token);

        Mail::assertQueued(SiteStatusNotification::class);
        Queue::assertPushed(EnrichSiteJob::class);
    }

    public function test_check_fails_when_file_missing(): void
    {
        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            '*' => Http::response('Not Found', 404),
        ]);

        $res = $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id));

        $res->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('verified', false);

        $this->assertFalse((bool) $site->fresh()->verified);
    }

    public function test_check_fails_when_token_mismatches(): void
    {
        $site = $this->makeSite([
            'verify_token' => 'slb-verify-abcdefghijklmnopqrstuvwx',
            'verify_token_created_at' => now(),
        ]);

        Http::fake([
            '*' => Http::response('slb-verify-wrong-token-value-here', 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $res = $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id));

        $res->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('verified', false);

        $this->assertFalse((bool) $site->fresh()->verified);
    }

    public function test_already_verified_site_returns_success_without_fetch(): void
    {
        $site = $this->makeSite([
            'verified' => true,
            'verified_at' => now(),
            'verify_method' => 'manual',
        ]);

        Http::fake();

        $res = $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.check', $site->id));

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('verified', true);

        Http::assertNothingSent();
    }

    public function test_incomplete_bulk_draft_cannot_start_verification(): void
    {
        $site = $this->makeSite([
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        $res = $this->actingAs($this->publisher)
            ->postJson(route('publisher.sites.verification.start', $site->id));

        $res->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertNull($site->fresh()->verify_token);
    }

    public function test_my_sites_table_shows_get_verified_for_unverified_sites(): void
    {
        $this->makeSite(['verified' => false, 'active' => false]);

        $ajax = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'pending']));

        $ajax->assertOk();
        $this->assertStringContainsString('btn-verify-site', $ajax->getContent());
        $this->assertStringContainsString('Get Verified', $ajax->getContent());
    }

    public function test_other_publishers_cannot_verify_foreign_sites(): void
    {
        $site = $this->makeSite();
        $role = Role::where('name', 'publisher')->firstOrFail();
        $other = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $other->roles()->attach($role->id);

        $this->actingAs($other)
            ->postJson(route('publisher.sites.verification.start', $site->id))
            ->assertNotFound();
    }
}
