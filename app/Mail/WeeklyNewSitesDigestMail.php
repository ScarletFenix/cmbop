<?php

namespace App\Mail;

use App\Models\User;
use App\Models\WeeklyNewSitesRecipient;
use App\Models\WeeklyNewSitesToken;
use App\Services\WeeklyNewSites\CardPresenter;
use App\Services\WeeklyNewSites\DigestDispatcher;
use App\Services\WeeklyNewSites\MatchSelector;
use App\Services\WeeklyNewSites\PermissionGate;
use App\Support\MailNotificationPresenter;
use App\Support\WeeklyNewSitesLink;
use Illuminate\Support\Collection;
use Symfony\Component\Mime\Email;

class WeeklyNewSitesDigestMail extends PlatformMailable
{
    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [90, 300, 900];

    /**
     * @param  Collection<int, array<string, mixed>>  $cards
     * @param  list<array<string, mixed>>  $browseLinks
     */
    public function __construct(
        public User $advertiser,
        public Collection $cards,
        public array $browseLinks,
        public string $weekKey,
        public int $recipientId = 0,
        public string $permissionBasis = '',
    ) {
        parent::__construct();

        $this->notificationType = PermissionGate::TYPE;
        $this->recipientUser = $advertiser;
        $this->dedupeKey = 'weekly_new_sites:'.$advertiser->id.':'.$weekKey;
    }

    public function build()
    {
        $unsub = $this->actionUrl(WeeklyNewSitesToken::PURPOSE_UNSUB);
        $prefs = $this->actionUrl(WeeklyNewSitesToken::PURPOSE_PREFERENCES, [
            'path' => '/advertiser/new-site-digest',
        ]);

        $this->withSymfonyMessage(function (Email $message) use ($unsub) {
            $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$unsub.'>');
            $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });

        $count = $this->cards->count();
        $subject = $count === 1
            ? 'A new site matches your placements'
            : $count.' new sites match your placements';

        return $this->subject($subject)
            ->view('emails.advertiser.weekly-new-sites-digest', $this->viewData($unsub, $prefs))
            ->text('emails.advertiser.weekly-new-sites-digest-text', $this->viewData($unsub, $prefs));
    }

    public function send($mailer)
    {
        if (! $this->forceSend && ! $this->passesDispatchRecheck()) {
            return null;
        }

        $result = parent::send($mailer);

        if ($result !== null && $this->recipientId > 0) {
            $recipient = WeeklyNewSitesRecipient::query()->find($this->recipientId);
            if ($recipient) {
                app(DigestDispatcher::class)->markDelivered($recipient);
            }
        }

        return $result;
    }

    private function passesDispatchRecheck(): bool
    {
        $gate = app(PermissionGate::class)->inspect($this->advertiser);
        if (! $gate['allowed']) {
            $this->suppressReason = $gate['reason'] ?: 'permission';
            $this->cancelQueued('permission');

            return false;
        }

        $min = max(1, (int) config('weekly_new_sites_digest.min_sites', 2));
        $fresh = app(MatchSelector::class)->recheck($this->advertiser, $this->cards);
        if ($fresh->count() < $min) {
            $this->suppressReason = 'below_min_at_dispatch';
            $this->cancelQueued('below_min_at_dispatch');

            return false;
        }

        $this->cards = app(CardPresenter::class)->decorate($this->advertiser, $fresh, $this->recipientModel());
        $this->browseLinks = app(CardPresenter::class)->browseLinks(
            $this->advertiser,
            app(MatchSelector::class)->distinctPairs($fresh),
            $this->recipientModel()
        );

        return true;
    }

    private function cancelQueued(string $reason): void
    {
        if ($this->recipientId < 1) {
            return;
        }
        $recipient = WeeklyNewSitesRecipient::query()->find($this->recipientId);
        if ($recipient && $recipient->status === WeeklyNewSitesRecipient::STATUS_QUEUED) {
            app(DigestDispatcher::class)->cancelRecipient($recipient, $reason);
        }
    }

    private function recipientModel(): ?WeeklyNewSitesRecipient
    {
        if ($this->recipientId < 1) {
            return null;
        }

        return WeeklyNewSitesRecipient::query()->find($this->recipientId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function actionUrl(string $purpose, array $payload = []): string
    {
        return WeeklyNewSitesLink::mint($this->advertiser, $purpose, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(string $unsub, string $prefs): array
    {
        return [
            'firstName' => $this->firstName($this->advertiser),
            'cards' => $this->cards,
            'browseLinks' => $this->browseLinks,
            'weekKey' => $this->weekKey,
            'unsubscribeUrl' => $unsub,
            'preferencesUrl' => $prefs,
            'brand' => $this->brand(),
            'supportLine' => MailNotificationPresenter::supportLine('account'),
        ];
    }
}
