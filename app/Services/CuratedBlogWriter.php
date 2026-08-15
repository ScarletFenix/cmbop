<?php

namespace App\Services;

use App\Models\Blog;
use App\Models\CuratedBlogTombstone;
use App\Models\User;
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

        $existing = Blog::query()
            ->where('slug', $slug)
            ->when(
                Schema::hasColumn('blogs', 'curated_key'),
                fn ($query) => $query->orWhere('curated_key', $slug)
            )
            ->first();

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
        }

        return Blog::updateOrCreate(['slug' => $slug], $data);
    }
}
