<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            return;
        }

        Schema::table('withdrawals', function (Blueprint $table) {
            if (! Schema::hasColumn('withdrawals', 'wallet_id')) {
                $table->foreignId('wallet_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('wallets')
                    ->nullOnDelete();
            }
        });

        $this->backfillWalletIds();
    }

    public function down(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasColumn('withdrawals', 'wallet_id')) {
            return;
        }

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wallet_id');
        });
    }

    private function backfillWalletIds(): void
    {
        if (! Schema::hasColumn('withdrawals', 'wallet_id') || ! Schema::hasTable('wallet_transactions')) {
            return;
        }

        $rows = DB::table('withdrawals')->whereNull('wallet_id')->get(['id']);
        foreach ($rows as $row) {
            $walletId = DB::table('wallet_transactions')
                ->whereNotNull('wallet_id')
                ->where('type', 'withdrawal')
                ->where('direction', 'debit')
                ->where(function ($query) use ($row) {
                    $query->where('reference', 'WD-'.$row->id)
                        ->orWhere(function ($query) use ($row) {
                            $query->where('related_id', $row->id)
                                ->where('related_type', 'like', '%Withdrawal');
                        });
                })
                ->orderByDesc('id')
                ->value('wallet_id');

            if ($walletId) {
                DB::table('withdrawals')->where('id', $row->id)->update(['wallet_id' => $walletId]);
            }
        }
    }
};
