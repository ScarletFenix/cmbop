<?php

namespace App\Mail;

use App\Models\ProblemReport;
use App\Models\Suggestion;
use App\Models\User;
use App\Support\CommunityInbox;

class CommunityFeedbackReviewed extends PlatformMailable
{
    public ?int $recipientUserId = null;

    public function __construct(
        public ProblemReport|Suggestion $item,
        public string $kind,
        public ?string $status = null,
        public ?string $notes = null,
        public ?string $subjectLabel = null,
        public ?string $reviewKey = null,
    ) {
        parent::__construct();
        $this->item->loadMissing(['user']);
        $this->status = CommunityInbox::plainLine($status ?? (string) $this->item->status, 'reviewed');
        $this->notes = $notes ?? trim((string) ($this->item->admin_notes ?? ''));
        $fallback = $this->kind === 'problem' ? 'your report' : 'your suggestion';
        $this->subjectLabel = CommunityInbox::plainLine(
            $subjectLabel ?? ($this->kind === 'problem' ? (string) ($this->item->subject ?: $fallback) : $fallback),
            $fallback
        );
        $this->reviewKey = $reviewKey ?: '';
        $this->notificationType = 'community_feedback_reviewed';
        $this->recipientUserId = $this->item->user?->id;
        $this->recipientUser = null;
        $this->skipUserPreference = $this->recipientUserId === null;
        $this->dedupeKey = 'community-feedback-reviewed-'.$this->kind.'-'.$this->item->id.'-'.$this->status
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
        $resolved = in_array($this->status, ['resolved', 'accepted'], true);
        $subjectLabel = $this->subjectLabel ?: ($this->kind === 'problem' ? 'your report' : 'your suggestion');

        return $this->subject(
            ($resolved ? 'Update on ' : 'We reviewed ').$subjectLabel
        )
            ->markdown('emails.community-feedback-reviewed')
            ->with([
                'item' => $this->item,
                'kind' => $this->kind,
                'status' => $this->status,
                'resolved' => $resolved,
                'subjectLabel' => $subjectLabel,
                'notes' => trim((string) $this->notes),
                'actionUrl' => $this->publicRoute('home'),
            ]);
    }
}
