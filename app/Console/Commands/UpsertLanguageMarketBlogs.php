<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\User;
use App\Support\AcheterGuestPostsFrBlogPost;
use App\Support\AdvertiserGuideDeBlogPost;
use App\Support\BlogInlineImages;
use App\Support\ChoisirEditeurFrBlogPost;
use App\Support\DofollowNofollowAnchorsEnBlogPost;
use App\Support\GastpostsKopenNlBlogPost;
use App\Support\GuestPostsEuropeEnBlogPost;
use App\Support\GuestPostsUkUsBlogPost;
use App\Support\PublisherGuideDeBlogPost;
use App\Support\PublicI18n;
use App\Support\UitgeversKiezenNlBlogPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Publish/update language + market gap pillars (EN twins, DE how-tos, FR/NL, UK/US).
 */
class UpsertLanguageMarketBlogs extends Command
{
    protected $signature = 'blog:upsert-language-market';

    protected $description = 'Publish/update EN/DE/FR/NL market and locale blog pillars';

    public function handle(): int
    {
        $classes = [
            GuestPostsEuropeEnBlogPost::class,
            DofollowNofollowAnchorsEnBlogPost::class,
            AdvertiserGuideDeBlogPost::class,
            PublisherGuideDeBlogPost::class,
            AcheterGuestPostsFrBlogPost::class,
            ChoisirEditeurFrBlogPost::class,
            GastpostsKopenNlBlogPost::class,
            UitgeversKiezenNlBlogPost::class,
            GuestPostsUkUsBlogPost::class,
        ];

        $authorUser = User::query()->orderBy('id')->first();
        $ok = 0;

        foreach ($classes as $class) {
            $this->ensureImages($class);

            $payload = $class::payload();
            unset($payload['faq']);

            $existing = Blog::query()->where('slug', $class::SLUG)->first();

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
                ['slug' => $class::SLUG],
                $data
            );

            $this->syncPrimaryTranslation($blog);

            $this->info('Upserted blog #'.$blog->id.' ('.$blog->slug.') locale='.($blog->primary_locale ?: 'null'));
            $ok++;
        }

        $this->info("Language/market blogs upserted: {$ok}");

        return self::SUCCESS;
    }

    /**
     * @param  class-string  $class
     */
    private function ensureImages(string $class): void
    {
        $constants = (new \ReflectionClass($class))->getConstants();

        foreach ($constants as $name => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (str_starts_with($name, 'IMAGE_')) {
                BlogInlineImages::publish($value);
            }
        }

        if (! defined($class.'::FEATURED_ASSET') || ! defined($class.'::FEATURED_STORAGE')) {
            return;
        }

        $source = public_path($class::FEATURED_ASSET);
        $destination = storage_path('app/public/'.$class::FEATURED_STORAGE);

        if (! File::exists($source)) {
            $this->warn('Featured asset missing: '.$source);

            return;
        }

        File::ensureDirectoryExists(dirname($destination));
        File::copy($source, $destination);
    }

    private function syncPrimaryTranslation(Blog $blog): void
    {
        if (! Schema::hasTable('blog_translations')) {
            return;
        }

        $locale = PublicI18n::isSupported($blog->primary_locale)
            ? $blog->primary_locale
            : 'en';

        BlogTranslation::query()->updateOrCreate(
            [
                'blog_id' => $blog->id,
                'locale' => $locale,
            ],
            [
                'title' => $blog->title,
                'slug' => $blog->slug,
                'excerpt' => $blog->excerpt,
                'content' => $blog->content,
                'is_published' => $blog->status === 'published',
            ]
        );
    }
}
