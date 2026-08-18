<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Restaurant extends Model
{
    protected $fillable = [
        'plan_id',
        'name',
        'slug',
        'phone',
        'whatsapp_number',
        'address',
        'city',
        'country',
        'logo_url',
        'currency',
        'timezone',
        'is_active',
        'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active'     => 'boolean',
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * "Ahora" en la zona horaria del restaurante.
     *
     * La app corre en UTC: cualquier cálculo de día de negocio (hoy, ayer, inicio de mes)
     * debe partir de aquí, no de now(), o el corte queda desplazado varias horas.
     */
    public function localNow(): Carbon
    {
        return Carbon::now($this->timezone ?: 'UTC');
    }

    /**
     * Límites UTC de un día local del restaurante, para filtrar columnas datetime.
     *
     * @return array{0: Carbon, 1: Carbon} [inicio, fin]
     */
    public function dayBoundsUtc(string $date): array
    {
        $tz = $this->timezone ?: 'UTC';

        return [
            Carbon::parse($date, $tz)->startOfDay()->utc(),
            Carbon::parse($date, $tz)->endOfDay()->utc(),
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class, 'restaurant_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function fixedCosts(): HasMany
    {
        return $this->hasMany(FixedCost::class);
    }

    public function financialGoal(): HasOne
    {
        return $this->hasOne(FinancialGoal::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function dailySummaries(): HasMany
    {
        return $this->hasMany(DailySummary::class);
    }
}
