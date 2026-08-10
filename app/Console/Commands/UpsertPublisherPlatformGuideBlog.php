<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Models\User;
use App\Support\BlogInlineImages;
use App\Support\PublisherPlatformGuideBlogPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class UpsertPublisherPlatformGuideBlog extends Command
{
    protected $signature = 'blog:upsert-publisher-platform-guide';

    protected $description = 'Publish/update the English publisher how-to guide (sites, tasks, withdraw)';

    public function handle(): int
    {
        $this->ensureImages();

        $payload = PublisherPlatformGuideBlogPost::payload();
        unset($payload['faq']);

        $existing = Blog::query()->where('slug', PublisherPlatformGuideBlogPost::SLUG)->first();
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
            ['slug' => PublisherPlatformGuideBlogPost::SLUG],
            $data
        );

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

        $source = public_path(PublisherPlatformGuideBlogPost::FEATURED_ASSET);
        $destination = storage_path('app/public/'.PublisherPlatformGuideBlogPost::FEATURED_STORAGE);

        if (! File::exists($source)) {
            $this->warn('Featured asset missing: '.$source);

            return;
        }

        File::ensureDirectoryExists(dirname($destination));
        File::copy($source, $destination);
    }
}
