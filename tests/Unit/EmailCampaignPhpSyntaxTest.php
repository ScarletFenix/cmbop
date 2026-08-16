<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class EmailCampaignPhpSyntaxTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function campaignSendSources(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            $root.'/app/Models/EmailCampaign.php',
            $root.'/app/Services/AudienceInventoryService.php',
            $root.'/app/Support/MailJobPayload.php',
            $root.'/app/Http/Controllers/Admin/EmailCenterController.php',
            $root.'/app/Models/EmailLog.php',
            $root.'/tests/Unit/MailJobPayloadTest.php',
        ];
    }

    public function test_campaign_send_classes_parse_and_do_not_redeclare_methods(): void
    {
        foreach ($this->campaignSendSources() as $path) {
            $this->assertFileExists($path);
            $source = (string) file_get_contents($path);
            token_get_all($source, TOKEN_PARSE);

            preg_match_all('/function\s+(\w+)\s*\(/', $source, $matches);
            $counts = array_count_values($matches[1] ?? []);
            foreach ($counts as $name => $times) {
                $this->assertSame(1, $times, basename($path).' redeclares '.$name);
            }
        }
    }

    public function test_email_campaign_model_parses_and_try_is_inside_the_connection_loop(): void
    {
        $path = dirname(__DIR__, 2).'/app/Models/EmailCampaign.php';
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);
        token_get_all($source, TOKEN_PARSE);

        $this->assertMatchesRegularExpression(
            '/foreach \(self::sendJobQueueConnections\(\) as \$connection\) \{.*?try \{/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/foreach \(self::sendJobQueueConnections\(\) as \$connection\) \{[^;]*\} catch \(/s',
            $source
        );

        $this->assertTrue((bool) preg_match(
            '/protected static function hasQueuedSendJob\(int \$campaignId\): bool\s*\{(.*?)\n    protected static function sendJobQueueConnections/s',
            $source,
            $matches
        ));
        $this->assertSame(
            1,
            substr_count($matches[1], 'try {'),
            'hasQueuedSendJob must not leave an extra unclosed try around the connection loop'
        );
        $this->assertStringContainsString('$scanFailed = true;', $matches[1]);
        $this->assertStringContainsString('$scannedOk = true;', $matches[1]);
        $this->assertStringContainsString('return $scanFailed && ! $scannedOk;', $matches[1]);

        $this->assertTrue((bool) preg_match(
            '/protected static function inFlightCampaignMailUserIds\(int \$campaignId(?:, bool \$includeFailedJobs = true)?\): \?array\s*\{(.*?)\n    protected static function collectCampaignMailUserIdsFromTable/s',
            $source,
            $inFlight
        ));
        $this->assertStringContainsString('$mailScannedOk = true;', $inFlight[1]);
        $this->assertStringContainsString('if ($mailNeedsScan && ! $mailScannedOk)', $inFlight[1]);
        $this->assertStringNotContainsString('$mailFailed', $inFlight[1]);
        $this->assertDoesNotMatchRegularExpression(
            '/hasColumn\(\$table, \'payload\'\)\) \{\s*return null;/',
            $inFlight[1]
        );
    }

    public function test_merge_sensitive_campaign_files_parse_without_duplicate_methods(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            $root.'/app/Models/EmailCampaign.php',
            $root.'/app/Support/MailJobPayload.php',
            $root.'/app/Services/AudienceInventoryService.php',
            $root.'/app/Http/Controllers/Admin/EmailCenterController.php',
            $root.'/app/Models/EmailLog.php',
            $root.'/tests/Unit/MailJobPayloadTest.php',
            $root.'/tests/Feature/AdminCampaignsTest.php',
        ];

        foreach ($files as $path) {
            $this->assertFileExists($path);
            token_get_all((string) file_get_contents($path), TOKEN_PARSE);
        }

        $payload = (string) file_get_contents($files[1]);
        $this->assertSame(1, preg_match_all('/function containsCampaignId\b/', $payload));
        $this->assertSame(1, preg_match_all('/function containsSendCampaignJob\b/', $payload));
        $this->assertSame(1, preg_match_all('/function containsCampaignMail\b/', $payload));
        $this->assertSame(1, preg_match_all('/function campaignMailUserIds\b/', $payload));
        $this->assertSame(1, preg_match_all('/function containsEmailCampaignModel\b/', $payload));
        $this->assertSame(1, preg_match_all('/function modelIdentifierIds\b/', $payload));

        $inventory = (string) file_get_contents($files[2]);
        $this->assertSame(1, preg_match_all('/function recipientRowQuery\b/', $inventory));

        $log = (string) file_get_contents($root.'/app/Models/EmailLog.php');
        $this->assertSame(1, preg_match_all('/function latestDeliveredForCampaignUser\b/', $log));
        $this->assertSame(1, preg_match_all('/function pendingUserIdsForCampaign\b/', $log));
        $this->assertSame(1, preg_match_all('/function deliveredUserIdsForCampaign\b/', $log));
        $this->assertSame(1, preg_match_all('/function campaignUserIds\b/', $log));

        $model = (string) file_get_contents($files[0]);
        $this->assertSame(1, preg_match_all('/function reclaimOrphanedQueuedRecipients\b/', $model));
        $this->assertTrue((bool) preg_match(
            '/protected static function reclaimOrphanedQueuedRecipients\(self \$campaign\): int\s*\{(.*?)\n    \/\*\*/s',
            $model,
            $reclaim
        ));
        $this->assertGreaterThanOrEqual(1, substr_count($reclaim[1], 'deliveredUserIdsForCampaign'));
        $this->assertTrue((bool) preg_match(
            '/protected static function expireOrphanedQueuedRecipients\(\): void\s*\{(.*?)\n    \/\*\*/s',
            $model,
            $expire
        ));
        $this->assertStringContainsString('deliveredUserIdsForCampaign', $expire[1]);
        $this->assertSame(1, preg_match_all('/function inFlightCampaignMailUserIds\b/', $model));
        $this->assertSame(1, preg_match_all('/function healQueuedRecipientsWithTerminalLog\b/', $model));
        $this->assertSame(0, preg_match_all('/function syncQueuedRecipientsWithAttachedLogs\b/', $model));
        $this->assertSame(1, preg_match_all('/function failPendingLogsForStaleRecipients\b/', $model));
        $this->assertSame(1, preg_match_all('/function expireOrphanedPendingLogs\b/', $model));
        $this->assertSame(1, preg_match_all('/function queuedMailablePayloads\b/', $model));
        $this->assertTrue((bool) preg_match(
            '/protected static function healQueuedRecipientsWithTerminalLog\(\): array\s*\{(.*?)\n    \/\*\*/s',
            $model,
            $heal
        ));
        $this->assertSame(
            0,
            preg_match_all('/^\s*return;\s*$/m', $heal[1]),
            'healQueuedRecipientsWithTerminalLog must return [] — a bare return is a TypeError'
        );
        $this->assertStringContainsString('dedupeKey(', $heal[1]);
        $this->assertStringContainsString('user_id', $heal[1]);
        $this->assertStringContainsString('STATUS_DELIVERED', $heal[1]);
        $this->assertStringContainsString('latestDeliveredForCampaignUser', $heal[1]);
        $this->assertSame(1, substr_count($heal[1], '$healedCampaigns = []'));
        $this->assertSame(1, substr_count($heal[1], 'return array_keys($healedCampaigns);'));
        $this->assertTrue(
            (bool) preg_match(
                '/expireOrphanedPendingLogs\(\);.*?healQueuedRecipientsWithTerminalLog\(\)/s',
                $model
            ),
            'heal must run again after pending-log expire so a leftover FK can sync this pass'
        );
        $this->assertTrue((bool) preg_match(
            '/protected static function queuedMailablePayloads\(\): \?array\s*\{(.*)\n    \}\n\}\n/s',
            $model,
            $queued
        ));
        $this->assertStringContainsString('$mailScannedOk = true;', $queued[1]);
        $this->assertStringContainsString('if ($mailNeedsScan && ! $mailScannedOk)', $queued[1]);
        $this->assertDoesNotMatchRegularExpression(
            '/hasColumn\(\$table, \'payload\'\)\) \{\s*return null;/',
            $queued[1]
        );

        $payloadTest = (string) file_get_contents($files[5]);
        $this->assertStringContainsString(
            'function test_contains_campaign_mail_matches_model_identifier_without_dedupe_key',
            $payloadTest
        );
    }
}
