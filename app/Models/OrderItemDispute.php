<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OrderItemDispute extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_UPHELD = 'upheld';

    public const STATUS_DISMISSED = 'dismissed';

    public const REPORT_WINDOW_DAYS = 30;

    protected static ?bool $tableAvailable = null;

    protected $fillable = [
        'order_id',
        'order_item_id',
        'opened_by',
        'status',
        'reason',
        'admin_notes',
        'resolved_by',
        'resolved_at',
        'publisher_debited',
        'advertiser_credited',
        'debt_created',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'publisher_debited' => 'decimal:2',
        'advertiser_credited' => 'decimal:2',
        'debt_created' => 'decimal:2',
    ];

    /**
     * True when order_item_disputes exists (migration may lag deploy).
     */
    public static function tableAvailable(): bool
    {
        if (static::$tableAvailable !== null) {
            return static::$tableAvailable;
        }

        try {
            return static::$tableAvailable = Schema::hasTable('order_item_disputes');
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
     *
     * This codebase already heals blog_translations the same way, for the same
     * reason: a missing table here is not a feature that is off, it is a feature
     * that silently vanished — advertisers cannot raise a dispute and admins
     * cannot resolve one, with nothing on screen to say why. Guarding the reads
     * stops the 500; this is what actually restores the feature.
     *
     * Cheap to call: the schema check is cached per process by tableAvailable().
     */
    public static function ensureTable(): void
    {
        if (static::tableAvailable()) {
            return;
        }

        try {
            if (! Schema::hasTable('orders') || ! Schema::hasTable('order_items')) {
                return;
            }

            Schema::create('order_item_disputes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
                $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
                $table->string('status', 20)->default('open');
                $table->text('reason');
                $table->text('admin_notes')->nullable();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->decimal('publisher_debited', 12, 2)->nullable();
                $table->decimal('advertiser_credited', 12, 2)->nullable();
                $table->decimal('debt_created', 12, 2)->nullable();
                $table->timestamps();

                $table->index(['order_item_id', 'status']);
                $table->index(['order_id', 'status']);
            });

            static::forgetTableAvailabilityCache();

            Log::warning('order_item_disputes table was missing — created at runtime');
        } catch (\Throwable $e) {
            // Reads stay guarded, so failing here costs the dispute feature but
            // not the order pages.
            Log::error('Could not create order_item_disputes at runtime', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Filter dispute relations out of an eager-load list when the table is absent.
     *
     * Disputes arrived after the rest of the order system, so a deploy that has
     * not run migrations yet still has to serve order pages. Every caller
     * repeating `tableAvailable() ? [...] : []` is a guard someone eventually
     * forgets — which is how the admin order page came to 500 on a missing
     * table while the advertiser one degraded quietly.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function eagerPaths(array $paths): array
    {
        return static::tableAvailable() ? $paths : [];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isUpheld(): bool
    {
        return $this->status === self::STATUS_UPHELD;
    }

    public function isDismissed(): bool
    {
        return $this->status === self::STATUS_DISMISSED;
    }
}
