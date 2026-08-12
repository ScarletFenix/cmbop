<?php

// app/Mail/WithdrawalStatusUpdated.php

namespace App\Mail;

use App\Services\Billing\WithdrawalPayoutStatementService;

class WithdrawalStatusUpdated extends PlatformMailable
{
    public $withdrawal;

    public $oldStatus;

    public $newStatus;

    public $notes;

    public function __construct($withdrawal, $oldStatus, $newStatus, $notes)
    {
        parent::__construct();
        $this->withdrawal = $withdrawal;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->notes = $notes;
    }

    protected function dedupeVariant(): ?string
    {
        return $this->oldStatus.'>'.$this->newStatus;
    }

    public function build()
    {
        $statementUrl = null;
        if ($this->newStatus === 'completed') {
            try {
                $statement = app(WithdrawalPayoutStatementService::class)
                    ->find($this->withdrawal);
                $statementUrl = $statement
                    ? route('publisher.billing.download', $statement)
                    : route('publisher.billing.index');
            } catch (\Throwable) {
                $statementUrl = route('publisher.billing.index');
            }
        }

        return $this->subject('Withdrawal Request '.ucfirst($this->newStatus))
            ->markdown('emails.publisher.withdrawal-status-updated')
            ->with([
                'withdrawal' => $this->withdrawal,
                'oldStatus' => $this->oldStatus,
                'newStatus' => $this->newStatus,
                'notes' => $this->notes,
                'statementUrl' => $statementUrl,
            ]);
    }
}
