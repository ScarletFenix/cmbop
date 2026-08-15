<?php

namespace App\Services;

use App\Mail\CommunityFeedbackReviewed;
use App\Mail\WebsiteSuggestionReviewed;
use App\Models\InAppNotification;
use App\Models\ProblemReport;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\WebsiteSuggestion;
use App\Support\CommunityInbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CommunityInboxNotifier
{
    public function __construct(private InAppNotificationService $notifications) {}

    public function notifyAdminsNewProblem(ProblemReport $report): void
    {
        $who = CommunityInbox::plainLine($report->name ?: ($report->email ?: 'Someone'), 'Someone');
        $subject = CommunityInbox::plainLine($report->subject ?: 'a problem', 'a problem');

        $this->notifications->notifyAdmins(
            InAppNotificationService::TYPE_SYSTEM,
            'New problem report',
            "{$who} reported: {$subject}",
            [
                'roles' => ['admin'],
                'category' => InAppNotificationService::CATEGORY_SUPPORT,
                'icon' => 'alert-circle',
                'priority' => InAppNotification::PRIORITY_NORMAL,
                'related' => $report,
                'action_label' => 'Review reports',
                'action_url' => route('admin.community.index', ['tab' => 'problems', 'status' => 'pending'], false),
                'meta' => ['report_id' => $report->id],
            ]
        );
    }

    public function notifyAdminsNewSuggestion(Suggestion $suggestion): void
    {
        $who = CommunityInbox::plainLine($suggestion->name ?: ($suggestion->email ?: 'Someone'), 'Someone');

        $this->notifications->notifyAdmins(
            InAppNotificationService::TYPE_SYSTEM,
            'New suggestion',
            "{$who} sent a suggestion.",
            [
                'roles' => ['admin'],
                'category' => InAppNotificationService::CATEGORY_SUPPORT,
                'icon' => 'message-square',
                'priority' => InAppNotification::PRIORITY_NORMAL,
                'related' => $suggestion,
                'action_label' => 'Review suggestions',
                'action_url' => route('admin.community.index', ['tab' => 'suggestions', 'status' => 'pending'], false),
                'meta' => ['suggestion_id' => $suggestion->id],
            ]
        );
    }

    public function notifyAdminsNewWebsite(WebsiteSuggestion $suggestion): void
    {
        $name = CommunityInbox::plainLine($suggestion->website_name ?: ($suggestion->domain ?: 'a website'), 'a website');

        $this->notifications->notifyAdmins(
            InAppNotificationService::TYPE_SYSTEM,
            'New website suggestion',
            "Someone suggested adding {$name} to the catalog.",
            [
                'roles' => ['admin'],
                'category' => InAppNotificationService::CATEGORY_SUPPORT,
                'icon' => 'globe',
                'priority' => InAppNotification::PRIORITY_NORMAL,
                'related' => $suggestion,
                'action_label' => 'Review websites',
                'action_url' => route('admin.community.index', ['tab' => 'websites', 'status' => 'pending'], false),
                'meta' => ['suggestion_id' => $suggestion->id, 'domain' => $suggestion->domain],
            ]
        );
    }

    public function notifySubmitterReviewed(ProblemReport|Suggestion|WebsiteSuggestion $item, string $tab): void
    {
        if (CommunityInbox::plainLine($item->status) === 'pending') {
            return;
        }

        $item->loadMissing(['user']);

        try {
            if ((int) ($item->user_id ?? 0) > 0) {
                $this->bellSubmitter($item, $tab);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to bell community submitter: '.$e->getMessage(), [
                'tab' => $tab,
                'id' => $item->id,
            ]);
        }

        $email = CommunityInbox::validEmail($item->email ?? null)
            ?? CommunityInbox::validEmail($item->user?->email ?? null);
        if ($email === null) {
            return;
        }

        try {
            $notes = trim((string) ($item->admin_notes ?? ''));
            $reviewKey = bin2hex(random_bytes(4));
            $mailable = $item instanceof WebsiteSuggestion
                ? new WebsiteSuggestionReviewed(
                    $item,
                    (string) $item->status,
                    $notes,
                    (string) ($item->website_name ?: ($item->domain ?: 'the website')),
                    $reviewKey,
                )
                : new CommunityFeedbackReviewed(
                    $item,
                    $tab === CommunityInbox::TAB_SUGGESTIONS ? 'suggestion' : 'problem',
                    (string) $item->status,
                    $notes,
                    $item instanceof ProblemReport ? (string) ($item->subject ?: 'your report') : 'your suggestion',
                    $reviewKey,
                );

            Mail::to($email)->send($mailable);
        } catch (\Throwable $e) {
            Log::warning('Failed to email community submitter: '.$e->getMessage(), [
                'tab' => $tab,
                'id' => $item->id,
            ]);
        }
    }

    public function acceptWebsiteSuggestionAfterListing(?int $suggestionId, Site $site, ?User $admin): void
    {
        if (! $admin instanceof User || ! $suggestionId || $suggestionId <= 0) {
            return;
        }

        $suggestion = WebsiteSuggestion::query()->find($suggestionId);
        if (! $suggestion || $suggestion->status === 'accepted') {
            return;
        }

        $expected = CommunityInbox::suggestionLookupDomain($suggestion);
        $actual = CommunityInbox::listingLookupDomain($site);
        if ($expected === '' || $actual === '' || $expected !== $actual) {
            return;
        }

        $note = 'Listing created: '.$actual;
        $existing = trim((string) ($suggestion->admin_notes ?? ''));

        $affected = WebsiteSuggestion::query()
            ->whereKey($suggestion->id)
            ->where('status', '!=', 'accepted')
            ->update([
                'status' => 'accepted',
                'admin_notes' => $existing !== '' ? $existing."\n".$note : $note,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            return;
        }

        $fresh = $suggestion->fresh(['user']);
        if ($fresh) {
            try {
                $this->notifySubmitterReviewed($fresh, CommunityInbox::TAB_WEBSITES);
            } catch (\Throwable $e) {
                Log::warning('Failed to notify website suggestion submitter after listing: '.$e->getMessage(), [
                    'suggestion_id' => $fresh->id,
                    'site_id' => $site->id,
                ]);
            }
        }
    }

    private function bellSubmitter(ProblemReport|Suggestion|WebsiteSuggestion $item, string $tab): void
    {
        $notes = Str::limit(trim((string) ($item->admin_notes ?? '')), 180);
        $status = CommunityInbox::plainLine($item->status, 'reviewed');

        if ($item instanceof WebsiteSuggestion) {
            $name = CommunityInbox::plainLine($item->website_name ?: ($item->domain ?: 'the website'), 'the website');
            $title = $status === 'accepted'
                ? "Website suggestion accepted — {$name}"
                : "Website suggestion update — {$name}";
            $message = $status === 'accepted'
                ? "We accepted your suggestion for {$name} and will try to add it to the catalog."
                : "We reviewed your suggestion for {$name} and marked it as {$status}.";
        } elseif ($item instanceof ProblemReport) {
            $subject = CommunityInbox::plainLine($item->subject ?: 'your report', 'your report');
            $title = "We reviewed your report — {$subject}";
            $message = "Your problem report was marked as {$status}.";
        } else {
            $title = 'We reviewed your suggestion';
            $message = "Your suggestion was marked as {$status}.";
        }

        if ($notes !== '') {
            $message .= ' Note: '.$notes;
        }

        $this->notifications->notify(
            (int) $item->user_id,
            InAppNotificationService::TYPE_SYSTEM,
            $title,
            $message,
            [
                'category' => InAppNotificationService::CATEGORY_SUPPORT,
                'icon' => in_array($status, ['resolved', 'accepted'], true) ? 'check-circle' : 'info',
                'audience' => InAppNotification::AUDIENCE_ALL,
                'related' => $item,
                'action_label' => 'Open marketplace',
                'action_url' => $item instanceof WebsiteSuggestion
                    ? route('advertiser.catalog', [], false)
                    : '/',
            ]
        );
    }
}
