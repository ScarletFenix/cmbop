<?php

use App\Models\ContentModerationSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Let the newly enabled restricted categories actually take effect.
 *
 * CBD, alcohol, tobacco, weapons and crypto promotion shipped with
 * `enabled => false`, so their checkboxes on the admin moderation screen always
 * rendered unticked. Saving that form stores every unticked category in
 * `disabled_categories`, and that stored list overrides the config file — so on
 * any install where the form had ever been saved, turning them on in config
 * would have changed nothing.
 *
 * Their presence in that list is an artifact of a diff against a default nobody
 * could see, not a decision an admin made, so it is pruned. Unticking them on
 * the settings screen still works afterwards: saving rewrites both lists whole.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $newlyEnabled = ['cbd', 'alcohol', 'tobacco', 'weapons', 'crypto_promo'];

    public function up(): void
    {
        if (! Schema::hasTable('content_moderation_settings')) {
            return;
        }

        $disabled = ContentModerationSetting::getValue('disabled_categories', []) ?: [];

        if (! is_array($disabled) || $disabled === []) {
            return;
        }

        $remaining = array_values(array_diff($disabled, $this->newlyEnabled));

        if ($remaining !== $disabled) {
            ContentModerationSetting::setValue('disabled_categories', $remaining);
        }

        ContentModerationSetting::clearCache();
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_moderation_settings')) {
            return;
        }

        $disabled = ContentModerationSetting::getValue('disabled_categories', []) ?: [];
        $disabled = is_array($disabled) ? $disabled : [];

        ContentModerationSetting::setValue(
            'disabled_categories',
            array_values(array_unique(array_merge($disabled, $this->newlyEnabled)))
        );
        ContentModerationSetting::clearCache();
    }
};
