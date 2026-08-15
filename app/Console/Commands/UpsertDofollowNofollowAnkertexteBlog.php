<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CuratedBlogWriter;
use App\Support\BlogInlineImages;
use App\Support\DofollowNofollowAnkertexteBlogPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

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

        $source = public_path(DofollowNofollowAnkertexteBlogPost::FEATURED_ASSET);
        $destination = storage_path('app/public/'.DofollowNofollowAnkertexteBlogPost::FEATURED_STORAGE);

        if (! File::exists($source)) {
            $this->warn('Featured asset missing: '.$source);

            return;
        }

        File::ensureDirectoryExists(dirname($destination));
        File::copy($source, $destination);
    }
}
