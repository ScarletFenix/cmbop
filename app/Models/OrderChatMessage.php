<?php

// app/Models/OrderChatMessage.php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class OrderChatMessage extends Model
{
    use ToleratesUnparseableDates;

    protected $table = 'order_chat_messages';

    protected static ?bool $hasBlockedColumn = null;

    protected $fillable = [
        'order_id',
        'user_id',
        'sender_type',
        'message',
        'images',
        'is_read',
        'is_blocked',
        'blocked_reason',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'is_blocked' => 'boolean',
        'read_at' => 'datetime',
        'images' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True when order_chat_messages.is_blocked exists (migration may lag deploy).
     */
    public static function hasBlockedColumn(): bool
    {
        if (static::$hasBlockedColumn !== null) {
            return static::$hasBlockedColumn;
        }

        return static::$hasBlockedColumn = Schema::hasTable('order_chat_messages')
            && Schema::hasColumn('order_chat_messages', 'is_blocked');
    }

    /** @internal Reset schema cache between tests. */
    public static function forgetBlockedColumnCache(): void
    {
        static::$hasBlockedColumn = null;
    }

    /**
     * Exclude moderation-blocked chat rows when the column is present.
     */
    public function scopeNotBlocked(Builder $query): Builder
    {
        if (static::hasBlockedColumn()) {
            $query->where('is_blocked', false);
        }

        return $query;
    }

    public function scopeUnreadForUser($query, $userId, $userType)
    {
        return $query->where('is_read', false)
            ->notBlocked()
            ->where('user_id', '!=', $userId)
            ->when($userType === 'advertiser', function ($q) {
                $q->where('sender_type', 'publisher');
            })
            ->when($userType === 'publisher', function ($q) {
                $q->where('sender_type', 'advertiser');
            });
    }

    public function markAsRead()
    {
        $this->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    public function getPreviewAttribute()
    {
        if ($this->message) {
            return strip_tags(substr($this->message, 0, 100)).(strlen($this->message) > 100 ? '...' : '');
        }
        if ($this->images) {
            return '📷 '.count($this->images).' image(s)';
        }

        return '';
    }
}
