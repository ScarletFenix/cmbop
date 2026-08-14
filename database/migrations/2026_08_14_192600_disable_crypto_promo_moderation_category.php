<?php

use App\Models\ContentModerationSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Crypto is an accepted marketplace topic, so stop scanning crypto_promo.
 *
 * The earlier rollout migration pruned crypto_promo from disabled_categories
 * so the scanner would start checking it. Live installs that saved the
 * settings form also have it in enabled_categories, which beats the config
 * file. This puts the stored lists back in line with the new default.
 */
return new class extends Migration
{
    private const CATEGORY = 'crypto_promo';

    public function up(): void
    {
        if (! Schema::hasTable('content_moderation_settings')) {
            return;
        }

        $disabled = ContentModerationSetting::getValue('disabled_categories', []) ?: [];
        $disabled = is_array($disabled) ? $disabled : [];
        if (! in_array(self::CATEGORY, $disabled, true)) {
            $disabled[] = self::CATEGORY;
            ContentModerationSetting::setValue('disabled_categories', array_values($disabled));
        }

        $enabled = ContentModerationSetting::getValue('enabled_categories', []) ?: [];
        $enabled = is_array($enabled) ? $enabled : [];
        $remaining = array_values(array_diff($enabled, [self::CATEGORY]));
        if ($remaining !== $enabled) {
            ContentModerationSetting::setValue('enabled_categories', $remaining);
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
            array_values(array_diff($disabled, [self::CATEGORY]))
        );

        $enabled = ContentModerationSetting::getValue('enabled_categories', []) ?: [];
        $enabled = is_array($enabled) ? $enabled : [];
        if (! in_array(self::CATEGORY, $enabled, true)) {
            $enabled[] = self::CATEGORY;
            ContentModerationSetting::setValue('enabled_categories', array_values($enabled));
        }

        ContentModerationSetting::clearCache();
    }
};
