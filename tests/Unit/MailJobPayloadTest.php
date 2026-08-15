<?php

namespace Tests\Unit;

use App\Support\MailJobPayload;
use Tests\TestCase;

class MailJobPayloadTest extends TestCase
{
    public function test_contains_campaign_job_reads_json_escaped_command(): void
    {
        $payload = json_encode([
            'displayName' => 'App\\Jobs\\SendEmailCampaignJob',
            'data' => [
                'commandName' => 'App\\Jobs\\SendEmailCampaignJob',
                'command' => 'O:32:"App\\Jobs\\SendEmailCampaignJob":2:{s:10:"campaignId";i:12;s:10:"failStreak";i:0;}',
            ],
        ]);

        $this->assertIsString($payload);
        $this->assertStringNotContainsString('campaignId";i:12;', $payload);
        $this->assertTrue(MailJobPayload::containsCampaignJob($payload, 12));
        $this->assertFalse(MailJobPayload::containsCampaignJob($payload, 1));
        $this->assertFalse(MailJobPayload::containsCampaignJob($payload, 120));
    }

    public function test_contains_campaign_job_reads_raw_serialized_token(): void
    {
        $payload = 'O:32:"App\\Jobs\\SendEmailCampaignJob":1:{s:10:"campaignId";i:4;}';

        $this->assertTrue(MailJobPayload::containsCampaignJob($payload, 4));
        $this->assertFalse(MailJobPayload::containsCampaignJob($payload, 40));
    }
}
