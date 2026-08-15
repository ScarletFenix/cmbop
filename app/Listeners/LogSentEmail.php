<?php

namespace App\Listeners;

use App\Mail\AudienceCampaignMail;
use App\Mail\PlatformMailable;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailLog;
use App\Support\EmailCatalog;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LogSentEmail
{
    public function handle(MessageSent $event): void
    {
        try {
            $this->record($event);
        } catch (\Throwable $e) {
            Log::error('Failed to record sent email', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function record(MessageSent $event): void
    {
        $message = $event->message;
        $to = $this->firstAddress($message->getTo());
        $from = $this->firstAddress($message->getFrom());

        $mailable = null;
        $mailableInstance = null;
        if (isset($event->data) && is_array($event->data)) {
            foreach ($event->data as $value) {
                if (is_object($value) && is_subclass_of($value, Mailable::class)) {
                    $mailable = $value::class;
                    $mailableInstance = $value;
                    break;
                }
            }
        }

        $meta = app()->bound('platform.mail.meta') ? (array) app('platform.mail.meta') : [];
        $headers = $message->getHeaders();

        $notificationType = $meta['notification_type']
            ?? $this->header($headers, 'X-Platform-Notification-Type');
        $dedupeKey = $meta['dedupe_key']
            ?? $this->header($headers, 'X-Platform-Dedupe-Key');
        $audience = $meta['audience']
            ?? $this->header($headers, 'X-Platform-Audience');
        $source = $meta['source']
            ?? $this->header($headers, 'X-Platform-Source');

        if ($mailableInstance instanceof PlatformMailable) {
            $notificationType = $notificationType ?: $mailableInstance->notificationType;
            $dedupeKey = $dedupeKey ?: $mailableInstance->dedupeKey;
            $mailable = $mailable ?: $mailableInstance::class;
            if (! $audience && property_exists($mailableInstance, 'audience')) {
                $audience = $mailableInstance->audience;
            }
        }
        $mailable = $mailable ?: ($meta['mailable'] ?? null);

        $subject = $message->getSubject() ?: '(no subject)';
        $templateKey = $notificationType
            ?: (EmailCatalog::keyFromMailable($mailable) ?? EmailCatalog::keyFromSubject($subject));

        if (! $audience && $notificationType) {
            $audience = config("email_notifications.types.{$notificationType}.audience");
        }

        $campaignId = isset($meta['campaign_id']) ? (int) $meta['campaign_id'] : 0;
        $userId = isset($meta['user_id']) ? (int) $meta['user_id'] : 0;
        if ($mailableInstance instanceof AudienceCampaignMail) {
            $campaignId = $campaignId ?: (int) ($mailableInstance->campaign->id ?? 0);
            $userId = $userId ?: (int) ($mailableInstance->recipient->id ?? 0);
        }

        $logMeta = array_filter([
            'mailer' => config('mail.default'),
            'source' => $source,
        ]);
        if ($campaignId > 0) {
            $logMeta['campaign_id'] = $campaignId;
        }
        if ($userId > 0) {
            $logMeta['user_id'] = $userId;
        }

        $payload = [
            'mailable' => $mailable,
            'template_key' => $templateKey,
            'notification_type' => $notificationType ?: $templateKey,
            'dedupe_key' => $dedupeKey,
            'audience' => $audience,
            'to_email' => $to['email'] ?? 'unknown',
            'to_name' => $to['name'] ?? null,
            'from_email' => $from['email'] ?? config('mail.from.address'),
            'subject' => $subject,
            'status' => EmailLog::STATUS_DELIVERED,
            'error' => null,
            'meta' => $logMeta,
            'sent_at' => now(),
        ];

        $existing = EmailLog::findOpenByDedupe($dedupeKey);
        if ($existing) {
            $previousMeta = (array) $existing->meta;
            $existing->fill($payload);
            $existing->meta = array_filter(array_merge($previousMeta, $logMeta));
            $existing->attempts = max(1, (int) $existing->attempts) + 1;
            $existing->save();
            $this->markCampaignRecipientDelivered($campaignId, $userId, (int) $existing->id);

            return;
        }

        $log = EmailLog::create(array_merge($payload, [
            'uuid' => (string) Str::uuid(),
            'attempts' => 1,
        ]));

        $this->markCampaignRecipientDelivered($campaignId, $userId, (int) $log->id);
    }

    protected function markCampaignRecipientDelivered(int $campaignId, int $userId, int $logId): void
    {
        if ($campaignId < 1 || $userId < 1) {
            return;
        }

        try {
            if (! Schema::hasTable((new EmailCampaignRecipient)->getTable())) {
                return;
            }

            $updated = EmailCampaignRecipient::query()
                ->where('email_campaign_id', $campaignId)
                ->where('user_id', $userId)
                ->whereIn('status', [
                    EmailCampaignRecipient::STATUS_PENDING,
                    EmailCampaignRecipient::STATUS_QUEUED,
                    EmailCampaignRecipient::STATUS_FAILED,
                ])
                ->update([
                    'status' => EmailCampaignRecipient::STATUS_DELIVERED,
                    'email_log_id' => $logId,
                    'skip_reason' => null,
                ]);

            if ($updated) {
                EmailCampaign::query()->find($campaignId)?->recountRecipientTotals();
            }
        } catch (\Throwable $e) {
            Log::warning('Campaign recipient log sync failed', [
                'campaign_id' => $campaignId,
                'user_id' => $userId,
                'email_log_id' => $logId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function header($headers, string $name): ?string
    {
        if (! $headers || ! $headers->has($name)) {
            return null;
        }

        return $headers->get($name)?->getBodyAsString();
    }

    protected function firstAddress(?array $addresses): array
    {
        if (empty($addresses)) {
            return [];
        }

        $address = $addresses[array_key_first($addresses)];
        if (is_object($address) && method_exists($address, 'getAddress')) {
            return [
                'email' => $address->getAddress(),
                'name' => method_exists($address, 'getName') ? $address->getName() : null,
            ];
        }

        if (is_string($address)) {
            return ['email' => $address, 'name' => null];
        }

        return [];
    }
}
