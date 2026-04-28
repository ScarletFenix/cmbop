<?php
// app/Models/Order.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id', 
        'order_number', 
        'reference_code',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'stripe_response',
        'paid_at',
        'subtotal', 
        'tax', 
        'total_amount', 
        'payment_method', 
        'payment_status', 
        'status',
        'sensitive_type',
        'additional_price'
    ];

    protected $casts = [
        'stripe_response' => 'array',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'additional_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total_amount' => 'decimal:2'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
    
    // Helper method to get base price
    public function getBasePriceAttribute()
    {
        return $this->subtotal - $this->additional_price;
    }
    
    // Helper method to check if order has sensitive pricing
    public function hasSensitivePricing()
    {
        return !is_null($this->sensitive_type) && $this->additional_price > 0;
    }
}