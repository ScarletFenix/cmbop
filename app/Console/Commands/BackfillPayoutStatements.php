<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Withdrawal;
use App\Services\Billing\WithdrawalPayoutStatementService;
use Illuminate\Console\Command;

class BackfillPayoutStatements extends Command
{
    protected $signature = 'billing:backfill-payout-statements
                            {--limit=50 : Max completed withdrawals to process}
                            {--dry-run : List candidates without creating statements}';

    protected $description = 'Create missing PAY payout statements for completed withdrawals';

    public function handle(WithdrawalPayoutStatementService $statements): int
    {
        $limit = (int) $this->option('limit');

        if ($this->option('dry-run')) {
            $count = Withdrawal::query()
                ->where('status', 'completed')
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('invoices')
                        ->whereColumn('invoices.user_id', 'withdrawals.user_id')
                        ->where('invoices.type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
                        ->where('invoices.status', '!=', Invoice::STATUS_CANCELLED)
                        ->whereRaw("invoices.reference_code = CONCAT('WD-', withdrawals.id)");
                })
                ->count();

            $this->info("Dry run: {$count} completed withdrawal(s) missing a payout statement (limit {$limit}).");

            return self::SUCCESS;
        }

        $result = $statements->backfillMissing($limit);

        $this->info(sprintf(
            'Payout statements: created=%d skipped=%d failed=%d',
            $result['created'],
            $result['skipped'],
            $result['failed']
        ));

        if ($result['invoice_ids'] !== []) {
            $this->line('Invoice ids: '.implode(', ', $result['invoice_ids']));
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
