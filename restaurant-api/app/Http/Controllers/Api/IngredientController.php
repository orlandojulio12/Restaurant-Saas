<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IngredientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Ingredient::where('restaurant_id', $request->input('restaurant_id'));

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOL));
        }

        if (filter_var($request->input('low_stock', false), FILTER_VALIDATE_BOOL)) {
            $query->whereColumn('stock', '<=', 'min_stock');
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data         = $request->validate($this->rules());
        $restaurantId = $request->input('restaurant_id');

        $ingredient = DB::transaction(function () use ($data, $restaurantId, $request) {
            // Los valores por defecto se fijan aquí y no solo en la base: el
            // movimiento de stock inicial lee del modelo recién creado.
            $ingredient = Ingredient::create([
                ...$data,
                'restaurant_id' => $restaurantId,
                'stock'         => $data['stock']         ?? 0,
                'min_stock'     => $data['min_stock']     ?? 0,
                'cost_per_unit' => $data['cost_per_unit'] ?? 0,
                'is_active'     => $data['is_active']     ?? true,
            ]);

            // El stock inicial se registra como movimiento de entrada: si no,
            // inventory_movements no cuadraría nunca con el stock real.
            if ((float) $ingredient->stock > 0) {
                InventoryMovement::create([
                    'restaurant_id' => $restaurantId,
                    'ingredient_id' => $ingredient->id,
                    'user_id'       => $request->user()?->id,
                    'type'          => 'in',
                    'quantity'      => $ingredient->stock,
                    'stock_before'  => 0,
                    'stock_after'   => $ingredient->stock,
                    'unit_cost'     => $ingredient->cost_per_unit,
                    'reason'        => 'Stock inicial',
                ]);
            }

            return $ingredient;
        });

        return response()->json($ingredient, 201);
    }

    public function show(Request $request, Ingredient $ingredient): JsonResponse
    {
        $this->authorizeRestaurant($request, $ingredient);

        $ingredient->load([
            'inventoryMovements' => fn($q) => $q->latest()->limit(20),
        ]);

        return response()->json($ingredient);
    }

    public function update(Request $request, Ingredient $ingredient): JsonResponse
    {
        $this->authorizeRestaurant($request, $ingredient);

        // 'stock' no se edita aquí a propósito: cambiarlo a mano rompería la
        // trazabilidad. Los ajustes van por POST /ingredients/{id}/movement.
        $data = $request->validate($this->rules(updating: true));
        unset($data['stock']);

        $ingredient->update($data);

        return response()->json($ingredient);
    }

    public function destroy(Request $request, Ingredient $ingredient): JsonResponse
    {
        $this->authorizeRestaurant($request, $ingredient);

        // product_ingredients e inventory_movements son RESTRICT: con recetas o
        // historial, el borrado fallaría en la base de datos.
        $enRecetas = $ingredient->productIngredients()->count();

        if ($enRecetas > 0) {
            return response()->json([
                'message' => "No se puede eliminar: el ingrediente se usa en {$enRecetas} receta(s). "
                    . 'Quítalo de los productos o desactívalo.',
            ], 422);
        }

        $conHistorial = $ingredient->inventoryMovements()->count();

        if ($conHistorial > 0) {
            return response()->json([
                'message' => "No se puede eliminar: tiene {$conHistorial} movimiento(s) de inventario registrados. "
                    . 'Desactívalo para retirarlo del uso.',
            ], 422);
        }

        $ingredient->delete();

        return response()->json(null, 204);
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name'          => [$required, 'string', 'max:100'],
            'unit'          => [$required, 'string', 'max:20'],
            'stock'         => ['nullable', 'numeric', 'min:0'],
            'min_stock'     => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'is_active'     => ['nullable', 'boolean'],
        ];
    }

    private function authorizeRestaurant(Request $request, Ingredient $ingredient): void
    {
        abort_if($ingredient->restaurant_id !== $request->input('restaurant_id'), 403);
    }
}
