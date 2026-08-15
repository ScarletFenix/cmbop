<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CuratedBlogWriter;
use App\Support\BlogInlineImages;
use App\Support\LiveLinkChecklistBlogPost;
use Illuminate\Console\Command;

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

        if (! BlogInlineImages::publishFeatured(
            LiveLinkChecklistBlogPost::FEATURED_STORAGE,
            LiveLinkChecklistBlogPost::FEATURED_ASSET
        )) {
            $this->warn('Featured asset missing: '.public_path(LiveLinkChecklistBlogPost::FEATURED_ASSET));
        }
    }
}
