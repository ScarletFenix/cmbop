<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CuratedBlogWriter;
use App\Support\AdvertiserPlatformGuideBlogPost;
use App\Support\BlogInlineImages;
use Illuminate\Console\Command;

class UpsertAdvertiserPlatformGuideBlog extends Command
{
    protected $signature = 'blog:upsert-advertiser-platform-guide';

    protected $description = 'Publish/update the English advertiser how-to guide (catalog, content, wallet, orders)';

    public function handle(): int
    {
        $this->ensureImages();

        $payload = AdvertiserPlatformGuideBlogPost::payload();
        $authorUser = User::query()->orderBy('id')->first();
        $blog = CuratedBlogWriter::upsert(AdvertiserPlatformGuideBlogPost::SLUG, $payload, $authorUser?->id);

        if (! $blog) {
            $this->warn('Skipped deleted curated slug '.AdvertiserPlatformGuideBlogPost::SLUG);

            return self::SUCCESS;
        }

        $this->info('Upserted blog #'.$blog->id.' ('.$blog->slug.') primary_locale='.($blog->primary_locale ?: 'null'));

        return self::SUCCESS;
    }

    private function ensureImages(): void
    {
        foreach ([
            AdvertiserPlatformGuideBlogPost::IMAGE_DASHBOARD,
            AdvertiserPlatformGuideBlogPost::IMAGE_CATALOG,
            AdvertiserPlatformGuideBlogPost::IMAGE_CONTENT,
            AdvertiserPlatformGuideBlogPost::IMAGE_FUNDS,
            AdvertiserPlatformGuideBlogPost::IMAGE_ORDERS,
        ] as $file) {
            BlogInlineImages::publish($file);
        }

        if (! BlogInlineImages::publishFeatured(
            AdvertiserPlatformGuideBlogPost::FEATURED_STORAGE,
            AdvertiserPlatformGuideBlogPost::FEATURED_ASSET
        )) {
            $this->warn('Featured asset missing: '.public_path(AdvertiserPlatformGuideBlogPost::FEATURED_ASSET));
        }
    }
}
