<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CuratedBlogWriter;
use App\Support\AcheterGuestPostsFrBlogPost;
use App\Support\AdvertiserGuideDeBlogPost;
use App\Support\BlogInlineImages;
use App\Support\ChoisirEditeurFrBlogPost;
use App\Support\DofollowNofollowAnchorsEnBlogPost;
use App\Support\GastpostsKopenNlBlogPost;
use App\Support\GuestPostsEuropeEnBlogPost;
use App\Support\GuestPostsUkUsBlogPost;
use App\Support\PublisherGuideDeBlogPost;
use App\Support\UitgeversKiezenNlBlogPost;
use Illuminate\Console\Command;

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
            $blog = CuratedBlogWriter::upsert($class::SLUG, $payload, $authorUser?->id);

            if (! $blog) {
                $this->warn('Skipped deleted curated slug '.$class::SLUG);

                continue;
            }

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

        if (! BlogInlineImages::publishFeatured($class::FEATURED_STORAGE, $class::FEATURED_ASSET)) {
            $this->warn('Featured asset missing: '.public_path($class::FEATURED_ASSET));
        }
    }
}
