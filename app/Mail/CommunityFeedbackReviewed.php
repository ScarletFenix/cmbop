<?php

namespace App\Mail;

use App\Models\ProblemReport;
use App\Models\Suggestion;
use App\Support\CommunityInbox;

class CommunityFeedbackReviewed extends PlatformMailable
{
    public function __construct(
        public ProblemReport|Suggestion $item,
        public string $kind,
        public ?string $status = null,
        public ?string $notes = null,
    ) {
        parent::__construct();
        $this->item->loadMissing(['user']);
        $this->status = $status ?? (string) $this->item->status;
        $this->notes = $notes ?? trim((string) ($this->item->admin_notes ?? ''));
        $this->notificationType = 'community_feedback_reviewed';
        $this->recipientUser = $this->item->user;
        $this->skipUserPreference = $this->recipientUser === null;
        $this->dedupeKey = 'community-feedback-reviewed-'.$this->kind.'-'.$this->item->id.'-'.$this->status;
    }

    public function build()
    {
        $resolved = in_array($this->status, ['resolved', 'accepted'], true);
        $subjectLabel = $this->kind === 'problem'
            ? (string) ($this->item->subject ?: 'your report')
            : 'your suggestion';
        $subjectLabel = CommunityInbox::plainLine($subjectLabel, $this->kind === 'problem' ? 'your report' : 'your suggestion');

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
