<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CuratedBlogWriter;
use App\Support\BlogInlineImages;
use App\Support\DofollowNofollowAnkertexteBlogPost;
use Illuminate\Console\Command;

class UpsertDofollowNofollowAnkertexteBlog extends Command
{
    protected $signature = 'blog:upsert-dofollow-nofollow-ankertexte';

    protected $description = 'Publish/update the German DoFollow/NoFollow/anchor-text marketplace guide';

    public function handle(): int
    {
        $this->ensureFeaturedImage();

        $payload = DofollowNofollowAnkertexteBlogPost::payload();
        $authorUser = User::query()->orderBy('id')->first();
        $blog = CuratedBlogWriter::upsert(DofollowNofollowAnkertexteBlogPost::SLUG, $payload, $authorUser?->id);

        if (! $blog) {
            $this->warn('Skipped deleted curated slug '.DofollowNofollowAnkertexteBlogPost::SLUG);

            return self::SUCCESS;
        }

        $this->info('Upserted blog #'.$blog->id.' ('.$blog->slug.') primary_locale='.($blog->primary_locale ?: 'null'));

        return self::SUCCESS;
    }

    private function ensureFeaturedImage(): void
    {
        BlogInlineImages::publish(DofollowNofollowAnkertexteBlogPost::IMAGE_LINK_TYPES);
        BlogInlineImages::publish(DofollowNofollowAnkertexteBlogPost::IMAGE_ANCHOR_MIX);

        if (! BlogInlineImages::publishFeatured(
            DofollowNofollowAnkertexteBlogPost::FEATURED_STORAGE,
            DofollowNofollowAnkertexteBlogPost::FEATURED_ASSET
        )) {
            $this->warn('Featured asset missing: '.public_path(DofollowNofollowAnkertexteBlogPost::FEATURED_ASSET));
        }
    }
}
