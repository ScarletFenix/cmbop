<?php

namespace App\Console\Commands;

use App\Support\EmailCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Local/dev helper: write HTML (and text) samples of catalog mailables.
 * Not a public HTTP route.
 */
class RenderMailPreviews extends Command
{
    protected $signature = 'mail:render-previews
        {keys?* : Catalog keys to render (default: article-live, order-placed, weekly digest)}
        {--dir= : Output directory (default storage/app/mail-previews)}';

    protected $description = 'Render transactional mail HTML previews (local/dev only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing', 'development'])) {
            $this->error('mail:render-previews is only available in local/dev.');

            return self::FAILURE;
        }

        $dir = $this->option('dir') ?: storage_path('app/mail-previews');
        File::ensureDirectoryExists($dir);

        $keys = $this->argument('keys') ?: [
            'live_url_submitted',
            'order_status_changed',
            'order_payment_confirmed',
            'weekly_new_sites',
            'order_accepted',
            'deposit_approved',
        ];

        foreach ($keys as $key) {
            $mailable = EmailCatalog::makeMailable($key);
            if (! $mailable) {
                $this->warn('Skip unknown catalog key: '.$key);

                continue;
            }

            $html = $mailable->render();
            $htmlPath = $dir.'/'.$key.'.html';
            File::put($htmlPath, $html);
            $this->line($htmlPath);
        }

        $this->info('Wrote '.count($keys).' preview file(s) to '.$dir);

        return self::SUCCESS;
    }
}
