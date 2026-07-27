<?php

namespace Tests\Feature;

use App\Mail\WelcomeEmail;
use App\Models\Role;
use App\Models\User;
use App\Notifications\VerifyEmail;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'app.public_url' => 'https://seolinkbuildings.com',
        ]);
        URL::forceRootUrl('http://127.0.0.1:8000');
    }

    public function test_signed_verification_link_uses_public_host_when_app_url_is_loopback(): void
    {
        $user = User::factory()->create([
            'email' => 'public-host@example.com',
            'email_verified_at' => null,
        ]);

        $url = VerifyEmail::signedUrlFor($user);

        $this->assertSame('seolinkbuildings.com', parse_url($url, PHP_URL_HOST));
        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
        $this->assertStringContainsString('/email/verify/'.$user->id.'/', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_signed_verification_link_keeps_configured_public_app_url(): void
    {
        config(['app.url' => 'https://staging.example.com']);
        URL::forceRootUrl('https://staging.example.com');

        $user = User::factory()->create([
            'email' => 'staging-host@example.com',
            'email_verified_at' => null,
        ]);

        $url = VerifyEmail::signedUrlFor($user);

        $this->assertSame('staging.example.com', parse_url($url, PHP_URL_HOST));
        $this->assertStringContainsString('/email/verify/'.$user->id.'/', $url);
    }

    public function test_signed_verification_link_logs_in_and_redirects_advertiser_to_catalog(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'verify-me@example.com',
            'email_verified_at' => null,
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $url = VerifyEmail::signedUrlFor($user);

        $this->assertStringContainsString('/email/verify/'.$user->id.'/', $url);
        $this->assertStringContainsString('signature=', $url);

        $this->get($url)
            ->assertRedirect('/advertiser/catalog')
            ->assertSessionHas('message');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_signed_verification_link_logs_in_and_redirects_publisher_to_dashboard(): void
    {
        $role = Role::where('name', 'publisher')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'verify-pub@example.com',
            'email_verified_at' => null,
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $url = VerifyEmail::signedUrlFor($user);

        $this->get($url)
            ->assertRedirect('/publisher/dashboard')
            ->assertSessionHas('message');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_unsigned_verification_link_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'unsigned@example.com',
            'email_verified_at' => null,
        ]);

        $hash = sha1($user->email);
        $this->get('/email/verify/'.$user->id.'/'.$hash)
            ->assertRedirect('/login')
            ->assertSessionHas('error');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_welcome_email_cta_uses_signed_verify_url_not_notice_page(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'welcome-verify@example.com',
            'email_verified_at' => null,
        ]);

        $mailable = new WelcomeEmail($user);
        $built = $mailable->build();

        $ctaUrl = $built->viewData['ctaUrl'] ?? null;
        $this->assertIsString($ctaUrl);
        $this->assertStringContainsString('/email/verify/'.$user->id.'/', $ctaUrl);
        $this->assertStringNotContainsString('/email/verify?', $ctaUrl);
        $this->assertSame('Click to verify', $built->viewData['ctaLabel'] ?? null);
        $this->assertStringContainsString('signature=', $ctaUrl);
        $this->assertSame('seolinkbuildings.com', parse_url($ctaUrl, PHP_URL_HOST));
    }

    public function test_verify_email_notification_action_uses_signed_url(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'notify-verify@example.com',
            'email_verified_at' => null,
        ]);

        $user->notify(new VerifyEmail);

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
            $mail = $notification->toMail($user);
            $actionUrl = $mail->actionUrl ?? null;
            $this->assertIsString($actionUrl);
            $this->assertStringContainsString('/email/verify/'.$user->id.'/', $actionUrl);
            $this->assertStringContainsString('signature=', $actionUrl);
            $this->assertSame('seolinkbuildings.com', parse_url($actionUrl, PHP_URL_HOST));

            return true;
        });
    }
}
