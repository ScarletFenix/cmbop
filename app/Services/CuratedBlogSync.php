<?php

namespace App\Services;

use App\Models\Blog;
use App\Support\AcheterGuestPostsFrBlogPost;
use App\Support\AdvertiserGuideDeBlogPost;
use App\Support\AdvertiserPlatformGuideBlogPost;
use App\Support\AiAeoGuestPostsBlogPost;
use App\Support\BacklinksAufbauenBlogPost;
use App\Support\BlogInlineImages;
use App\Support\ChoisirEditeurFrBlogPost;
use App\Support\ChoosePublisherSiteBlogPost;
use App\Support\DofollowNofollowAnchorsEnBlogPost;
use App\Support\DofollowNofollowAnkertexteBlogPost;
use App\Support\FasterPublisherPayoutsBlogPost;
use App\Support\GastbeitraegeEuropaBlogPost;
use App\Support\GastpostsKopenNlBlogPost;
use App\Support\GuestPostBriefBlogPost;
use App\Support\GuestPostsEuropeEnBlogPost;
use App\Support\GuestPostsUkUsBlogPost;
use App\Support\LiveLinkChecklistBlogPost;
use App\Support\LiveLinkRemovedBlogPost;
use App\Support\MarketplaceVsOutreachBlogPost;
use App\Support\PublisherGuideDeBlogPost;
use App\Support\PublisherPlatformGuideBlogPost;
use App\Support\UitgeversKiezenNlBlogPost;
use App\Support\WalletEscrowRefundsBlogPost;
use App\Support\WhySitesGetRejectedBlogPost;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CuratedBlogSync
{
    /** @return list<string> */
    public static function curatedSlugs(): array
    {
        return [
            BacklinksAufbauenBlogPost::SLUG,
            GastbeitraegeEuropaBlogPost::SLUG,
            DofollowNofollowAnkertexteBlogPost::SLUG,
            LiveLinkChecklistBlogPost::SLUG,
            AdvertiserPlatformGuideBlogPost::SLUG,
            PublisherPlatformGuideBlogPost::SLUG,
            ChoosePublisherSiteBlogPost::SLUG,
            WalletEscrowRefundsBlogPost::SLUG,
            LiveLinkRemovedBlogPost::SLUG,
            GuestPostBriefBlogPost::SLUG,
            MarketplaceVsOutreachBlogPost::SLUG,
            AiAeoGuestPostsBlogPost::SLUG,
            GuestPostsEuropeEnBlogPost::SLUG,
            DofollowNofollowAnchorsEnBlogPost::SLUG,
            AdvertiserGuideDeBlogPost::SLUG,
            PublisherGuideDeBlogPost::SLUG,
            AcheterGuestPostsFrBlogPost::SLUG,
            ChoisirEditeurFrBlogPost::SLUG,
            GastpostsKopenNlBlogPost::SLUG,
            UitgeversKiezenNlBlogPost::SLUG,
            GuestPostsUkUsBlogPost::SLUG,
        ];
    }

    /**
     * Ensure blog schema pieces exist (older production DBs may have skipped migrations).
     */
    public static function ensureSchema(): void
    {
        if (! Schema::hasTable('blogs')) {
            return;
        }

        if (! Schema::hasColumn('blogs', 'primary_locale')) {
            Schema::table('blogs', function ($table) {
                $table->string('primary_locale', 5)->nullable()->after('slug');
            });
        }

        self::ensureTranslationsTable();
    }

    /**
     * Create blog_translations + backfill from blogs when the migration was skipped.
     * Public /blog and locale sitemaps query this table and 500 without it.
     */
    public static function ensureTranslationsTable(): void
    {
        try {
            if (! Schema::hasTable('blogs')) {
                return;
            }

            if (! Schema::hasTable('blog_translations')) {
                Schema::create('blog_translations', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
                    $table->string('locale', 8);
                    $table->string('title');
                    $table->string('slug');
                    $table->text('excerpt')->nullable();
                    $table->longText('content');
                    $table->string('meta_title')->nullable();
                    $table->text('meta_description')->nullable();
                    $table->boolean('is_published')->default(true);
                    $table->timestamps();

                    $table->unique(['blog_id', 'locale']);
                    $table->unique('slug');
                });
                Log::warning('blog_translations table was missing — created at runtime');
                self::backfillTranslationsFromBlogs();

                return;
            }

            // One empty-table check per process; table presence is re-checked each call.
            static $emptyChecked = false;
            if (! $emptyChecked) {
                $emptyChecked = true;
                if (Blog::query()->exists() && ! DB::table('blog_translations')->exists()) {
                    self::backfillTranslationsFromBlogs();
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to ensure blog_translations schema', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Copy legacy blogs.* content into blog_translations (idempotent per blog/locale).
     */
    public static function backfillTranslationsFromBlogs(): void
    {
        if (! Schema::hasTable('blog_translations') || ! Schema::hasTable('blogs')) {
            return;
        }

        $usedSlugs = DB::table('blog_translations')->pluck('slug')->all();
        $used = array_fill_keys($usedSlugs, true);

        DB::table('blogs')->orderBy('id')->chunkById(100, function ($blogs) use (&$used): void {
            foreach ($blogs as $blog) {
                $locale = in_array($blog->primary_locale ?? null, ['en', 'de', 'fr', 'nl'], true)
                    ? $blog->primary_locale
                    : 'en';

                $exists = DB::table('blog_translations')
                    ->where('blog_id', $blog->id)
                    ->where('locale', $locale)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $baseSlug = $blog->slug ?: Str::slug((string) $blog->title);
                $slug = $baseSlug !== '' ? $baseSlug : 'post-'.$blog->id;
                $counter = 1;
                while (isset($used[$slug])) {
                    $slug = $baseSlug.'-'.$counter;
                    $counter++;
                }
                $used[$slug] = true;

                DB::table('blog_translations')->insert([
                    'blog_id' => $blog->id,
                    'locale' => $locale,
                    'title' => $blog->title ?: 'Untitled',
                    'slug' => $slug,
                    'excerpt' => $blog->excerpt,
                    'content' => $blog->content ?: '',
                    'meta_title' => null,
                    'meta_description' => null,
                    'is_published' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * Run all curated upserts. Returns true when Artisan exits 0.
     */
    public static function sync(): bool
    {
        self::ensureSchema();

        try {
            $exit = Artisan::call('blog:upsert-curated');
            $output = trim(Artisan::output());
            if ($exit !== 0) {
                Log::error('Curated blog sync failed', ['exit' => $exit, 'output' => $output]);

                return false;
            }

            Cache::forget('curated_blogs_present_v1');

            return true;
        } catch (\Throwable $e) {
            Log::error('Curated blog sync exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Idempotent heal for production: if any curated slug is missing, sync once.
     * Also re-sync when content still points at /assets/img/blog/ (broken on many hosts).
     * Safe to call from public blog routes after deploy.
     */
    public static function ensurePresent(): void
    {
        if (! Schema::hasTable('blogs')) {
            return;
        }

        // Always heal schema first — curated presence cache must not skip translations table.
        self::ensureSchema();

        $present = Cache::remember('curated_blogs_present_v1', now()->addMinutes(30), function () {
            $slugs = self::curatedSlugs();
            $found = Blog::query()
                ->whereIn('slug', $slugs)
                ->pluck('slug')
                ->all();

            if (count($found) >= count($slugs)) {
                return true;
            }

            Log::warning('Curated blogs missing from database — auto-syncing', [
                'expected' => $slugs,
                'found' => $found,
            ]);

            return self::sync();
        });

        // If cache says false from a failed sync, retry on next request after short TTL
        if ($present === false) {
            Cache::forget('curated_blogs_present_v1');
        }

        self::ensureInlineImagesOnStorage();
    }

    /**
     * Heal curated posts whose HTML still references public /assets/img/blog/ paths.
     */
    public static function ensureInlineImagesOnStorage(): void
    {
        Cache::remember('curated_blogs_inline_storage_v1', now()->addMinutes(30), function () {
            self::ensureSchema();
            BlogInlineImages::publishAllFromPublicAssets();

            $needsRewrite = Blog::query()
                ->whereIn('slug', self::curatedSlugs())
                ->where('content', 'like', '%/assets/img/blog/%')
                ->exists();

            if (! $needsRewrite) {
                return true;
            }

            Log::warning('Curated blogs still use /assets/img/blog/ inline paths — re-syncing to storage URLs');

            $ok = self::sync();
            if (! $ok) {
                Cache::forget('curated_blogs_inline_storage_v1');
            }

            return $ok;
        });
    }
}
