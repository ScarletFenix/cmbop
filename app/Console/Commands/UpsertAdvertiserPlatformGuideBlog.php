<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Models\User;
use App\Support\AdvertiserPlatformGuideBlogPost;
use App\Support\BlogInlineImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class UpsertAdvertiserPlatformGuideBlog extends Command
{
    protected $signature = 'blog:upsert-advertiser-platform-guide';

    protected $description = 'Publish/update the English advertiser how-to guide (catalog, content, wallet, orders)';

    public function handle(): int
    {
        $this->ensureImages();

        $payload = AdvertiserPlatformGuideBlogPost::payload();
        unset($payload['faq']);

        $existing = Blog::query()->where('slug', AdvertiserPlatformGuideBlogPost::SLUG)->first();
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
            ['slug' => AdvertiserPlatformGuideBlogPost::SLUG],
            $data
        );

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

        $source = public_path(AdvertiserPlatformGuideBlogPost::FEATURED_ASSET);
        $destination = storage_path('app/public/'.AdvertiserPlatformGuideBlogPost::FEATURED_STORAGE);

        if (! File::exists($source)) {
            $this->warn('Featured asset missing: '.$source);

            return;
        }

        File::ensureDirectoryExists(dirname($destination));
        File::copy($source, $destination);
    }
}
