<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CuratedBlogWriter;
use App\Support\BlogInlineImages;
use App\Support\LiveLinkChecklistBlogPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class UpsertLiveLinkChecklistBlog extends Command
{
    protected $signature = 'blog:upsert-live-link-checklist';

    protected $description = 'Publish/update the English live-link QA checklist (indexation, attributes, rankings)';

    public function handle(): int
    {
        $this->ensureFeaturedImage();

        $payload = LiveLinkChecklistBlogPost::payload();
        $authorUser = User::query()->orderBy('id')->first();
        $blog = CuratedBlogWriter::upsert(LiveLinkChecklistBlogPost::SLUG, $payload, $authorUser?->id);

        if (! $blog) {
            $this->warn('Skipped deleted curated slug '.LiveLinkChecklistBlogPost::SLUG);

            return self::SUCCESS;
        }

        $this->info('Upserted blog #'.$blog->id.' ('.$blog->slug.') primary_locale='.($blog->primary_locale ?: 'null'));

        return self::SUCCESS;
    }

    private function ensureFeaturedImage(): void
    {
        BlogInlineImages::publish(LiveLinkChecklistBlogPost::IMAGE_ATTRIBUTES);
        BlogInlineImages::publish(LiveLinkChecklistBlogPost::IMAGE_RANKINGS);

        $source = public_path(LiveLinkChecklistBlogPost::FEATURED_ASSET);
        $destination = storage_path('app/public/'.LiveLinkChecklistBlogPost::FEATURED_STORAGE);

        if (! File::exists($source)) {
            $this->warn('Featured asset missing: '.$source);

            return;
        }

        File::ensureDirectoryExists(dirname($destination));
        File::copy($source, $destination);
    }
}
