<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentWebhookLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'event',
        'payment_id',
        'order_id',
        'payload',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
