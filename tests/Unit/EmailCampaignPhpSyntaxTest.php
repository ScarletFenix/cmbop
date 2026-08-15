<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class EmailCampaignPhpSyntaxTest extends TestCase
{
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
    }

    public function test_merge_sensitive_campaign_files_parse_without_duplicate_methods(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            $root.'/app/Models/EmailCampaign.php',
            $root.'/app/Support/MailJobPayload.php',
            $root.'/app/Services/AudienceInventoryService.php',
        ];

        foreach ($files as $path) {
            $this->assertFileExists($path);
            token_get_all((string) file_get_contents($path), TOKEN_PARSE);
        }

        $payload = (string) file_get_contents($files[1]);
        $this->assertSame(1, preg_match_all('/function containsCampaignId\b/', $payload));
        $this->assertSame(1, preg_match_all('/function containsSendCampaignJob\b/', $payload));

        $inventory = (string) file_get_contents($files[2]);
        $this->assertSame(1, preg_match_all('/function recipientRowQuery\b/', $inventory));
    }
}
