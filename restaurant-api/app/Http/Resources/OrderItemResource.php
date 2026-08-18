<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'product_id'   => $this->product_id,
            'product_name' => $this->product_name,
            'unit_price'   => $this->unit_price,
            'quantity'     => $this->quantity,
            'subtotal'     => $this->subtotal,
            'notes'        => $this->notes,
            'status'       => $this->status,
            'additionals'  => $this->whenLoaded('additionals', fn() =>
                $this->additionals->map(fn($a) => [
                    'id'              => $a->id,
                    'additional_id'   => $a->additional_id,
                    'additional_name' => $a->additional_name,
                    'extra_price'     => $a->extra_price,
                ])
            ),
        ];
    }
}
