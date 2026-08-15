<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CuratedBlogWriter;
use App\Support\BlogInlineImages;
use App\Support\GastbeitraegeEuropaBlogPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class UpsertGastbeitraegeEuropaBlog extends Command
{
    protected $signature = 'blog:upsert-gastbeitraege-europa';

    protected $description = 'Publish/update the German Europe guest-post buying guide (same body on all locale URLs)';

    public function handle(): int
    {
        $this->ensureFeaturedImage();

        $payload = GastbeitraegeEuropaBlogPost::payload();
        $authorUser = User::query()->orderBy('id')->first();
        $blog = CuratedBlogWriter::upsert(GastbeitraegeEuropaBlogPost::SLUG, $payload, $authorUser?->id);

        if (! $blog) {
            $this->warn('Skipped deleted curated slug '.GastbeitraegeEuropaBlogPost::SLUG);

            return self::SUCCESS;
        }

        $this->info('Upserted blog #'.$blog->id.' ('.$blog->slug.') primary_locale='.($blog->primary_locale ?: 'null'));

        return self::SUCCESS;
    }

    private function ensureFeaturedImage(): void
    {
        BlogInlineImages::publish(GastbeitraegeEuropaBlogPost::IMAGE_CHECKLIST);
        BlogInlineImages::publish(GastbeitraegeEuropaBlogPost::IMAGE_LANGUAGES);

        $source = public_path(GastbeitraegeEuropaBlogPost::FEATURED_ASSET);
        $destination = storage_path('app/public/'.GastbeitraegeEuropaBlogPost::FEATURED_STORAGE);

        if (! File::exists($source)) {
            $this->warn('Featured asset missing: '.$source);

            return;
        }

        File::ensureDirectoryExists(dirname($destination));
        File::copy($source, $destination);
    }
}
