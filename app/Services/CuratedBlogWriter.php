<?php

namespace App\Services;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\CuratedBlogTombstone;
use App\Models\User;
use App\Support\PublicI18n;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CuratedBlogWriter
{
    public static function isTombstoned(string $slug): bool
    {
        if (! Schema::hasTable('curated_blog_tombstones')) {
            return false;
        }

        return CuratedBlogTombstone::query()->where('slug', $slug)->exists();
    }

    public static function rememberDeleted(Blog $blog): void
    {
        $catalogSlug = filled($blog->curated_key)
            ? (string) $blog->curated_key
            : (string) $blog->slug;
        if ($catalogSlug === '') {
            return;
        }

        // Only tombstone a real pillar. A custom post that reused a catalog
        // slug must not block later upserts of the curated article.
        $isCuratedPillar = filled($blog->curated_key)
            || (
                in_array($catalogSlug, CuratedBlogSync::curatedSlugs(), true)
                && ! $blog->manually_edited_at
            );

        if (! $isCuratedPillar || ! Schema::hasTable('curated_blog_tombstones')) {
            return;
        }

        CuratedBlogTombstone::query()->updateOrCreate(['slug' => $catalogSlug]);
    }

    /**
     * Create or update a code-defined pillar post.
     * Tombstoned slugs and admin-edited rows are left alone.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function upsert(string $slug, array $payload, ?int $actorId = null): ?Blog
    {
        unset($payload['faq']);

        if (self::isTombstoned($slug)) {
            return null;
        }

        $existing = self::findExisting($slug);

        if ($existing && $existing->manually_edited_at) {
            if (Schema::hasColumn('blogs', 'curated_key') && ! $existing->curated_key) {
                $existing->forceFill(['curated_key' => $slug])->save();
            }

            return $existing;
        }

        $authorUserId = $actorId ?? User::query()->orderBy('id')->value('id');

        $data = array_merge($payload, [
            'updated_by' => $authorUserId,
        ]);

        if (Schema::hasColumn('blogs', 'curated_key')) {
            $data['curated_key'] = $slug;
        }

        if (! $existing) {
            $data['published_at'] = $data['published_at'] ?? now();
            $data['created_by'] = $authorUserId;
            if (isset($data['slug'])) {
                $data['slug'] = self::uniquePublicSlug((string) $data['slug']);
            }
        } else {
            $data['published_at'] = $existing->published_at ?? now();
            $data['created_by'] = $existing->created_by ?? $authorUserId;
            if ($existing->author) {
                $data['author'] = $existing->author;
            }
            if ($existing->featured_image) {
                $data['featured_image'] = $existing->featured_image;
            }
            // Keep draft/unpublish. Payload is always "published"; overwriting
            // here republishes pillars that were toggled before manually_edited_at
            // was set, or unpublished directly in the DB.
            if (filled($existing->status)) {
                $data['status'] = $existing->status;
            }
            if (isset($data['slug']) && self::publicSlugTakenByAnother((string) $data['slug'], $existing->id)) {
                $data['slug'] = $existing->slug;
            }
        }

        if ($existing) {
            $existing->fill($data);
            $existing->save();
            self::syncPrimaryTranslation($existing);

            return $existing;
        }

        $created = Blog::create($data);
        self::syncPrimaryTranslation($created);

        return $created;
    }

    private static function publicSlugTakenByAnother(string $slug, int $blogId): bool
    {
        if (Blog::query()->where('slug', $slug)->where('id', '!=', $blogId)->exists()) {
            return true;
        }

        if (! Schema::hasTable('blog_translations')) {
            return false;
        }

        return BlogTranslation::query()
            ->where('slug', $slug)
            ->where('blog_id', '!=', $blogId)
            ->exists();
    }

    private static function uniquePublicSlug(string $slug, ?int $ignoreBlogId = null): string
    {
        $base = Str::slug($slug) ?: Str::random(8);
        $candidate = $base;
        $counter = 1;

        while (self::publicSlugTakenByAnother($candidate, $ignoreBlogId ?? 0)) {
            $candidate = $base.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }

    /**
     * Resolve the pillar row for a catalog slug.
     *
     * Prefers curated_key. A custom admin post that only happens to reuse the
     * catalog slug is not adopted — callers should create a new uniquified row.
     */
    public static function findExisting(string $slug): ?Blog
    {
        if (Schema::hasColumn('blogs', 'curated_key')) {
            $byKey = Blog::query()->where('curated_key', $slug)->first();
            if ($byKey) {
                return $byKey;
            }
        }

        $bySlug = Blog::query()->where('slug', $slug)->first();
        if (! $bySlug) {
            return null;
        }

        // A custom admin post can reuse a catalog slug. Do not adopt it as the
        // pillar — create a new row with a uniquified slug instead.
        if ($bySlug->manually_edited_at && ! filled($bySlug->curated_key)) {
            return null;
        }

        if (filled($bySlug->curated_key) && $bySlug->curated_key !== $slug) {
            return null;
        }

        return $bySlug;
    }

    /**
     * Public locale URLs read blog_translations, not blogs.content.
     * Upsert commands that only write blogs.* leave DE/FR/NL pillars stale
     * after a deploy until an admin full-saves the post.
     */
    public static function syncPrimaryTranslation(Blog $blog): void
    {
        if (! Schema::hasTable('blog_translations')) {
            return;
        }

        $locale = PublicI18n::isSupported($blog->primary_locale)
            ? $blog->primary_locale
            : 'en';

        $slug = $blog->slug ?: 'post-'.$blog->id;
        if (self::translationSlugTaken($slug, $blog->id, $locale)) {
            $existingSlug = BlogTranslation::query()
                ->where('blog_id', $blog->id)
                ->where('locale', $locale)
                ->value('slug');
            $slug = is_string($existingSlug) && $existingSlug !== ''
                ? $existingSlug
                : self::uniqueTranslationSlug($slug, $blog->id, $locale);
        }

        BlogTranslation::query()->updateOrCreate(
            [
                'blog_id' => $blog->id,
                'locale' => $locale,
            ],
            [
                'title' => $blog->title,
                'slug' => $slug,
                'excerpt' => $blog->excerpt,
                'content' => $blog->content,
                'is_published' => $blog->status === 'published',
            ]
        );
    }

    private static function translationSlugTaken(string $slug, int $blogId, string $locale): bool
    {
        // Public /blog/{slug} resolves translations first, then blogs.slug.
        // A uniquified {slug}-{locale} must not steal another post's fallback URL.
        if (self::publicSlugTakenByAnother($slug, $blogId)) {
            return true;
        }

        return BlogTranslation::query()
            ->where('blog_id', $blogId)
            ->where('locale', '!=', $locale)
            ->where('slug', $slug)
            ->exists();
    }

    private static function uniqueTranslationSlug(string $slug, int $blogId, string $locale): string
    {
        $base = $slug !== '' ? $slug : 'post-'.$blogId;
        $candidate = $base.'-'.$locale;
        $counter = 1;

        while (self::translationSlugTaken($candidate, $blogId, $locale)) {
            $candidate = $base.'-'.$locale.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }
}
