<?php

namespace App\Console\Commands;

use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Services\ContentModeration\ContentModerationService;
use Illuminate\Console\Command;

/**
 * Answer "is content moderation actually running, and why" on one line.
 *
 * Three separate things decide it — the env var, a stored database override that
 * silently beats the env var, and whether the config cache still holds a stale
 * copy — and when it is off nothing in the product looks different: articles are
 * approved, orders go through, and the scan log fills with passes. Working that
 * out by reading code on a server is the slow path.
 */
class ModerationStatus extends Command
{
    protected $signature = 'moderation:status';

    protected $description = 'Report whether content moderation is running, what decided that, and which categories are live';

    public function handle(ContentModerationService $moderation): int
    {
        $enabled = $moderation->isEnabled();

        $envRaw = env('CONTENT_MODERATION_ENABLED');
        $override = ContentModerationSetting::getValue('config_override', []);
        $storedEnabled = is_array($override) && array_key_exists('enabled', $override)
            ? (bool) $override['enabled']
            : null;

        $this->newLine();
        $this->line($enabled
            ? '<bg=green;fg=black> MODERATION IS ON </>'
            : '<bg=red;fg=white> MODERATION IS OFF — nothing is being scanned </>');
        $this->newLine();

        $this->line('<options=bold>What decided that</>');
        $this->line(sprintf(
            '  .env CONTENT_MODERATION_ENABLED   %s',
            $envRaw === null ? 'not set (defaults to on)' : var_export($envRaw, true)
        ));
        $this->line(sprintf(
            '  database override                 %s',
            $storedEnabled === null
                ? 'none'
                : ($storedEnabled ? 'true' : 'false').'  <- wins over .env'
        ));
        $this->line(sprintf(
            '  config cache                      %s',
            file_exists($this->laravel->getCachedConfigPath())
                ? 'PRESENT — .env edits do nothing until you run config:cache again'
                : 'none, .env is read live'
        ));

        if ($storedEnabled === false) {
            $this->newLine();
            $this->warn('Someone saved the admin moderation screen with the toggle off.');
            $this->line('  Turn it back on there, or clear the override:');
            $this->line('  <fg=cyan>php artisan tinker --execute="App\\Models\\ContentModerationSetting::setValue(\'config_override\', []);"</>');
        }

        $this->newLine();
        $this->line('<options=bold>Categories</>');
        $categories = $moderation->activeCategories();
        $off = [];

        foreach ($categories as $key => $cat) {
            $on = (bool) ($cat['enabled'] ?? false);
            if (! $on) {
                $off[] = $key;
            }
            $this->line(sprintf(
                '  %s %-14s %s',
                $on ? '<fg=green>on </>' : '<fg=red>off</>',
                $key,
                $cat['label'] ?? ''
            ));
        }

        if ($off !== []) {
            $this->newLine();
            $this->warn('Not scanned: '.implode(', ', $off));
        }

        $skipped = ContentModerationLog::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->get()
            ->filter(fn (ContentModerationLog $log) => $log->wasSkipped())
            ->count();

        if ($skipped > 0) {
            $this->newLine();
            $this->warn($skipped.' article(s) in the last 30 days were waved through without being scanned.');
            $this->line('  They appear as "Not checked" on the admin moderation screen.');
        }

        $this->newLine();

        // Fail only when the scanner is off, or when a category that ships
        // enabled in config was turned off (admin disable). Categories that
        // are off by default (crypto_promo) stay listed as "Not scanned"
        // without failing a healthy deploy check.
        $configCategories = (array) config('content_moderation.categories', []);
        $unexpectedOff = array_values(array_filter(
            $off,
            static fn (string $key): bool => (bool) ($configCategories[$key]['enabled'] ?? false)
        ));

        return $enabled && $unexpectedOff === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
