<?php

namespace App\Mail;

use App\Models\User;
use InvalidArgumentException;

class DepositReminderMail extends PlatformMailable
{
    public const STEP_DAY7 = 'day7';

    public const STEP_DAY14 = 'day14';

    public function __construct(
        public User $user,
        public string $step = self::STEP_DAY14,
    ) {
        parent::__construct();

        if (! in_array($step, [self::STEP_DAY7, self::STEP_DAY14], true)) {
            throw new InvalidArgumentException('Invalid deposit reminder step: '.$step);
        }

        $this->notificationType = 'deposit_reminder';
        $this->recipientUser = $user;
    }

    public function build()
    {
        $addFundsUrl = $this->publicRoute('advertiser.add-funds');
        $catalogUrl = $this->publicRoute('advertiser.catalog');

        if ($this->step === self::STEP_DAY7) {
            return $this->subject('Your €20 credit is waiting — ready when you are')
                ->markdown('emails.deposit-reminder-day7')
                ->with([
                    'firstName' => $this->firstName($this->user),
                    'addFundsUrl' => $addFundsUrl,
                    'catalogUrl' => $catalogUrl,
                    'brand' => $this->brand(),
                ]);
        }

        return $this->subject('Add funds to place your first guest post')
            ->markdown('emails.deposit-reminder-day14')
            ->with([
                'firstName' => $this->firstName($this->user),
                'addFundsUrl' => $addFundsUrl,
                'catalogUrl' => $catalogUrl,
                'brand' => $this->brand(),
            ]);
    }
}
