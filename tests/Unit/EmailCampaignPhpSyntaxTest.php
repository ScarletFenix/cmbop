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
            $root.'/app/Listeners/LogSentEmail.php',
            $root.'/tests/Unit/MailJobPayloadTest.php',
        ];
    }

    public function test_campaign_send_classes_parse_and_do_not_redeclare_methods(): void
    {
        foreach ($this->campaignSendSources() as $path) {
            $this->assertFileExists($path);
            $source = (string) file_get_contents($path);
            token_get_all($source, TOKEN_PARSE);

            $lint = [];
            exec('php -l '.escapeshellarg($path).' 2>&1', $lint, $lintCode);
            $this->assertSame(0, $lintCode, implode("\n", $lint));

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

        $lint = [];
        exec('php -l '.escapeshellarg($path).' 2>&1', $lint, $lintCode);
        $this->assertSame(0, $lintCode, implode("\n", $lint));

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

        $this->assertTrue((bool) preg_match(
            '/protected static function queuedMailablePayloads\(\): \?array\s*\{(.*)\n    protected static function unidentifiedPayloadCouldBeLog/s',
            $source,
            $queued
        ));
        $this->assertStringContainsString('$mailScannedOk = true;', $queued[1]);
        $this->assertStringContainsString('if ($mailNeedsScan && ! $mailScannedOk)', $queued[1]);
        $this->assertDoesNotMatchRegularExpression(
            '/hasColumn\(\$table, \'payload\'\)\) \{\s*return null;/',
            $queued[1]
        );
        $this->assertDoesNotMatchRegularExpression(
            '/catch \(\\\\Throwable\) \{\s*return null;/',
            $queued[1]
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
            $root.'/app/Listeners/LogSentEmail.php',
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
        $this->assertSame(1, preg_match_all('/function campaignDedupeKeys\b/', $payload));
        $this->assertSame(1, preg_match_all('/function modelIdentifierIds\b/', $payload));
        $this->assertTrue((bool) preg_match(
            '/public static function matchesEmailLog\(string \$payload, EmailLog \$log, bool \$requireToken = false\): bool\s*\{(.*?)\n    public static function dedupeKey/s',
            $payload,
            $matchesLog
        ));
        $this->assertStringContainsString('campaignUserIds', $matchesLog[1]);

        $center = (string) file_get_contents($files[3]);
        $this->assertTrue((bool) preg_match(
            '/protected function payloadAlreadyDelivered\(string \$payload\): bool\s*\{(.*?)\n    protected function isOneShotCampaignLog/s',
            $center,
            $already
        ));
        $this->assertStringContainsString('modelIdentifierIds', $already[1]);
        $this->assertStringContainsString('latestDeliveredForCampaignUser', $already[1]);

        $mailable = (string) file_get_contents($root.'/app/Mail/PlatformMailable.php');
        $this->assertSame(1, preg_match_all('/function alreadyHasDeliveredLog\b/', $mailable));

        $campaignMail = (string) file_get_contents($root.'/app/Mail/AudienceCampaignMail.php');
        $this->assertSame(1, preg_match_all('/function defaultDedupeKey\b/', $campaignMail));
        $this->assertStringContainsString('EmailCampaignRecipient::dedupeKey', $campaignMail);

        $inventory = (string) file_get_contents($files[2]);
        $this->assertSame(1, preg_match_all('/function recipientRowQuery\b/', $inventory));

        $center = (string) file_get_contents($files[3]);
        $this->assertSame(1, preg_match_all('/function deliveredSiblingIsSameCampaignSend\b/', $center));
        $this->assertSame(1, preg_match_all('/function campaignIdFromLog\b/', $center));
        $this->assertSame(1, preg_match_all('/function isSameAttemptLeftover\b/', $center));
        $this->assertSame(1, preg_match_all('/function payloadQueuedAtAlreadyDelivered\b/', $center));

        $log = (string) file_get_contents($root.'/app/Models/EmailLog.php');
        $this->assertSame(1, preg_match_all('/function latestDeliveredForCampaignUser\b/', $log));
        $this->assertSame(1, preg_match_all('/function pendingUserIdsForCampaign\b/', $log));
        $this->assertSame(1, preg_match_all('/function campaignUserIds\b/', $log));
        $this->assertSame(1, preg_match_all('/function openForCampaignUser\b/', $log));

        $sent = (string) file_get_contents($root.'/app/Listeners/LogSentEmail.php');
        $this->assertSame(1, preg_match_all('/function closeSiblingCampaignLogs\b/', $sent));

        $this->assertSame(1, preg_match_all('/function openSiblingCampaignLogs\b/', $mailable));
        $this->assertTrue((bool) preg_match(
            '/public static function campaignUserIds\(self \$log\): array\s*\{(.*?)\n    \/\*\*/s',
            $log,
            $ids
        ));
        $this->assertStringContainsString('template_key', $ids[1]);
        $this->assertStringContainsString('return [0, 0];', $ids[1]);
        $this->assertStringNotContainsString(
            'return static::query()',
            $ids[1],
            'campaignUserIds must parse identity, not return open leftover rows'
        );
        $this->assertSame(
            0,
            preg_match_all('/->limit\(100\)/', $log),
            'latestDeliveredForCampaignUser must not cap a global 100-row scan'
        );

        $this->assertTrue((bool) preg_match(
            '/protected function isDuplicate\(string \$key\): bool\s*\{(.*?)\n    protected function brand/s',
            $mailable,
            $dup
        ));
        $this->assertStringContainsString("where('sent_at', '>=', \$cutoff)", $dup[1]);
        $this->assertStringContainsString("whereNull('sent_at')", $dup[1]);
        $this->assertStringNotContainsString("where('created_at', '>=', now()->subMinutes(\$minutes))", $dup[1]);

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
        $this->assertStringContainsString('pendingUserIdsForCampaign', $expire[1]);
        $this->assertSame(1, preg_match_all('/function inFlightCampaignMailUserIds\b/', $model));
        $this->assertSame(1, preg_match_all('/function collectCampaignMailUserIdsFromTable\b/', $model));
        $this->assertSame(1, preg_match_all('/function campaignLogUserIdsForStatus\b/', $model));
        $this->assertSame(1, preg_match_all('/function healQueuedRecipientsWithTerminalLog\b/', $model));
        $this->assertSame(1, preg_match_all('/function reconcileOneQueuedRecipientFromLogs\b/', $model));
        $this->assertTrue((bool) preg_match(
            '/protected static function reconcileQueuedRecipientsFromLogs\(int \$staleMinutes = 2\): void\s*\{(.*?)\n    \/\*\*/s',
            $model,
            $reconcileParent
        ));
        $this->assertStringContainsString('reconcileOneQueuedRecipientFromLogs', $reconcileParent[1]);
        $this->assertStringNotContainsString('if ($logs->isEmpty())', $reconcileParent[1]);
        $this->assertTrue((bool) preg_match(
            '/protected static function reconcileOneQueuedRecipientFromLogs\([\s\S]*?\): void\s*\{(.*?)\n    \/\*\*/s',
            $model,
            $reconcile
        ));
        $this->assertStringContainsString('latestDeliveredForCampaignUser', $reconcile[1]);
        $this->assertSame(0, preg_match_all('/function syncQueuedRecipientsWithAttachedLogs\b/', $model));
        $this->assertSame(1, preg_match_all('/function failPendingLogsForStaleRecipients\b/', $model));
        $this->assertSame(1, preg_match_all('/function expireOrphanedPendingLogs\b/', $model));
        $this->assertTrue((bool) preg_match(
            '/protected static function isCampaignEmailLog\(EmailLog \$log\): bool\s*\{(.*?)\n    \/\*\*/s',
            $model,
            $campaignLog
        ));
        $this->assertStringContainsString('audience_campaign|', $campaignLog[1]);
        $this->assertStringContainsString('notification_type', $campaignLog[1]);
        $this->assertStringContainsString("str_starts_with((string) \$log->template_key, 'audience_campaign')", $campaignLog[1]);
        $this->assertSame(1, preg_match_all('/function queuedMailablePayloads\b/', $model));
        $this->assertSame(1, preg_match_all('/function unidentifiedPayloadCouldBeLog\b/', $model));
        $this->assertSame(1, preg_match_all('/function mailConnectionIsInline\b/', $model));
        $this->assertTrue((bool) preg_match(
            '/protected static function queuedMailablePayloads\(\): \?array\s*\{(.*)\n    protected static function unidentifiedPayloadCouldBeLog/s',
            $model,
            $queuedMail
        ));
        $this->assertStringContainsString('$mailScannedOk = true;', $queuedMail[1]);
        $this->assertStringContainsString('if ($mailNeedsScan && ! $mailScannedOk)', $queuedMail[1]);
        $this->assertDoesNotMatchRegularExpression(
            '/hasColumn\(\$table, \'payload\'\)\) \{\s*return null;/',
            $queuedMail[1]
        );
        $this->assertTrue((bool) preg_match(
            '/protected static function recoverStalledLocked\(int \$staleMinutes\): int\s*\{(.*?)\n    protected static function reclaimOrphanedQueuedRecipients/s',
            $model,
            $recover
        ));
        $this->assertNotFalse(strpos($recover[1], 'hasQueuedSendJob'));
        $this->assertNotFalse(strpos($recover[1], 'currentFailStreak()'));
        $this->assertLessThan(
            strpos($recover[1], 'currentFailStreak()'),
            strpos($recover[1], 'hasQueuedSendJob'),
            'recover must see an in-flight send job before fail-streak give-up'
        );

        $this->assertTrue((bool) preg_match(
            '/protected static function expireOrphanedQueuedRecipients\(\): void\s*\{(.*?)\n    \/\*\*/s',
            $model,
            $expire
        ));
        $this->assertStringContainsString('inFlightCampaignMailUserIds', $expire[1]);
        $this->assertStringContainsString('deliveredUserIdsForCampaign', $expire[1]);
        $this->assertStringContainsString('pendingUserIdsForCampaign', $expire[1]);
        $this->assertTrue((bool) preg_match(
            '/protected static function healQueuedRecipientsWithTerminalLog\(\): array\s*\{(.*?)\n    \/\*\*/s',
            $model,
            $heal
        ));
        $this->assertSame(0, substr_count($heal[1], "return;\n"), 'healQueued must return [] not void');
        $this->assertStringContainsString('meta->campaign_id', $heal[1]);
        $this->assertStringContainsString('campaignUserIds', $heal[1]);
        $this->assertTrue((bool) preg_match(
            '/protected static function failPendingLogsForStaleRecipients\(\): void\s*\{(.*?)\n    \/\*\*/s',
            $model,
            $failPending
        ));
        $this->assertStringContainsString('inFlightCampaignMailUserIds', $failPending[1]);
        $this->assertStringNotContainsString('$expired', $failPending[1]);
        $this->assertStringContainsString("'updated_at'", $failPending[1]);
        $this->assertStringContainsString('campaignUserIds', $failPending[1]);
        $this->assertTrue((bool) preg_match(
            '/protected static function reconcileOneQueuedRecipientFromLogs\(.*?\): void\s*\{(.*?)\n    \/\*\*/s',
            $model,
            $reconcileOne
        ));
        $this->assertSame(
            0,
            preg_match_all('/^\s*continue;\s*$/m', $reconcileOne[1]),
            'reconcileOneQueuedRecipientFromLogs is not a loop — continue fatals class load'
        );

        $center = (string) file_get_contents($files[3]);
        $this->assertSame(1, preg_match_all('/function markRetriedMailLogsPending\b/', $center));
        $this->assertSame(1, preg_match_all('/function failedJobMatchesLog\b/', $center));
        $this->assertSame(1, preg_match_all('/function leftoverOwnsFailedJob\b/', $center));
        $this->assertSame(1, preg_match_all('/function shouldSkipRetryForClosedLeftover\b/', $center));
        $this->assertTrue((bool) preg_match(
            '/protected function requeueFailedCampaignRecipient\(EmailLog \$log\): void\s*\{(.*)\n    protected function failedJobUuidForLog/s',
            $center,
            $requeue
        ));
        $this->assertStringContainsString('clearFailStreak()', $requeue[1]);
        $this->assertTrue((bool) preg_match(
            '/protected function leftoverOwnsFailedJob\(EmailLog \$leftover, string \$payload\): bool\s*\{(.*?)\n    protected function closeFailedLogAlreadyDelivered/s',
            $center,
            $owns
        ));
        $this->assertStringContainsString('campaignUserIds', $owns[1]);
        $this->assertStringContainsString('campaignDedupeKeys', $owns[1]);

        $payloadTest = (string) file_get_contents($files[5]);
        $this->assertSame(1, preg_match_all(
            '/function test_matches_email_log_require_token_rejects_unidentified_payload\b/',
            $payloadTest
        ));
        $this->assertSame(1, preg_match_all(
            '/function test_contains_campaign_mail_matches_model_identifier_without_dedupe_key\b/',
            $payloadTest
        ));
    }

    public function test_transactional_is_duplicate_holds_when_email_logs_cannot_be_read(): void
    {
        $path = dirname(__DIR__, 2).'/app/Mail/PlatformMailable.php';
        $source = (string) file_get_contents($path);
        $this->assertTrue((bool) preg_match(
            '/protected function isDuplicate\(string \$key\): bool\s*\{(.*?)\n    protected function brand/s',
            $source,
            $dup
        ));
        $this->assertStringContainsString('holding send', $dup[1]);
        $this->assertStringNotContainsString('allowing send', $dup[1]);
        $this->assertStringContainsString('throw $e;', $dup[1]);
    }

    public function test_leftover_owns_failed_job_does_not_treat_generic_key_as_ownership(): void
    {
        $path = dirname(__DIR__, 2).'/app/Http/Controllers/Admin/EmailCenterController.php';
        $center = (string) file_get_contents($path);
        $this->assertTrue((bool) preg_match(
            '/protected function leftoverOwnsFailedJob\(EmailLog \$leftover, string \$payload\): bool\s*\{(.*?)\n    protected function closeFailedLogAlreadyDelivered/s',
            $center,
            $owns
        ));
        $this->assertStringContainsString('audience_campaign|', $owns[1]);
        $this->assertStringContainsString('campaignMailUserIds', $owns[1]);
        $this->assertTrue((bool) preg_match(
            '/protected function closeFailedLogAlreadyDelivered\(EmailLog \$log\): bool\s*\{(.*?)\n    protected function deliveredSiblingIsSameCampaignSend/s',
            $center,
            $close
        ));
        $this->assertStringContainsString('audience_campaign|', $close[1]);
    }
}
