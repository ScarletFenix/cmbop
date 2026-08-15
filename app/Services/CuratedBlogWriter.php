<?php

namespace App\Services;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\CuratedBlogTombstone;
use App\Models\User;
use App\Support\PublicI18n;
use Illuminate\Support\Facades\Schema;

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
        $slug = $blog->curated_key ?: $blog->slug;
        if ($slug === '') {
            return;
        }

        $isCurated = filled($blog->curated_key)
            || in_array($slug, CuratedBlogSync::curatedSlugs(), true);

        if (! $isCurated || ! Schema::hasTable('curated_blog_tombstones')) {
            return;
        }

        CuratedBlogTombstone::query()->updateOrCreate(['slug' => $slug]);
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

    private static function findExisting(string $slug): ?Blog
    {
        if (Schema::hasColumn('blogs', 'curated_key')) {
            $byKey = Blog::query()->where('curated_key', $slug)->first();
            if ($byKey) {
                return $byKey;
            }
        }

        return Blog::query()->where('slug', $slug)->first();
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
        $slugTaken = BlogTranslation::query()
            ->where('slug', $slug)
            ->where(function ($query) use ($blog, $locale) {
                $query->where('blog_id', '!=', $blog->id)
                    ->orWhere('locale', '!=', $locale);
            })
            ->exists();
        if ($slugTaken) {
            $existingSlug = BlogTranslation::query()
                ->where('blog_id', $blog->id)
                ->where('locale', $locale)
                ->value('slug');
            $slug = is_string($existingSlug) && $existingSlug !== ''
                ? $existingSlug
                : $slug.'-'.$locale;
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
}
