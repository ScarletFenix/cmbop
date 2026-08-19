<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Publisher site description limits — validated on visible plain text, not HTML tags.
 */
class SiteDescriptionRules
{
    public const MIN_CHARS = 50;

    public const MAX_WORDS = 500;

    public const EXCERPT_CHARS = 260;

    public static function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
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

        if (self::wordCount($plain) > self::MAX_WORDS) {
            $errors[] = 'Description must be at most '.self::MAX_WORDS.' words.';
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

    public static function helpText(): string
    {
        return 'Shown to advertisers on your listing. Min '.self::MIN_CHARS.' characters, max '.self::MAX_WORDS.' words.';
    }

    public static function placeholder(): string
    {
        return 'Describe your audience, niches, and why advertisers should buy a placement here…';
    }
}
