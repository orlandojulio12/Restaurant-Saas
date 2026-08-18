<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'category_id'       => $this->category_id,
            'name'              => $this->name,
            'description'       => $this->description,
            'image_url'         => $this->image_url,
            'price'             => $this->price,
            'cost'              => $this->cost,
            'preparation_time'  => $this->preparation_time,
            'is_available'      => $this->is_available,
            'sort_order'        => $this->sort_order,
            'additional_groups' => $this->whenLoaded('additionalGroups', fn() =>
                $this->additionalGroups->map(fn($g) => [
                    'id'             => $g->id,
                    'name'           => $g->name,
                    'selection_type' => $g->selection_type,
                    'is_required'    => $g->is_required,
                    'additionals'    => $g->relationLoaded('additionals')
                        ? $g->additionals->map(fn($a) => [
                            'id'           => $a->id,
                            'name'         => $a->name,
                            'extra_price'  => $a->extra_price,
                            'is_available' => $a->is_available,
                        ])
                        : [],
                ])
            ),
        ];
    }
}