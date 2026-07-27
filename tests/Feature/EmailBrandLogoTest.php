<?php

namespace Tests\Feature;

use App\Mail\WelcomeEmail;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EmailBrandLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    public function test_email_logo_asset_exists(): void
    {
        $this->assertFileExists(public_path('assets/img/email-logo.png'));
        $this->assertGreaterThan(10_000, filesize(public_path('assets/img/email-logo.png')));
    }

    public function test_mail_brand_logo_url_uses_email_logo_with_cache_buster(): void
    {
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'email_notifications.brand.logo_url' => null,
            'email_notifications.brand.logo_path' => 'assets/img/email-logo.png',
        ]);

        $url = mail_brand_logo_url();

        $this->assertStringContainsString('assets/img/email-logo.png', $url);
        $this->assertStringContainsString('?v=', $url);
        $this->assertStringNotContainsString('logo1.png', $url);
        $this->assertStringNotContainsString('127.0.0.1', $url);
    }

    public function test_mail_brand_logo_url_migrates_stale_logo1_override(): void
    {
        config([
            'app.url' => 'https://seolinkbuildings.com',
            'email_notifications.brand.logo_url' => 'https://seolinkbuildings.com/assets/img/logo1.png',
            'email_notifications.brand.logo_path' => 'assets/img/email-logo.png',
        ]);

        $url = mail_brand_logo_url();

        $this->assertStringContainsString('email-logo.png', $url);
        $this->assertStringNotContainsString('logo1.png', $url);
    }

    public function test_welcome_email_html_embeds_current_email_logo(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        config([
            'email_notifications.brand.logo_url' => null,
            'email_notifications.brand.logo_path' => 'assets/img/email-logo.png',
        ]);

        $html = (new WelcomeEmail($user))->render();

        $this->assertStringContainsString('email-logo.png', $html);
        $this->assertStringNotContainsString('assets/img/logo1.png', $html);
        $this->assertStringNotContainsString('laravel.com/img/notification-logo', $html);
        $this->assertStringNotContainsString('width: 75px', $html);

        File::ensureDirectoryExists(storage_path('app/testing'));
        File::put(storage_path('app/testing/welcome-email-logo.html'), $html);
    }
}
