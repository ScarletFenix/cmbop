<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Models\User;
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
        unset($payload['faq']);

        $existing = Blog::query()->where('slug', LiveLinkChecklistBlogPost::SLUG)->first();
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
            ['slug' => LiveLinkChecklistBlogPost::SLUG],
            $data
        );

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
