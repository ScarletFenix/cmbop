<?php

namespace Tests\Feature;

use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moderation being off is the most dangerous state the feature has, and the one
 * that looks most like everything working: articles are approved, orders go
 * through, and the scan log fills with passes. Nothing in the product changes,
 * so every place a person might look has to say it out loud.
 */
class ModerationDisabledVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $u = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $u->roles()->attach($role->id);

        return $u->fresh();
    }

    private function disableViaDatabase(): void
    {
        // How it happens in practice: someone saves the settings screen with the
        // toggle off, and that stored value quietly beats the .env var.
        ContentModerationSetting::setValue('config_override', ['enabled' => false]);
        ContentModerationSetting::clearCache();
    }

    public function test_a_stored_override_beats_the_env_var(): void
    {
        config(['content_moderation.enabled' => true]);
        $this->disableViaDatabase();

        $this->assertFalse(
            app(ContentModerationService::class)->isEnabled(),
            'The stored override should decide this, which is why .env alone cannot be trusted.'
        );
    }

    public function test_the_dashboard_says_so(): void
    {
        $this->disableViaDatabase();

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Content moderation is switched off');
    }

    public function test_the_dashboard_stays_quiet_when_it_is_running(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Content moderation is switched off');
    }

    public function test_an_unscanned_article_is_not_recorded_as_approved(): void
    {
        $this->disableViaDatabase();

        $result = app(ContentModerationService::class)->scanExtractedContent(
            text: 'Play at the best online casino and claim your no deposit bonus for slots tonight.',
            html: '<p>Play at the best online casino.</p>',
            sourceLabel: 'upload:test',
            user: null,
            title: 'Casino guide',
            links: [],
        );

        // Checkout still proceeds — that is what disabling means — but the row
        // must not claim anything looked at the article.
        $this->assertTrue((bool) ($result['passed'] ?? false));

        $log = ContentModerationLog::latest('id')->firstOrFail();
        $this->assertTrue($log->wasSkipped(), 'The log row does not record that the scan was skipped.');
    }

    public function test_the_scan_log_shows_not_checked_rather_than_approved(): void
    {
        $this->disableViaDatabase();

        app(ContentModerationService::class)->scanExtractedContent(
            text: 'Buy firearms and ammunition for sale, shipped discreetly to your door.',
            html: '<p>Buy firearms.</p>',
            sourceLabel: 'upload:test',
            user: null,
            title: 'Weapons guide',
            links: [],
        );

        $this->actingAs($this->admin())
            ->get(route('admin.moderation.index'))
            ->assertOk()
            ->assertSee('Not checked');
    }

    public function test_a_genuinely_checked_pass_still_reads_as_approved(): void
    {
        app(ContentModerationService::class)->scanExtractedContent(
            text: 'This guide covers building a content calendar for a B2B SaaS blog, including '
                .'keyword research, internal linking and measuring organic growth over time.',
            html: '<p>Content calendar guide.</p>',
            sourceLabel: 'upload:test',
            user: null,
            title: 'Content calendar',
            links: [],
        );

        $log = ContentModerationLog::latest('id')->firstOrFail();
        $this->assertFalse($log->wasSkipped());

        $this->actingAs($this->admin())
            ->get(route('admin.moderation.index'))
            ->assertOk()
            ->assertSee('Approved');
    }

    public function test_the_status_command_names_what_switched_it_off(): void
    {
        $this->disableViaDatabase();

        $this->artisan('moderation:status')
            ->expectsOutputToContain('MODERATION IS OFF')
            ->expectsOutputToContain('wins over .env')
            ->assertFailed();
    }

    public function test_the_status_command_passes_when_everything_is_live(): void
    {
        $this->artisan('moderation:status')
            ->expectsOutputToContain('MODERATION IS ON')
            ->assertSuccessful();
    }

    public function test_the_status_command_flags_a_disabled_category(): void
    {
        ContentModerationSetting::setValue('disabled_categories', ['gambling']);
        ContentModerationSetting::clearCache();

        // Exit non-zero so a deploy check or cron notices a partial configuration.
        $this->artisan('moderation:status')
            ->expectsOutputToContain('Not scanned: gambling')
            ->assertFailed();
    }
}
