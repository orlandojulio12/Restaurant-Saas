<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappSession extends Model
{
    protected $table = 'whatsapp_sessions';

    protected $fillable = [
        'restaurant_id',
        'customer_id',
        'phone',
        'state',
        'context',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'context'         => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
