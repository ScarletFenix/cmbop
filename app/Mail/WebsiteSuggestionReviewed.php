<?php

namespace App\Mail;

use App\Models\User;
use App\Models\WebsiteSuggestion;
use App\Support\CommunityInbox;

class WebsiteSuggestionReviewed extends PlatformMailable
{
    public ?int $recipientUserId = null;

    public function __construct(
        public WebsiteSuggestion $suggestion,
        public ?string $status = null,
        public ?string $notes = null,
        public ?string $siteName = null,
        public ?string $reviewKey = null,
    ) {
        parent::__construct();
        $this->suggestion->loadMissing(['user']);
        $this->status = $status ?? (string) $this->suggestion->status;
        $this->notes = $notes ?? trim((string) ($this->suggestion->admin_notes ?? ''));
        $this->siteName = CommunityInbox::plainLine(
            $siteName ?? ($this->suggestion->website_name ?: ($this->suggestion->domain ?: 'the website')),
            'the website'
        );
        $this->reviewKey = $reviewKey ?: '';
        $this->notificationType = 'website_suggestion_reviewed';
        $this->recipientUserId = $this->suggestion->user?->id;
        $this->recipientUser = null;
        $this->skipUserPreference = $this->recipientUserId === null;
        $this->dedupeKey = 'website-suggestion-reviewed-'.$this->suggestion->id.'-'.$this->status
            .($this->reviewKey !== '' ? '-'.$this->reviewKey : '');
    }

    protected function resolveRecipientUser(): ?User
    {
        if ($this->recipientUserId) {
            return User::query()->find($this->recipientUserId);
        }

        return parent::resolveRecipientUser();
    }

    public function build()
    {
        $accepted = $this->status === 'accepted';

        return $this->subject(
            ($accepted ? 'Website suggestion accepted' : 'Website suggestion update').': '.$this->siteName
        )
            ->markdown('emails.website-suggestion-reviewed')
            ->with([
                'suggestion' => $this->suggestion,
                'accepted' => $accepted,
                'status' => $this->status,
                'siteName' => $this->siteName,
                'notes' => trim((string) $this->notes),
                'actionUrl' => $this->publicRoute('advertiser.catalog'),
            ]);
    }
}
