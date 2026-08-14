<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckoutIntent extends Model
{
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
