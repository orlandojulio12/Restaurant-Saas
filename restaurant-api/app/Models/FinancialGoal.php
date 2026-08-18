<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialGoal extends Model
{
    protected $table = 'financial_goals';

    protected $fillable = [
        'restaurant_id',
        'target_monthly_revenue',
        'target_profit_margin',
        'avg_ticket_goal',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
