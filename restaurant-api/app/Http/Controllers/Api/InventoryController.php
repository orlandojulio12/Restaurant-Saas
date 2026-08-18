<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function addMovement(Request $request, Ingredient $ingredient): JsonResponse
    {
        $this->authorizeRestaurant($request, $ingredient);


        $data = $request->validate([
            'type'      => ['required', 'in:in,out,adjustment,waste'],
            // En un ajuste no se informa cuánto entra o sale, sino cuánto hay.
            'quantity'  => ['required_unless:type,adjustment', 'nullable', 'numeric', 'gt:0'],
            'new_stock' => ['required_if:type,adjustment', 'nullable', 'numeric', 'min:0'],
            'reason'    => ['nullable', 'string', 'max:255'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $movimiento = $this->inventory->registerMovement(
            ingredient: $ingredient,
            type:       $data['type'],
            quantity:   isset($data['quantity'])  ? (float) $data['quantity']  : null,
            newStock:   isset($data['new_stock']) ? (float) $data['new_stock'] : null,
            reason:     $data['reason'] ?? null,
            unitCost:   isset($data['unit_cost']) ? (float) $data['unit_cost'] : null,
            userId:     $request->user()?->id,
        );

        return response()->json([
            'movement'   => $movimiento,
            'ingredient' => $ingredient->fresh(),
        ], 201);
    }

    public function lowStockAlerts(Request $request): JsonResponse
    {

        $ingredientes = Ingredient::where('restaurant_id', $request->input('restaurant_id'))
            ->where('is_active', true)
            ->whereColumn('stock', '<=', 'min_stock')
            ->orderBy('name')
            ->get()
            // Primero lo más crítico: agotado antes que rozando el mínimo.
            ->sortBy(fn($i) => (float) $i->min_stock > 0
                ? (float) $i->stock / (float) $i->min_stock
                : 0)
            ->values();

        return response()->json([
            'data'  => $ingredientes,
            'count' => $ingredientes->count(),
        ]);
    }

    public function movements(Request $request): JsonResponse
    {

        $query = InventoryMovement::with(['ingredient:id,name,unit', 'user:id,name'])
            ->where('restaurant_id', $request->input('restaurant_id'));

        if ($request->filled('ingredient_id')) {
            $query->where('ingredient_id', $request->input('ingredient_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return response()->json(
            $query->latest()->paginate((int) $request->input('per_page', 30))
        );
    }

    private function authorizeRestaurant(Request $request, Ingredient $ingredient): void
    {
        abort_if($ingredient->restaurant_id !== $request->input('restaurant_id'), 403);
    }
}
