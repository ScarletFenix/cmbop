<?php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WelcomeBonusClaim extends Model
{
    use ToleratesUnparseableDates;

    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'source',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * Final schema after 180100 + 103800 + 110800: nullable user_id,
     * nullOnDelete, unique place locks. No wallet backfill (that belongs
     * on migrate). Used when `--path` no-ops because the row is already
     * in `migrations` but the table was dropped. Not called from signup.
     */
    public static function ensureTable(): void
    {
        try {
            $name = (new static)->getTable();
            if (Schema::hasTable($name)) {
                return;
            }

            if (! Schema::hasTable('users')) {
                return;
            }

            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('source', 40);
                $table->decimal('amount', 12, 2);
                $table->timestamps();

                $table->unique('user_id');
                $table->unique('ip_address');
            });
        } catch (\Throwable $e) {
            Log::warning('Could not create welcome_bonus_claims at runtime', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
