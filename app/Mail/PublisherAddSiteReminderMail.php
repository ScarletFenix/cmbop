<?php

namespace App\Mail;

use App\Models\User;
use InvalidArgumentException;

class PublisherAddSiteReminderMail extends PlatformMailable
{
    public const STEP_DAY3 = 'day3';

    public const STEP_DAY7 = 'day7';

    public function __construct(
        public User $user,
        public string $step = self::STEP_DAY3,
    ) {
        parent::__construct();

        if (! in_array($step, [self::STEP_DAY3, self::STEP_DAY7], true)) {
            throw new InvalidArgumentException('Invalid publisher add-site reminder step: '.$step);
        }

        $this->notificationType = 'publisher_add_site_reminder';
        $this->recipientUser = $user;
        $this->dedupeKey = 'publisher_add_site:'.$step.':'.$user->id;
    }

    public function build()
    {
        $websitesUrl = url('/publisher/websites');

        if ($this->step === self::STEP_DAY3) {
            return $this->subject('List your first website to start receiving orders')
                ->markdown('emails.publisher-add-site-day3')
                ->with([
                    'firstName' => $this->firstName($this->user),
                    'websitesUrl' => $websitesUrl,
                    'brand' => $this->brand(),
                ]);
        }

        return $this->subject('Finish setup — add your website this week')
            ->markdown('emails.publisher-add-site-day7')
            ->with([
                'firstName' => $this->firstName($this->user),
                'websitesUrl' => $websitesUrl,
                'brand' => $this->brand(),
            ]);
    }
}
