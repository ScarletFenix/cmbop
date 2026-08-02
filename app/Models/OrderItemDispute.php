<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
