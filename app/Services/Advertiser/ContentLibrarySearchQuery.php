<?php

namespace App\Services\Advertiser;

use App\Services\Catalog\CatalogSearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Content Library free-text search: catalog token rules, word-AND across
 * title and original filename.
 */
class ContentLibrarySearchQuery
{
    public function __construct(
        private readonly CatalogSearchQuery $catalogSearch,
    ) {}

    /**
     * Apply search constraints to a ContentSubmission query.
     */
    public function apply(Builder $query, string $raw): void
    {
        $text = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        if ($text === '') {
            return;
        }

        $tokens = $this->catalogSearch->tokens($text);
        if ($tokens === []) {
            return;
        }

        $applied = 0;
        foreach ($tokens as $token) {
            $escaped = $this->escapeLike($token);
            if ($escaped === '') {
                continue;
            }
            $applied++;
            $like = '%'.$escaped.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('original_filename', 'like', $like);
            });
        }

        // Input was only LIKE wildcards — do not match everything.
        if ($applied === 0) {
            $query->whereRaw('0 = 1');
        }
    }

    public function escapeLike(string $value): string
    {
        // Neutralize LIKE wildcards so user input cannot broaden the match.
        return str_replace(['\\', '%', '_'], ['', '', ''], $value);
    }
}
