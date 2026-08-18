<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Envuelve el array que arma FinancialController@dashboard, no un modelo.
 *
 * @property-read array $resource
 */
class FinancialDashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}