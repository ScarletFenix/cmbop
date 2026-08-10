<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Models\User;
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
        unset($payload['faq']);

        $existing = Blog::query()->where('slug', DofollowNofollowAnkertexteBlogPost::SLUG)->first();
        $authorUser = User::query()->orderBy('id')->first();

        $data = array_merge($payload, [
            'updated_by' => $authorUser?->id,
        ]);

        if (! $existing) {
            $data['published_at'] = now();
            $data['created_by'] = $authorUser?->id;
        } else {
            $data['published_at'] = $existing->published_at ?? now();
            $data['created_by'] = $existing->created_by ?? $authorUser?->id;
            if ($existing->author) {
                $data['author'] = $existing->author;
            }
        }

        $blog = Blog::updateOrCreate(
            ['slug' => DofollowNofollowAnkertexteBlogPost::SLUG],
            $data
        );

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
