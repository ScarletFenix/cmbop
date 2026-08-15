<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\User;
use App\Services\CuratedBlogWriter;
use App\Support\BlogInlineImages;
use App\Support\FasterPublisherPayoutsBlogPost;
use App\Support\HowToPriceYourSiteBlogPost;
use App\Support\PublicI18n;
use App\Support\WhySitesGetRejectedBlogPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Publish/update the English publisher supply-quality posts.
 */
class UpsertPublisherSupplyBlogs extends Command
{
    protected $signature = 'blog:upsert-publisher-supply';

    protected $description = 'Publish/update EN publisher supply blogs (pricing, approval, payouts)';

    public function handle(): int
    {
        $classes = [
            HowToPriceYourSiteBlogPost::class,
            WhySitesGetRejectedBlogPost::class,
            FasterPublisherPayoutsBlogPost::class,
        ];

        $authorUser = User::query()->orderBy('id')->first();
        $ok = 0;

        foreach ($classes as $class) {
            $this->ensureImages($class);

            $payload = $class::payload();
            $blog = CuratedBlogWriter::upsert($class::SLUG, $payload, $authorUser?->id);

            if (! $blog) {
                $this->warn('Skipped deleted curated slug '.$class::SLUG);

                continue;
            }

            if (! $blog->manually_edited_at) {
                $this->syncPrimaryTranslation($blog);
            }

            $this->info('Upserted blog #'.$blog->id.' ('.$blog->slug.')');
            $ok++;
        }

        $this->info("Publisher supply blogs upserted: {$ok}");

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
