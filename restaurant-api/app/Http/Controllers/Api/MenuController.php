<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;

class MenuController extends Controller
{
    public function public(string $restaurantSlug): JsonResponse
    {
        $restaurant = Restaurant::where('slug', $restaurantSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $categories = Category::with([
                'products' => fn($q) => $q->where('is_available', true)
                    ->orderBy('sort_order')
                    ->with(['additionalGroups.additionals' => fn($q) => $q->where('is_available', true)]),
            ])
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'restaurant' => [
                'id'       => $restaurant->id,
                'name'     => $restaurant->name,
                'currency' => $restaurant->currency,
            ],
            'categories' => $categories->map(fn($cat) => [
                'id'       => $cat->id,
                'name'     => $cat->name,
                'image_url'=> $cat->image_url,
                'products' => $cat->products->map(fn($p) => [
                    'id'               => $p->id,
                    'name'             => $p->name,
                    'description'      => $p->description,
                    'image_url'        => $p->image_url,
                    'price'            => $p->price,
                    'preparation_time' => $p->preparation_time,
                    'additional_groups'=> $p->additionalGroups->map(fn($g) => [
                        'id'             => $g->id,
                        'name'           => $g->name,
                        'selection_type' => $g->selection_type,
                        'is_required'    => $g->is_required,
                        'additionals'    => $g->additionals->map(fn($a) => [
                            'id'          => $a->id,
                            'name'        => $a->name,
                            'extra_price' => $a->extra_price,
                        ]),
                    ]),
                ]),
            ]),
        ]);
    }
}