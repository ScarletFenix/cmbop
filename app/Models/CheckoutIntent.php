<?php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Model;

class CheckoutIntent extends Model
{
    use ToleratesUnparseableDates;

    protected $fillable = [
        'user_id',
        'reference_code',
        'bonus_applied',
        'package',
        'expires_at',
    ];

    protected $casts = [
        'bonus_applied' => 'decimal:2',
        'package' => 'array',
        'expires_at' => 'datetime',
    ];
}
