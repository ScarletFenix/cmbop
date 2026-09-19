<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CuratedBlogWriter;
use App\Support\BlogInlineImages;
use App\Support\GuestPostingGuideBlogPost;
use App\Support\HowToGetBacklinksBlogPost;
use App\Support\LinkBuildingGuideBlogPost;
use App\Support\SponsoredPostGuideBlogPost;
use App\Support\ThinBlogRedirects;
use Illuminate\Console\Command;

/**
 * Publish/update the English link-building pillar cluster.
 */
class UpsertLinkBuildingGuidesBlogs extends Command
{
    protected $signature = 'blog:upsert-link-building-guides';

    protected $description = 'Publish/update EN/DE/FR/NL link-building pillars (backlinks, guest posting, sponsored posts, strategy)';

    public function handle(): int
    {
        if (class_exists(ThinBlogRedirects::class)) {
            $hidden = ThinBlogRedirects::unpublishLegacy();
            if ($hidden > 0) {
                $this->line("Unpublished {$hidden} competing BlogSeeder stub(s).");
            }
        }

        $classes = [
            HowToGetBacklinksBlogPost::class,
            GuestPostingGuideBlogPost::class,
            SponsoredPostGuideBlogPost::class,
            LinkBuildingGuideBlogPost::class,
        ];

        $authorUser = User::query()->orderBy('id')->first();
        $ok = 0;

        foreach ($classes as $class) {
            try {
                if (! class_exists($class) || ! defined($class.'::SLUG') || ! method_exists($class, 'payload')) {
                    $this->warn('Skipped missing post class '.$class);

                    continue;
                }

                $this->ensureImages($class);

                $payload = $class::payload();
                if (! is_array($payload)) {
                    $this->warn('Skipped invalid payload for '.$class::SLUG);

                    continue;
                }

                $blog = CuratedBlogWriter::upsert($class::SLUG, $payload, $authorUser?->id);

                if (! $blog) {
                    $this->warn('Skipped deleted curated slug '.$class::SLUG);

                    continue;
                }

                $this->info('Upserted blog #'.$blog->id.' ('.$blog->slug.')');
                $ok++;
            } catch (\Throwable $e) {
                $this->error('Failed '.$class.': '.$e->getMessage());
            }
        }

        $this->info("Link-building guide blogs upserted: {$ok}");

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
