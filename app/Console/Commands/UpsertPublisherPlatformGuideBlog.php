<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CuratedBlogWriter;
use App\Support\BlogInlineImages;
use App\Support\PublisherPlatformGuideBlogPost;
use Illuminate\Console\Command;

class UpsertPublisherPlatformGuideBlog extends Command
{
    protected $signature = 'blog:upsert-publisher-platform-guide';

    protected $description = 'Publish/update the English publisher how-to guide (sites, tasks, withdraw)';

    public function handle(): int
    {
        $this->ensureImages();

        $payload = PublisherPlatformGuideBlogPost::payload();
        $authorUser = User::query()->orderBy('id')->first();
        $blog = CuratedBlogWriter::upsert(PublisherPlatformGuideBlogPost::SLUG, $payload, $authorUser?->id);

        if (! $blog) {
            $this->warn('Skipped deleted curated slug '.PublisherPlatformGuideBlogPost::SLUG);

            return self::SUCCESS;
        }

        $this->info('Upserted blog #'.$blog->id.' ('.$blog->slug.') primary_locale='.($blog->primary_locale ?: 'null'));

        return self::SUCCESS;
    }

    private function ensureImages(): void
    {
        foreach ([
            PublisherPlatformGuideBlogPost::IMAGE_MYSITES,
            PublisherPlatformGuideBlogPost::IMAGE_TASKS,
            PublisherPlatformGuideBlogPost::IMAGE_BALANCE,
        ] as $file) {
            BlogInlineImages::publish($file);
        }

        if (! BlogInlineImages::publishFeatured(
            PublisherPlatformGuideBlogPost::FEATURED_STORAGE,
            PublisherPlatformGuideBlogPost::FEATURED_ASSET
        )) {
            $this->warn('Featured asset missing: '.public_path(PublisherPlatformGuideBlogPost::FEATURED_ASSET));
        }
    }
}
