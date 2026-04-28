<?php
// app/Models/OrderItem.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 
        'site_id', 
        'site_name', 
        'site_url', 
        'price', 
        'content_link', 
        'live_url',
        'sensitive_type',
        'additional_price'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'additional_price' => 'decimal:2'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
    
    // Helper method to get base price (price - additional_price)
    public function getBasePriceAttribute()
    {
        return $this->price - $this->additional_price;
    }
    
    // Helper method to check if item has sensitive pricing
    public function hasSensitivePricing()
    {
        return !is_null($this->sensitive_type) && $this->additional_price > 0;
    }
    
    // Helper method to get formatted price breakdown
    public function getPriceBreakdownAttribute()
    {
        if ($this->hasSensitivePricing()) {
            return [
                'base_price' => $this->base_price,
                'additional_price' => $this->additional_price,
                'sensitive_type' => $this->sensitive_type,
                'total_price' => $this->price
            ];
        }
        
        return [
            'base_price' => $this->price,
            'additional_price' => 0,
            'sensitive_type' => null,
            'total_price' => $this->price
        ];
    }
}