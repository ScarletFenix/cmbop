<?php

namespace App\Services;

/**
 * Detects sharing or requesting personal contact details in order chat.
 */
class OrderChatContactGuard
{
    public const REASON_SHARE = 'contact_share';

    public const REASON_ASK = 'contact_ask';

    /**
     * @return array{blocked: bool, reason: ?string}
     */
    public function inspect(string $message): array
    {
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return ['blocked' => false, 'reason' => null];
        }

        if ($this->detectsShare($normalized, $message)) {
            return ['blocked' => true, 'reason' => self::REASON_SHARE];
        }

        if ($this->detectsAsk($normalized)) {
            return ['blocked' => true, 'reason' => self::REASON_ASK];
        }

        return ['blocked' => false, 'reason' => null];
    }

    public function isBlocked(string $message): bool
    {
        return $this->inspect($message)['blocked'];
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

    private function detectsShare(string $normalized, string $original): bool
    {
        if (preg_match('/mailto:\s*[^\s]+/i', $original)) {
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

        // Phone-like sequences (international / local), ignore short order numbers.
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
            // Bare international-looking numbers with + or many separators.
            if (preg_match('/(?:\+|00)\d[\d\s().\-]{8,}\d/', $original)) {
                return true;
            }
        }

        // Messaging app handles with contact intent.
        if (preg_match('/\b(?:whatsapp|telegram|signal|skype|discord|viber)\b/i', $normalized)
            && preg_match('/[@+]?\w{3,}|\d{6,}/', $normalized)
        ) {
            // Avoid blocking "please check WhatsApp web article" style without a handle/number.
            if (preg_match('/\b(?:whatsapp|telegram|signal|skype|discord|viber)\s*[:\-]?\s*[@+]?\w{3,}/i', $normalized)
                || preg_match('/\b(?:add|message|msg|dm|contact|reach|ping|call)\b.{0,40}\b(?:whatsapp|telegram|signal|skype|discord)\b/i', $normalized)
                || preg_match('/\b(?:whatsapp|telegram|signal|skype|discord)\b.{0,40}\b(?:me|us|number|handle|id)\b/i', $normalized)
            ) {
                return true;
            }
        }

        return false;
    }

    private function detectsAsk(string $normalized): bool
    {
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

        foreach ($askPatterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }
}
