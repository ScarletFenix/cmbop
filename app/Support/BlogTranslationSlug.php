<?php

namespace App\Support;

use App\Models\Blog;
use App\Models\BlogTranslation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Give each published translation its own slug when it still copies another locale.
 */
class BlogTranslationSlug
{
    public static function hasCopiedSlugs(): bool
    {
        if (! Schema::hasTable('blog_translations')) {
            return false;
        }

        $localeSuffix = DB::connection()->getDriverName() === 'sqlite'
            ? "a.slug = b.slug || '-' || a.locale"
            : "a.slug = CONCAT(b.slug, '-', a.locale)";

        return DB::table('blog_translations as a')
            ->join('blog_translations as b', function ($join) {
                $join->on('a.blog_id', '=', 'b.blog_id')
                    ->whereColumn('a.id', '!=', 'b.id');
            })
            ->where(function ($query) use ($localeSuffix) {
                $query->whereColumn('a.slug', 'b.slug')
                    ->orWhereRaw($localeSuffix);
            })
            ->exists();
    }

    public static function localizeCopiedSlugs(): int
    {
        if (! Schema::hasTable('blog_translations')) {
            return 0;
        }

        $updated = 0;
        $grouped = BlogTranslation::query()
            ->where('is_published', true)
            ->orderBy('id')
            ->get()
            ->groupBy('blog_id');

        foreach ($grouped as $blogId => $translations) {
            foreach ($translations as $translation) {
                $fromTitle = Str::slug((string) $translation->title);
                if ($fromTitle === '' || $fromTitle === $translation->slug) {
                    continue;
                }

                $copied = $translations->contains(
                    fn (BlogTranslation $other) => $other->id !== $translation->id
                        && (
                            $other->slug === $translation->slug
                            || $translation->slug === $other->slug.'-'.$translation->locale
                        )
                );
                if (! $copied) {
                    continue;
                }

                $unique = self::unique($fromTitle, (int) $blogId, (int) $translation->id);
                if ($unique === $translation->slug) {
                    continue;
                }

                $translation->slug = $unique;
                $translation->save();
                $updated++;
            }
        }

        return $updated;
    }

    public static function unique(string $slug, int $blogId, ?int $ignoreTranslationId = null): string
    {
        $base = Str::slug($slug) ?: 'post-'.$blogId;
        $candidate = $base;
        $counter = 1;

        while (self::taken($candidate, $blogId, $ignoreTranslationId)) {
            $candidate = $base.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }

    private static function taken(string $slug, int $blogId, ?int $ignoreTranslationId): bool
    {
        if (Blog::query()->where('slug', $slug)->where('id', '!=', $blogId)->exists()) {
            return true;
        }

        return BlogTranslation::query()
            ->where('slug', $slug)
            ->where('id', '!=', $ignoreTranslationId ?: 0)
            ->exists();
    }
}
