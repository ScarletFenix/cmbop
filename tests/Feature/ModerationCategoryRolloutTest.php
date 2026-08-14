<?php

namespace Tests\Feature;

use App\Models\ContentModerationSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Turning a category on in config is not enough on a live install.
 *
 * Saving the moderation settings form stores every unticked category in
 * `disabled_categories`, and that stored list overrides the config file. The
 * five categories that shipped disabled could never render ticked, so they are
 * in that list wherever the form was ever saved — and enabling them in config
 * would have changed nothing at all.
 */
class ModerationCategoryRolloutTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $newlyEnabled = ['cbd', 'alcohol', 'tobacco', 'weapons', 'crypto_promo'];

    /**
     * Run the rollout migration itself.
     *
     * RefreshDatabase has already recorded it, so `artisan migrate` would skip
     * it — and skipping is exactly the case this test needs to rule out.
     */
    private function runRollout(): void
    {
        $migration = require database_path(
            'migrations/2026_08_04_010000_release_newly_enabled_moderation_categories.php'
        );
        $migration->up();
        ContentModerationSetting::clearCache();
    }

    private function offCategories(): array
    {
        ContentModerationSetting::clearCache();

        return collect(app(ContentModerationService::class)->activeCategories())
            ->reject(fn ($cat) => (bool) ($cat['enabled'] ?? false))
            ->keys()
            ->all();
    }

    public function test_a_stored_disable_list_would_otherwise_beat_the_config(): void
    {
        // The exact state any install that saved the form is in.
        ContentModerationSetting::setValue('disabled_categories', $this->newlyEnabled);
        ContentModerationSetting::setValue('enabled_categories', ['gambling', 'adult']);

        $off = $this->offCategories();

        foreach ($this->newlyEnabled as $key) {
            $this->assertContains($key, $off, "Expected {$key} to be suppressed by the stored list.");
        }
    }

    public function test_the_migration_releases_them(): void
    {
        ContentModerationSetting::setValue('disabled_categories', $this->newlyEnabled);
        ContentModerationSetting::setValue('enabled_categories', ['gambling', 'adult']);

        $this->runRollout();

        // Crypto stays off: it is an accepted topic even after the historical rollout.
        $this->assertSame(['crypto_promo'], $this->offCategories(), 'Non-crypto categories are still suppressed after the migration.');
    }

    public function test_a_deliberate_disable_is_not_overridden(): void
    {
        // An admin unticking gambling is a real decision and must survive.
        ContentModerationSetting::setValue('disabled_categories', array_merge($this->newlyEnabled, ['gambling']));

        $this->runRollout();

        $this->assertEqualsCanonicalizing(['gambling', 'crypto_promo'], $this->offCategories());
    }

    public function test_the_admin_banner_names_categories_the_scanner_is_skipping(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $admin->roles()->attach($role->id);

        ContentModerationSetting::setValue('disabled_categories', ['gambling']);

        // The banner used to read the config file, which does not carry the
        // stored list — so it reassured the admin while casino content sailed
        // through, which is worse than showing nothing at all.
        $this->actingAs($admin->fresh())
            ->get(route('admin.moderation.index'))
            ->assertOk()
            ->assertSee('not being checked')
            ->assertSee('Casino / Poker / Gambling / Betting');
    }

    public function test_restricted_content_is_flagged_once_they_are_released(): void
    {
        ContentModerationSetting::setValue('disabled_categories', $this->newlyEnabled);

        $this->runRollout();

        $result = app(ContentModerationService::class)->scanExtractedContent(
            text: 'Buy cbd oil and hemp flower from our cannabis dispensary, thc products shipped fast.',
            html: '<p>Buy cbd oil and hemp flower from our cannabis dispensary.</p>',
            sourceLabel: 'test',
            user: null,
            title: 'CBD guide',
            links: [],
        );

        $this->assertFalse((bool) ($result['passed'] ?? true), 'CBD content still passed the scan.');
    }
}
