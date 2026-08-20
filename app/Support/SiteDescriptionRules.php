<?php

namespace App\Support;

use App\Services\OrderChatContactGuard;
use Illuminate\Support\Str;

/**
 * Publisher site description limits — validated on visible plain text, not HTML tags.
 */
class SiteDescriptionRules
{
    public const MIN_CHARS = 50;

    public const MAX_CHARS = 5000;

    public const MAX_WORDS = 500;

    public const EXCERPT_CHARS = 260;

    public const ENGLISH_LOOKS_MIN_CHARS = 40;

    /**
     * Function words used to guess whether a brief is already English.
     * Staff Activate uses this for a warning only — not a hard block.
     *
     * @var list<string>
     */
    public const ENGLISH_HINT_WORDS = [
        'the', 'and', 'for', 'with', 'this', 'that', 'your', 'from', 'are',
        'not', 'have', 'will', 'their', 'our', 'you', 'can', 'about', 'when',
        'guest', 'publishers', 'advertisers', 'audience', 'website',
    ];

    /**
     * @var list<string>
     */
    public const NON_ENGLISH_HINT_WORDS = [
        'und', 'für', 'die', 'der', 'das', 'mit', 'eine', 'einen',
        'les', 'des', 'une', 'pour', 'avec', 'est',
        'el', 'los', 'las', 'para', 'con', 'una', 'este',
        'per', 'della', 'che', 'questo',
        'het', 'een', 'van', 'niet',
        'och', 'att', 'som',
    ];

    public static function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Quill Snow posts <p><br></p> (or similar) when the editor is empty.
     * Treat that as no description so staff saves are not blocked by min:50
     * on the raw HTML wrapper. Non-strings stay with the validator.
     */
    public static function isBlankHtml(mixed $html): bool
    {
        return is_string($html) && self::plainText($html) === '';
    }

    public static function wordCount(string $plain): int
    {
        $plain = trim($plain);
        if ($plain === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }

    /**
     * @return list<string>
     */
    public static function errors(string $html): array
    {
        $plain = self::plainText($html);
        $errors = [];

        if ($plain === '') {
            $errors[] = 'Please enter a site description.';

            return $errors;
        }

        if (mb_strlen($plain) < self::MIN_CHARS) {
            $errors[] = 'Description must be at least '.self::MIN_CHARS.' characters (visible text).';
        }

        if (mb_strlen($plain) > self::MAX_CHARS) {
            $errors[] = 'Description must be at most '.self::MAX_CHARS.' characters.';
        }

        if (self::wordCount($plain) > self::MAX_WORDS) {
            $errors[] = 'Description must be at most '.self::MAX_WORDS.' words.';
        }

        $guard = new OrderChatContactGuard;
        if ($guard->isBlocked($plain) || $guard->isBlocked($html)) {
            $errors[] = OrderChatContactGuard::messageFor('description');
        }

        return $errors;
    }

    public static function isValid(string $html): bool
    {
        return self::errors($html) === [];
    }

    public static function excerpt(?string $html, ?int $limit = null): string
    {
        $plain = self::plainText((string) $html);
        if ($plain === '') {
            return '';
        }

        return Str::limit($plain, $limit ?? self::EXCERPT_CHARS);
    }

    /**
     * Conservative guess for staff Activate: warn when the brief does not
     * look English. Listing language (de) is ignored — only the text counts.
     */
    public static function looksLikeEnglish(?string $html): bool
    {
        $plain = self::plainText((string) $html);
        if (mb_strlen($plain) < self::ENGLISH_LOOKS_MIN_CHARS) {
            return false;
        }

        $englishHits = self::countHintWords($plain, self::ENGLISH_HINT_WORDS);
        $otherHits = self::countHintWords($plain, self::NON_ENGLISH_HINT_WORDS);

        return $englishHits >= 2 && $englishHits > $otherHits;
    }

    /**
     * @param  list<string>  $words
     */
    private static function countHintWords(string $plain, array $words): int
    {
        $count = 0;
        foreach ($words as $word) {
            $matched = preg_match_all('/\b'.preg_quote($word, '/').'\b/iu', $plain);
            if (is_int($matched) && $matched > 0) {
                $count += $matched;
            }
        }

        return $count;
    }

    public static function helpText(): string
    {
        return 'Shown to advertisers on your listing. Min '.self::MIN_CHARS.' characters, max '.self::MAX_WORDS.' words.';
    }

    public static function placeholder(): string
    {
        return 'Describe your audience, niches, and why advertisers should buy a placement here…';
    }
}
