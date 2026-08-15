<?php

namespace Tests\Unit;

use App\Support\MailJobPayload;
use Tests\TestCase;

class MailJobPayloadTest extends TestCase
{
    public function test_contains_send_campaign_job_matches_json_escaped_payload(): void
    {
        $raw = 'O:32:"App\\Jobs\\SendEmailCampaignJob":1:{s:10:"campaignId";i:12;}';
        $json = json_encode([
            'displayName' => 'App\\Jobs\\SendEmailCampaignJob',
            'data' => [
                'commandName' => 'App\\Jobs\\SendEmailCampaignJob',
                'command' => $raw,
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertTrue(MailJobPayload::containsSendCampaignJob($raw, 12));
        $this->assertTrue(MailJobPayload::containsSendCampaignJob($json, 12));
        $this->assertFalse(MailJobPayload::containsSendCampaignJob($json, 1));
        $this->assertFalse(MailJobPayload::containsSendCampaignJob($json, 123));
        $this->assertFalse(MailJobPayload::containsSendCampaignJob('SendEmailCampaignJob only', 12));
    }
}
