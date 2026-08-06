<?php

namespace App\Console\Commands;

use App\Services\CuratedBlogSync;
use App\Support\BlogInlineImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class UpsertCuratedBlogs extends Command
{
    protected $signature = 'blog:upsert-curated';

    protected $description = 'Upsert all curated SEO pillar blog posts into the database (visible in Admin → Blogs)';

    /** @var list<string> */
    private array $commands = [
        'blog:upsert-backlinks-aufbauen',
        'blog:upsert-gastbeitraege-europa',
        'blog:upsert-dofollow-nofollow-ankertexte',
        'blog:upsert-live-link-checklist',
        'blog:upsert-advertiser-platform-guide',
        'blog:upsert-publisher-platform-guide',
        'blog:upsert-trust-conversion',
    ];

    public function handle(): int
    {
        CuratedBlogSync::ensureSchema();

        $published = BlogInlineImages::publishAllFromPublicAssets();
        $this->line("Published {$published} blog image(s) to storage/blogs/content.");

        $ok = 0;
        $failed = 0;

        foreach ($this->commands as $signature) {
            $this->line('Running '.$signature.'…');
            try {
                $exit = $this->call($signature);
                if ($exit === self::SUCCESS) {
                    $ok++;
                } else {
                    $failed++;
                    $this->error('Command failed: '.$signature.' (exit '.$exit.')');
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error('Command error: '.$signature.' — '.$e->getMessage());
            }
        }

        Cache::forget('curated_blogs_present_v1');

        $this->newLine();
        $this->info("Curated blog sync finished. OK={$ok}, failed={$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
