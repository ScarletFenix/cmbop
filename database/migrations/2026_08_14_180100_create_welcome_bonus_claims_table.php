<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('welcome_bonus_claims')) {
            Schema::create('welcome_bonus_claims', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('source', 40);
                $table->decimal('amount', 12, 2);
                $table->timestamps();

                $table->unique('user_id');
                $table->unique('ip_address');
            });
        }

        $this->backfillClaims();
    }

    public function down(): void
    {
        Schema::dropIfExists('welcome_bonus_claims');
    }

    /**
     * Lock IPs that already received a welcome bonus so a later signup
     * from the same place cannot collect it again. Skip rows with no IP
     * so we do not lock the empty-IP bucket. Never claw back existing credit.
     */
    private function backfillClaims(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('welcome_bonus_claims')) {
            return;
        }

        $hasConsents = Schema::hasTable('user_consents');
        $claimedIps = [];

        $rows = DB::table('wallet_transactions')
            ->where('type', 'bonus_credit')
            ->where('description', 'Welcome promotional bonus')
            ->orderBy('id')
            ->get(['user_id', 'amount']);

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            if ($userId < 1) {
                continue;
            }

            if (DB::table('welcome_bonus_claims')->where('user_id', $userId)->exists()) {
                continue;
            }

            $ip = null;
            if ($hasConsents) {
                $raw = DB::table('user_consents')
                    ->where('user_id', $userId)
                    ->orderBy('id')
                    ->value('ip_address');
                $ip = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
            }

            if ($ip !== null && isset($claimedIps[$ip])) {
                continue;
            }

            DB::table('welcome_bonus_claims')->insert([
                'user_id' => $userId,
                'ip_address' => $ip,
                'user_agent' => null,
                'source' => 'backfill',
                'amount' => $row->amount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($ip !== null) {
                $claimedIps[$ip] = true;
            }
        }
    }
};
