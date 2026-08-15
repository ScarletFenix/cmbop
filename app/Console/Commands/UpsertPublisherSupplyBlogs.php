<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CuratedBlogWriter;
use App\Support\BlogInlineImages;
use App\Support\FasterPublisherPayoutsBlogPost;
use App\Support\HowToPriceYourSiteBlogPost;
use App\Support\WhySitesGetRejectedBlogPost;
use Illuminate\Console\Command;

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

        if (! BlogInlineImages::publishFeatured($class::FEATURED_STORAGE, $class::FEATURED_ASSET)) {
            $this->warn('Featured asset missing: '.public_path($class::FEATURED_ASSET));
        }
    }
}
