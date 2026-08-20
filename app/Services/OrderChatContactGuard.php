<?php

namespace App\Services;

/**
 * Detects sharing or requesting personal contact details in order chat.
 */
class OrderChatContactGuard
{
    public const REASON_SHARE = 'contact_share';

    public const REASON_ASK = 'contact_ask';

    public const MODE_CHAT = 'chat';

    public const MODE_CONTENT = 'content';

    /**
     * @return array{blocked: bool, reason: ?string}
     */
    public function inspect(string $message, string $mode = self::MODE_CHAT): array
    {
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return ['blocked' => false, 'reason' => null];
        }

        if ($this->detectsShare($normalized, $message, $mode)) {
            return ['blocked' => true, 'reason' => self::REASON_SHARE];
        }

        if ($this->detectsAsk($normalized, $mode)) {
            return ['blocked' => true, 'reason' => self::REASON_ASK];
        }

        return ['blocked' => false, 'reason' => null];
    }

    public function isBlocked(string $message, string $mode = self::MODE_CHAT): bool
    {
        return $this->inspect($message, $mode)['blocked'];
    }

    /**
     * User-facing copy when contact details are blocked on a given surface.
     */
    public static function messageFor(string $surface): string
    {
        return match ($surface) {
            'article' => 'Remove email, phone, or messaging-app contact details from this article. Keep communication on the platform.',
            'description' => 'Remove email, phone, or messaging-app contact details from the site description.',
            'revision' => 'Remove email, phone, or messaging-app contact details from these notes. Keep communication on the platform.',
            default => 'Do not share or ask for email, phone, or messaging-app details. Keep communication on the platform.',
        };
    }

    private function normalize(string $message): string
    {
        $text = mb_strtolower($message);
        // Strip zero-width / invisible chars.
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $text) ?? $text;
        // Common obfuscation tokens → canonical separators.
        $replacements = [
            '[@]' => '@',
            '[at]' => '@',
            '(at)' => '@',
            '{at}' => '@',
            ' @ ' => '@',
            ' at ' => '@',
            '[.]' => '.',
            '(dot)' => '.',
            '[dot]' => '.',
            '{dot}' => '.',
            ' dot ' => '.',
            '．' => '.',
            '＠' => '@',
        ];
        $text = strtr($text, $replacements);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function detectsShare(string $normalized, string $original, string $mode = self::MODE_CHAT): bool
    {
        if (preg_match('/mailto:\s*[^\s]+/i', $original)) {
            return true;
        }

        if ($this->detectsMessengerLink($original)) {
            return true;
        }

        // Standard email / lightly obfuscated email after normalize.
        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}\b/i', $normalized)) {
            return true;
        }

        // Spaced email fragments that survived normalize poorly: "name @ gmail . com"
        if (preg_match('/[a-z0-9._%+\-]+\s*@\s*[a-z0-9.\-]+\s*\.\s*[a-z]{2,}\b/i', $original)) {
            return true;
        }

        if ($this->detectsPhoneShare($normalized, $original, $mode)) {
            return true;
        }

        // Messaging app handles with contact intent.
        if (preg_match('/\b(?:whatsapp|telegram|signal|skype|discord|viber)\b/i', $normalized)
            && preg_match('/[@+]?\w{3,}|\d{6,}/', $normalized)
        ) {
            // Articles/descriptions may mention "Telegram marketing" — only treat
            // an explicit @handle or +number as a share. Chat stays stricter.
            $explicitHandle = (bool) preg_match(
                '/\b(?:whatsapp|telegram|signal|skype|discord|viber)\s*[:\-]?\s*[@+]\S+/i',
                $normalized
            );
            if ($mode === self::MODE_CONTENT) {
                return $explicitHandle;
            }

            if ($explicitHandle
                || preg_match('/\b(?:whatsapp|telegram|signal|skype|discord|viber)\s*[:\-]?\s*[@+]?\w{3,}/i', $normalized)
                || preg_match('/\b(?:add|message|msg|dm|contact|reach|ping|call)\b.{0,40}\b(?:whatsapp|telegram|signal|skype|discord)\b/i', $normalized)
                || preg_match('/\b(?:whatsapp|telegram|signal|skype|discord)\b.{0,40}\b(?:me|us|number|handle|id)\b/i', $normalized)
            ) {
                return true;
            }
        }

        return false;
    }

    private function detectsMessengerLink(string $original): bool
    {
        return (bool) preg_match(
            '#(?:https?:)?//(?:(?:www\.)?(?:t\.me|telegram\.me|wa\.me|api\.whatsapp\.com|chat\.whatsapp\.com|discord\.gg)|m\.me)/#i',
            $original
        ) || (bool) preg_match(
            '#(?<![\w./])(?:t\.me|telegram\.me|wa\.me|discord\.gg)/[^\s<>"\']+#i',
            $original
        ) || (bool) preg_match('#\b(?:tg|whatsapp|skype)://#i', $original);
    }

    private function detectsPhoneShare(string $normalized, string $original, string $mode): bool
    {
        if (preg_match('/tel:\s*[+\d]/i', $original)) {
            return true;
        }

        // International numbers. The lookbehind stops "2000 2024-12-15" from
        // looking like a 00-prefixed phone inside a long article.
        if (preg_match('/(?<![\d])(?:\+|00)[\d\s().\-]{8,}\d/', $original)) {
            return true;
        }

        if ($mode === self::MODE_CONTENT) {
            // Do not count every digit in a long article (years, prices, image
            // widths). Only treat a local number as a share when a phone cue
            // sits next to it — not generic words like "contact" or "number".
            return (bool) preg_match(
                '/\b(?:phone|mobile|cell|whatsapp|telegram|fax|tel)\b[\s:.\-]{0,20}[\d().\-\s]{8,}\d/i',
                $normalized
            ) || (bool) preg_match(
                '/\bcall\s+(?:us|me|now)\b[\s:.\-]{0,20}[\d().\-\s]{8,}\d/i',
                $normalized
            );
        }

        // Chat messages are short, so a 10–15 digit window is a useful filter.
        $digitsOnly = preg_replace('/\D+/', '', $original) ?? '';
        if (strlen($digitsOnly) >= 10 && strlen($digitsOnly) <= 15) {
            if (preg_match(
                '/(?:\+|00)?[\d\s().\-]{9,}\d/',
                $original
            ) && preg_match(
                '/\b(phone|mobile|cell|whatsapp|telegram|signal|call|sms|text|number|contact)\b/i',
                $normalized
            )) {
                return true;
            }
        }

        return false;
    }

    private function detectsAsk(string $normalized, string $mode = self::MODE_CHAT): bool
    {
        // Articles and site briefs often say "outside the site" or "personal
        // contact with customers". Keep those chat-only. Content mode only
        // blocks an explicit ask to move the conversation off-platform.
        $askPatterns = [
            '/\b(?:email|mail|phone|call|text|whatsapp|telegram|dm)\s+me\b/i',
            '/\b(?:send|share|give|drop)\s+me\s+(?:your\s+)?(?:e[\-\s]?mail|email|mail|phone|whatsapp|telegram)\b/i',
        ];

        if ($mode === self::MODE_CHAT) {
            $askPatterns = [
                '/\b(?:what(?:\'s| is)|whats)\s+your\s+(?:e[\-\s]?mail|email|mail|phone|number|mobile|cell|whatsapp|telegram|skype|discord|contact)\b/i',
                '/\b(?:send|share|give|drop|leave|provide|pass)\s+(?:me\s+)?(?:your\s+)?(?:e[\-\s]?mail|email|mail|phone|number|mobile|cell|whatsapp|telegram|skype|discord|contact(?:\s+details)?)\b/i',
                '/\b(?:can|could|may)\s+(?:i|we)\s+(?:have|get|get\s+your)\s+(?:your\s+)?(?:e[\-\s]?mail|email|mail|phone|number|mobile|whatsapp|telegram|contact)\b/i',
                '/\b(?:can|could)\s+(?:i|we)\s+call\s+you\b/i',
                '/\b(?:email|mail|phone|call|text|whatsapp|telegram|dm)\s+me\b/i',
                '/\b(?:your|ur)\s+(?:e[\-\s]?mail|email|mail|phone|number|whatsapp|telegram)\s*(?:please|pls)?\s*\??\s*$/i',
                '/\b(?:how\s+can\s+i\s+(?:reach|contact)\s+you|best\s+(?:way|number)\s+to\s+(?:reach|contact)\s+you)\b/i',
                '/\b(?:off\s*platform|outside\s+(?:the\s+)?(?:platform|chat|site)|personal\s+contact)\b/i',
            ];
        }

        foreach ($askPatterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }
}
