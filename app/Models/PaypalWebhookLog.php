<?php

namespace App\Models;

use App\Models\Concerns\ToleratesMissingSchema;
use Illuminate\Database\Eloquent\Model;

class PaypalWebhookLog extends Model
{
    use ToleratesMissingSchema;

    protected $table = 'paypal_webhook_logs';

    protected $fillable = [
        'event_id',
        'event_type',
        'payload',
        'processed',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed' => 'boolean',
    ];
}
