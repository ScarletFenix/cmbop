<?php

namespace Tests\Unit;

use App\Mail\WelcomeEmail;
use App\Models\EmailLog;
use App\Support\MailJobPayload;
use Tests\TestCase;

class MailJobPayloadTest extends TestCase
{
    public function test_contains_send_campaign_job_matches_escaped_and_raw_payloads(): void
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
        $this->assertTrue(MailJobPayload::containsSendCampaignJob(
            '{"displayName":"App\\\\Jobs\\\\SendEmailCampaignJob","campaignId":12}',
            12
        ));
        $this->assertFalse(MailJobPayload::containsSendCampaignJob(
            '{"displayName":"App\\\\Jobs\\\\SendEmailCampaignJob","campaignId":123}',
            12
        ));
        $this->assertTrue(MailJobPayload::containsCampaignId($json, 12));
        $this->assertFalse(MailJobPayload::containsCampaignId($json, 123));
    }

    public function test_contains_campaign_mail_matches_dedupe_token_without_crossing_ids(): void
    {
        $payload = json_encode([
            'displayName' => 'App\\Mail\\AudienceCampaignMail',
            'data' => [
                'commandName' => 'Illuminate\\Mail\\SendQueuedMailable',
                'command' => 's:32:"audience_campaign:12:user:34"',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertTrue(MailJobPayload::containsCampaignMail($payload, 12));
        $this->assertFalse(MailJobPayload::containsCampaignMail($payload, 123));
        $this->assertFalse(MailJobPayload::containsCampaignMail($payload, 1));
        $this->assertSame([34], MailJobPayload::campaignMailUserIds($payload, 12));
        $this->assertSame([], MailJobPayload::campaignMailUserIds($payload, 123));
    }

    public function test_matches_email_log_require_token_rejects_unidentified_payload(): void
    {
        $log = new EmailLog([
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'to_email' => 'customer@example.com',
            'dedupe_key' => 'welcome:1',
        ]);
        $unidentified = json_encode([
            'displayName' => WelcomeEmail::class,
            'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
        ], JSON_THROW_ON_ERROR);
        $identified = json_encode([
            'displayName' => WelcomeEmail::class,
            'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
            'to' => 'customer@example.com',
        ], JSON_THROW_ON_ERROR);

        $this->assertTrue(MailJobPayload::matchesEmailLog($unidentified, $log));
        $this->assertFalse(MailJobPayload::matchesEmailLog($unidentified, $log, requireToken: true));
        $this->assertTrue(MailJobPayload::matchesEmailLog($identified, $log, requireToken: true));
    }
}
