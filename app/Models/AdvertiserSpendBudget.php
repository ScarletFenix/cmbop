<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdvertiserSpendBudget extends Model
{
    protected static ?bool $tableAvailable = null;

    protected $fillable = [
        'user_id',
        'monthly_limit',
        'warn_at_percent',
        'low_balance_threshold',
        'notify_email',
        'notify_bell',
        'last_warn_period',
        'last_hit_period',
        'last_low_balance_on',
    ];

    protected $casts = [
        'monthly_limit' => 'decimal:2',
        'low_balance_threshold' => 'decimal:2',
        'warn_at_percent' => 'integer',
        'notify_email' => 'boolean',
        'notify_bell' => 'boolean',
        'last_low_balance_on' => 'date',
    ];

    /**
     * True when advertiser_spend_budgets exists (migration may lag deploy).
     */
    public static function tableAvailable(): bool
    {
        if (static::$tableAvailable !== null) {
            return static::$tableAvailable;
        }

        try {
            return static::$tableAvailable = Schema::hasTable('advertiser_spend_budgets');
        } catch (\Throwable) {
            return static::$tableAvailable = false;
        }
    }

    /** @internal Reset schema cache between tests. */
    public static function forgetTableAvailabilityCache(): void
    {
        static::$tableAvailable = null;
    }

    /**
     * Create the table at runtime when a deploy skipped its migration.
     * Hostinger often syncs PHP without migrate — Spending must not 500.
     */
    public static function ensureTable(): void
    {
        if (static::tableAvailable()) {
            return;
        }

        try {
            // Peer request may have created it after we cached false.
            if (Schema::hasTable('advertiser_spend_budgets')) {
                static::$tableAvailable = true;

                return;
            }

            if (! Schema::hasTable('users')) {
                return;
            }

            Schema::create('advertiser_spend_budgets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->decimal('monthly_limit', 12, 2)->nullable();
                $table->unsignedTinyInteger('warn_at_percent')->default(80);
                $table->decimal('low_balance_threshold', 12, 2)->nullable();
                $table->boolean('notify_email')->default(true);
                $table->boolean('notify_bell')->default(true);
                $table->string('last_warn_period', 7)->nullable();
                $table->string('last_hit_period', 7)->nullable();
                $table->date('last_low_balance_on')->nullable();
                $table->timestamps();
            });

            static::$tableAvailable = true;

            Log::warning('advertiser_spend_budgets table was missing — created at runtime');
        } catch (\Throwable $e) {
            // Lost create race (table already exists) or permission denial —
            // refresh cache from the live schema so this worker can recover.
            try {
                static::$tableAvailable = Schema::hasTable('advertiser_spend_budgets');
            } catch (\Throwable) {
                static::$tableAvailable = false;
            }

            if (! static::$tableAvailable) {
                Log::error('Could not create advertiser_spend_budgets at runtime', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
