<?php

namespace App\Support;

use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\User;
use Carbon\Carbon;

class MailJobPayload
{
    public static function isQueuedMailable(string $payload): bool
    {
        return str_contains($payload, 'SendQueuedMailable');
    }

    public static function containsMailable(string $payload, string $class): bool
    {
        if ($class === '') {
            return false;
        }

        return str_contains($payload, $class)
            || str_contains($payload, str_replace('\\', '\\\\', $class));
    }

    /**
     * True when this payload is a SendEmailCampaignJob for exactly $campaignId.
     * Covers raw PHP serialization, JSON-escaped queue rows, and a decoded
     * command string. `i:12;` / `"campaignId":12` must not match 123.
     */
    public static function containsSendCampaignJob(string $payload, int $campaignId): bool
    {
        if ($campaignId < 1 || ! str_contains($payload, 'SendEmailCampaignJob')) {
            return false;
        }

        return self::containsCampaignId($payload, $campaignId);
    }

    /**
     * True when this payload is an AudienceCampaignMail for exactly $campaignId.
     * `audience_campaign:12:user:` must not match campaign 123.
     * Production SerializesModels stores the campaign as a ModelIdentifier
     * (`App\Models\EmailCampaign` + id), not a `campaignId` property.
     */
    public static function containsCampaignMail(string $payload, int $campaignId): bool
    {
        if ($campaignId < 1) {
            return false;
        }

        if (self::campaignMailUserIds($payload, $campaignId) !== []) {
            return true;
        }

        return str_contains($payload, 'AudienceCampaignMail')
            && (self::containsCampaignId($payload, $campaignId)
                || self::containsEmailCampaignModel($payload, $campaignId));
    }

    /**
     * User ids addressed by an in-flight campaign mailable.
     *
     * @return list<int>
     */
    public static function campaignMailUserIds(string $payload, int $campaignId): array
    {
        if ($campaignId < 1) {
            return [];
        }

        $ids = [];
        if (preg_match_all(
            '/audience_campaign:'.$campaignId.':user:(\d+)/',
            $payload,
            $matches
        )) {
            $ids = array_map('intval', $matches[1]);
        }

        // Queued mail without a stamped dedupeKey still serializes the
        // recipient as a User ModelIdentifier. Missing that looked like
        // "nothing in flight" and reclaim doubled the send.
        if ($ids === []
            && str_contains($payload, 'AudienceCampaignMail')
            && self::containsEmailCampaignModel($payload, $campaignId)) {
            $ids = self::modelIdentifierIds($payload, User::class);
        }

        return array_values(array_unique(array_filter(
            $ids,
            static fn (int $id): bool => $id > 0
        )));
    }

    /**
     * True when SerializesModels stored EmailCampaign id $campaignId.
     * `i:12;` must not match campaign 123.
     */
    public static function containsEmailCampaignModel(string $payload, int $campaignId): bool
    {
        return self::containsModelIdentifier($payload, EmailCampaign::class, $campaignId);
    }

