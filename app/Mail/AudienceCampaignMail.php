<?php

namespace App\Mail;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use App\Support\EmailUnsubscribeLink;
use Carbon\Carbon;
use Illuminate\Mail\Mailables\Headers;

class AudienceCampaignMail extends PlatformMailable
{
    protected ?string $cachedUnsubscribeUrl = null;

    public function __construct(
        public EmailCampaign $campaign,
        public User $recipient,
    ) {
        parent::__construct();
        $this->notificationType = 'audience_campaign';
        $this->recipientUser = $recipient;
    }

    public function unsubscribeUrl(): string
    {
        return $this->cachedUnsubscribeUrl ??= EmailUnsubscribeLink::url($this->recipient);
    }

    public function build()
    {
        return $this->subject($this->campaign->subject)
            ->markdown('emails.campaigns.audience')
            ->with([
                'firstName' => $this->firstName($this->recipient),
                'subject' => $this->campaign->subject,
                'bodyHtml' => $this->campaign->body_html,
                'ctaLabel' => $this->campaign->cta_label,
                'ctaUrl' => $this->campaign->cta_url,
                'brand' => $this->brand(),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ]);
    }

    public function headers(): Headers
    {
        $url = $this->unsubscribeUrl();

        return new Headers(
            text: [
                'List-Unsubscribe' => '<'.$url.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }

    public function send($mailer)
    {
        $stale = $this->isStale();
        $result = parent::send($mailer);

        if ($result === null) {
            $this->markRecipientSkipped($stale
                ? EmailCampaignRecipient::SKIP_STALE
                : EmailCampaignRecipient::SKIP_DISABLED);
        }

        return $result;
    }

    /**
     * Campaigns may sit behind a backlog longer than transactional mail.
     */
    protected function isStale(): bool
    {
        $maxHours = (int) config('email_notifications.campaign_max_age_hours', 72);

        if ($maxHours <= 0 || blank($this->queuedAt)) {
            return false;
        }

        try {
            return Carbon::parse($this->queuedAt)->addHours($maxHours)->isPast();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function markRecipientSkipped(string $reason): void
    {
        if (! $this->campaign->id || ! $this->recipient->id) {
            return;
        }

        $updated = EmailCampaignRecipient::query()
            ->where('email_campaign_id', $this->campaign->id)
            ->where('user_id', $this->recipient->id)
            ->whereIn('status', [
                EmailCampaignRecipient::STATUS_PENDING,
                EmailCampaignRecipient::STATUS_QUEUED,
            ])
            ->update([
                'status' => EmailCampaignRecipient::STATUS_SKIPPED,
                'skip_reason' => $reason,
            ]);

        if ($updated) {
            $this->campaign->refresh()->recountRecipientTotals();
        }
    }
}
