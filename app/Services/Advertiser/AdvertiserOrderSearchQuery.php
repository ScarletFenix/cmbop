<?php

namespace App\Services\Advertiser;

use App\Services\Catalog\CatalogSearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Advertiser Orders free-text search: word-AND across order # / reference /
 * site name / site URL / live URL, with host normalization and safe LIKE.
 */
class AdvertiserOrderSearchQuery
{
    public function __construct(
        private readonly CatalogSearchQuery $catalogSearch,
    ) {}

    /**
     * Apply search constraints to an Order query (already scoped to the user).
     */
    public function apply(Builder $query, string $raw, ?string $hostNeedle = null): void
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
            $tokenHost = $this->hostFromToken($token) ?? $hostNeedle;

            $query->where(function (Builder $q) use ($like, $tokenHost) {
                $q->where('order_number', 'like', $like)
                    ->orWhere('reference_code', 'like', $like)
                    ->orWhereHas('items', function (Builder $sub) use ($like, $tokenHost) {
                        $sub->where(function (Builder $item) use ($like, $tokenHost) {
                            $item->where('site_name', 'like', $like)
                                ->orWhere('site_url', 'like', $like)
                                ->orWhere('live_url', 'like', $like);

                            if ($tokenHost) {
                                $hostEscaped = $this->escapeLike($tokenHost);
                                if ($hostEscaped !== '') {
                                    $hostLike = '%'.$hostEscaped.'%';
                                    $item->orWhere('site_url', 'like', $hostLike)
                                        ->orWhere('live_url', 'like', $hostLike)
                                        ->orWhere('site_name', 'like', $hostLike);
                                }
                            }
                        });
                    });
            });
        }

        // Input was only LIKE wildcards — do not match everything.
        if ($applied === 0) {
            $query->whereRaw('0 = 1');
        }
    }

    /**
     * Soft relevance: exact-ish order # / reference first, then recent.
     *
     * @param  list<string>  $bindings
     */
    public function applyRelevanceOrder(Builder $query, string $raw): void
    {
        $text = trim($raw);
        if ($text === '') {
            return;
        }

        $needle = $this->escapeLike($text);
        if ($needle === '') {
            return;
        }

        $exact = $needle;
        $prefix = $needle.'%';
        $contains = '%'.$needle.'%';

        $query->orderByRaw(
            'CASE
                WHEN order_number = ? OR reference_code = ? THEN 0
                WHEN order_number LIKE ? OR reference_code LIKE ? THEN 1
                WHEN order_number LIKE ? OR reference_code LIKE ? THEN 2
                ELSE 3
            END',
            [$exact, $exact, $prefix, $prefix, $contains, $contains]
        );
    }

    public function escapeLike(string $value): string
    {
        // Neutralize LIKE wildcards so user input cannot broaden the match.
        return str_replace(['\\', '%', '_'], ['', '', ''], $value);
    }

    private function hostFromToken(string $token): ?string
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        // Mirror CatalogController::catalogSearchHostNeedle for a single token.
        $candidate = $token;
        if (! preg_match('#^https?://#i', $candidate) && str_contains($candidate, '/')) {
            $candidate = 'https://'.$candidate;
        } elseif (preg_match('#^https?://#i', $candidate) === 0 && str_contains($candidate, '.')) {
            $candidate = 'https://'.$candidate;
        } elseif (preg_match('#^https?://#i', $candidate) === 0) {
            return null;
        }

        $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?: '';

        if ($host === '' || ! str_contains($host, '.')) {
            return null;
        }

        return $host;
    }
}