    /**
     * Eloquent ids stored as ModelIdentifier for $class.
     *
     * @return list<int>
     */
    public static function modelIdentifierIds(string $payload, string $class): array
    {
        if ($class === '') {
            return [];
        }

        $ids = [];
        $quoted = preg_quote($class, '/');
        foreach (self::payloadHaystacks($payload) as $haystack) {
            if (preg_match_all('/'.$quoted.'";s:2:"id";i:(\d+);/', $haystack, $matches)) {
                foreach ($matches[1] as $id) {
                    $ids[] = (int) $id;
                }
            }
            if (preg_match_all('/'.$quoted.'";s:2:"id";s:\d+:"(\d+)";/', $haystack, $matches)) {
                foreach ($matches[1] as $id) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique(array_filter(
            $ids,
            static fn (int $id): bool => $id > 0
        )));
    }

    /**
     * audience_campaign:{id}:user:{id} keys encoded in this jobs payload.
     * Covers a stamped dedupeKey and SerializesModels ModelIdentifiers
     * when the mailable was queued without that token.
     *
     * @return list<string>
     */
    public static function campaignDedupeKeys(string $payload): array
    {
        $keys = [];
        foreach (self::payloadHaystacks($payload) as $haystack) {
            if (preg_match_all('/audience_campaign:(\d+):user:(\d+)/', $haystack, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $keys[] = 'audience_campaign:'.$match[1].':user:'.$match[2];
                }
            }
        }

        if ($keys === []
            && str_contains($payload, 'AudienceCampaignMail')) {
            foreach (self::modelIdentifierIds($payload, EmailCampaign::class) as $campaignId) {
                foreach (self::modelIdentifierIds($payload, User::class) as $userId) {
                    $keys[] = 'audience_campaign:'.$campaignId.':user:'.$userId;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Match campaign 12 without treating i:123; or "campaignId":123 as a hit.
     * Database-queue rows JSON-escape the serialized command.
     */
    public static function containsCampaignId(string $payload, int $campaignId): bool
    {
        if ($campaignId < 1) {
            return false;
        }

        $id = (string) $campaignId;
        if (preg_match('/s:10:\\\\?"campaignId\\\\?";i:'.$id.';/', $payload)) {
            return true;
        }

        if (preg_match('/"campaignId":'.$id.'(?!\d)/', $payload)) {
            return true;
        }

        $decoded = json_decode($payload, true);
        $command = is_array($decoded) ? ($decoded['data']['command'] ?? null) : null;

        return is_string($command) && (bool) preg_match('/s:10:"campaignId";i:'.$id.';/', $command);
    }

    /**
     * @return list<string>
     */
    protected static function payloadHaystacks(string $payload): array
    {
        $haystacks = [$payload];
        $decoded = json_decode($payload, true);
        $command = is_array($decoded) ? ($decoded['data']['command'] ?? null) : null;
        if (is_string($command) && $command !== '') {
            $haystacks[] = $command;
        }

        return $haystacks;
    }

    protected static function containsModelIdentifier(string $payload, string $class, int $id): bool
    {
        if ($id < 1 || $class === '') {
            return false;
        }

        $idStr = (string) $id;
        $needles = [
            $class.'";s:2:"id";i:'.$idStr.';',
            $class.'";s:2:"id";s:'.strlen($idStr).':"'.$idStr.'";',
        ];

        foreach (self::payloadHaystacks($payload) as $haystack) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Match a recipient or dedupe key without treating "welcome:1" as "welcome:10".
     */
    public static function containsToken(string $payload, string $token): bool
    {
        $token = trim($token);
        if ($token === '' || strcasecmp($token, 'unknown') === 0) {
            return false;
        }

        if (str_contains($payload, json_encode($token, JSON_UNESCAPED_SLASHES))) {
            return true;
        }

        return str_contains($payload, 's:'.strlen($token).':"'.$token.'"');
    }

    public static function looksIdentified(string $payload): bool
    {
        if (str_contains($payload, 'dedupe_key') || str_contains($payload, 'dedupeKey')) {
            return true;
        }

        return self::emails($payload) !== [];
    }

    public static function matchesEmailLog(string $payload, EmailLog $log, bool $requireToken = false): bool
    {
        if (! self::isQueuedMailable($payload)) {
            return false;
        }

        $catalog = EmailCatalog::get((string) $log->template_key) ?? [];
        $class = (string) ($log->mailable ?: ($catalog['mailable'] ?? ''));
        if ($class !== '' && ! self::containsMailable($payload, $class)) {
            return false;
        }

        if (self::containsToken($payload, (string) $log->to_email)
            || self::containsToken($payload, (string) $log->dedupe_key)) {
            return true;
        }

        // AudienceCampaignMail without a stamped dedupeKey still serializes
        // EmailCampaign + User ModelIdentifiers. Expire used requireToken
        // only, so a 72h pending log was failed beside the live job and a
        // later retry doubled the send. Canonical-key-only matching missed
        // leftover generic-key Email Center rows that store the pair in meta.
        [$campaignId, $userId] = EmailLog::campaignUserIds($log);
        if ($campaignId > 0 && $userId > 0
            && in_array($userId, self::campaignMailUserIds($payload, $campaignId), true)) {
            return true;
        }

        if ($requireToken) {
            return false;
        }

        $to = (string) $log->to_email;
        $dedupe = (string) $log->dedupe_key;
        $logHasIdentity = ($to !== '' && strcasecmp($to, 'unknown') !== 0) || $dedupe !== '';

        return ! ($logHasIdentity && self::looksIdentified($payload));
    }

    public static function dedupeKey(string $payload): ?string
    {
        $decoded = json_decode($payload, true);
        $command = is_array($decoded) ? ($decoded['data']['command'] ?? null) : null;
        $haystacks = array_values(array_filter([
            is_string($command) ? $command : null,
            $payload,
        ]));

        foreach ($haystacks as $haystack) {
            if (preg_match('/s:9:\\\\?"dedupeKey\\\\?";s:\d+:\\\\?"([^\\\\"]+)\\\\?"/', $haystack, $matches)) {
                return $matches[1];
            }

            if (preg_match('/s:10:\\\\?"dedupe_key\\\\?";s:\d+:\\\\?"([^\\\\"]+)\\\\?"/', $haystack, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function emails(string $payload): array
    {
        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $payload, $matches);

        return array_values(array_unique(array_map('strval', $matches[0] ?? [])));
    }

    public static function queuedAt(string $payload): ?Carbon
    {
        if (! preg_match('/s:8:\\\\?"queuedAt\\\\?";s:\d+:\\\\?"([^\\\\"]+)\\\\?"/', $payload, $matches)) {
            return null;
        }

        try {
            return Carbon::parse($matches[1]);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function refreshQueuedAt(string $payload, ?\DateTimeInterface $at = null): string
    {
        $fresh = Carbon::parse($at ?? now())->toIso8601String();
        $replacement = 's:8:"queuedAt";s:'.strlen($fresh).':"'.$fresh.'"';

        $decoded = json_decode($payload, true);
        $command = is_array($decoded) ? ($decoded['data']['command'] ?? null) : null;
        if (is_string($command) && str_contains($command, 'queuedAt')) {
            $decoded['data']['command'] = preg_replace(
                '/s:8:"queuedAt";(?:N|s:\d+:"[^"]*")/',
                $replacement,
                $command,
                1
            ) ?? $command;

            return json_encode($decoded) ?: $payload;
        }

        $updated = preg_replace(
            '/s:8:\\\\?"queuedAt\\\\?";(?:N|s:\d+:\\\\?"[^\\\\"]*\\\\?")/',
            $replacement,
            $payload,
            1
        );

        return is_string($updated) ? $updated : $payload;
    }
}
