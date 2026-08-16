<?php

namespace App\Services\ContentUpload;

use App\Models\ContentModerationLog;
use App\Models\ContentSubmission;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\InAppNotificationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminLibraryStaffActions
{
    public function __construct(
        private ContentUploadService $uploads,
        private InAppNotificationService $notifications,
        private ContentModerationService $moderation,
    ) {}

    public function fileOnDisk(ContentSubmission $submission): bool
    {
        if (! $submission->hasStoredFile()) {
            return false;
        }

        try {
            return Storage::disk($submission->disk ?: 'local')->exists($submission->path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{approved:bool, submission:ContentSubmission, message:string, moderation_status:string}
     */
    public function retry(ContentSubmission $submission): array
    {
        if ($submission->isArchived()) {
            throw ValidationException::withMessages([
                'submission' => 'Archived articles cannot be re-evaluated. Restore the article first.',
            ]);
        }

        if ($submission->isLockedByPaidOrder()) {
            throw ValidationException::withMessages([
                'submission' => 'Cannot re-evaluate an article on a paid order. Use an order dispute if the placement must be unwound.',
            ]);
        }

        if ($submission->isUnusedExpired()) {
            throw ValidationException::withMessages([
                'submission' => 'Expired unused articles are preview only. Ask the advertiser to upload a new file.',
            ]);
        }

        if (! $this->fileOnDisk($submission) && ! $submission->hasPreviewHtml()) {
            throw ValidationException::withMessages([
                'submission' => 'This article has no file or preview to evaluate.',
            ]);
        }

        return $this->uploads->reEvaluateSubmission($submission, true);
    }

    /**
     * @return array{ok:bool, already:bool, submission:ContentSubmission, message:string}
     */
    public function override(ContentSubmission $submission, string $decision, User $admin, string $notes): array
    {
        $decision = $decision === ContentSubmission::STATUS_REJECTED
            ? ContentSubmission::STATUS_REJECTED
            : ContentSubmission::STATUS_APPROVED;

        if ($submission->isArchived()) {
            throw ValidationException::withMessages([
                'submission' => 'Archived articles cannot be overridden. Restore the article first.',
            ]);
        }

        if ($decision === ContentSubmission::STATUS_REJECTED && $submission->isLockedByPaidOrder()) {
            throw ValidationException::withMessages([
                'decision' => 'Cannot reject an article on a paid order. Use an order dispute instead.',
            ]);
        }

        $result = $this->moderation->applyStaffOverride($submission, $decision, $admin, $notes);
        $fresh = $result['submission'] ?? null;
        if (! ($result['ok'] ?? false) || ! $fresh) {
            throw ValidationException::withMessages([
                'submission' => $result['message'] ?: 'Override failed.',
            ]);
        }

        $already = (bool) ($result['already'] ?? false);
        $owner = $fresh->user;
        // Re-submitting the same decision used to notify the advertiser again
        // while Activity History stayed silent — a no-op with side effects.
        if ($owner && ! $already) {
            try {
                $this->notifications->notifyContentEvaluation($owner, $fresh, [
                    'approved' => $decision === ContentSubmission::STATUS_APPROVED,
                    'title' => $decision === ContentSubmission::STATUS_APPROVED
                        ? 'Article approved'
                        : 'Article needs changes',
                    'message' => $this->overrideNotice($fresh, $decision, $notes),
                    'moderation_status' => $decision,
                    'action_url' => route(
                        'advertiser.content-library',
                        $fresh->staffApprovalLibraryParams(),
                        false
                    ),
                ]);
            } catch (\Throwable) {
            }
        }

        return [
            'ok' => true,
            'already' => $already,
            'submission' => $fresh,
            'message' => (string) ($result['message'] ?? ''),
        ];
    }

    protected function overrideNotice(ContentSubmission $submission, string $decision, string $notes): string
    {
        if ($decision !== ContentSubmission::STATUS_APPROVED) {
            return 'A staff member rejected this article. '.$notes;
        }

        if ($submission->isReadyForCheckout()) {
            return 'A staff member approved this article. You can attach it in the catalog.';
        }

        if ($submission->isUsableAfterStaffApproval()) {
            return 'A staff member approved this article. You can continue the open order it is already attached to.';
        }

        $notice = trim($submission->editorNotice());

        return 'A staff member approved this article, but it still needs a fix before you can order it.'
            .($notice !== '' ? ' '.$notice : '');
    }

    public function archive(ContentSubmission $submission): ContentSubmission
    {
        if (($submission->isInUse() || $submission->isClaimedByAnotherOrder()) && ! $submission->isPublished()) {
            throw ValidationException::withMessages([
                'submission' => $submission->isClaimedByAnotherOrder() && ! $submission->isInUse()
                    ? ContentSubmission::ACTIVE_ORDER_CLAIM_MESSAGE
                    : 'Articles in progress cannot be archived until the order is completed or cancelled.',
            ]);
        }

        $submission->archive();

        return $submission->fresh();
    }

    public function restore(ContentSubmission $submission): ContentSubmission
    {
        $submission->restoreFromArchive();

        return $submission->fresh();
    }

    public function submissionForLog(ContentModerationLog $log): ?ContentSubmission
    {
        $byLogId = ContentSubmission::query()
            ->where('moderation_log_id', $log->id)
            ->first();
        if ($byLogId) {
            return $byLogId;
        }

        if (! filled($log->scan_token) || ! $log->user_id) {
            return null;
        }

        return ContentSubmission::query()
            ->where('scan_token', $log->scan_token)
            ->where('user_id', $log->user_id)
            ->latest('id')
            ->first();
    }
}
