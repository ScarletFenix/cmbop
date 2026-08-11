<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertiserSpendBudget extends Model
{
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
