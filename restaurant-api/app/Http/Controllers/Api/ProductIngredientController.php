<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductIngredient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Receta de un producto: qué ingredientes consume y en qué cantidad.
 *
 * Es lo que hace funcionar el descuento automático de inventario al cerrar un
 * pedido; sin receta, InventoryService no tiene nada que descontar.
 */
class ProductIngredientController extends Controller
{
    public function index(Request $request, Product $product): JsonResponse
    {
        $this->authorizeRestaurant($request, $product);

        return response()->json($this->receta($product));
    }

    /**
     * Reemplaza la receta completa por la lista recibida.
     *
     * Se sincroniza en bloque en vez de tener alta/baja por línea: una receta
     * se edita como un todo y así no quedan estados intermedios raros.
     */
    public function sync(Request $request, Product $product): JsonResponse
    {
        $this->authorizeRestaurant($request, $product);

        $data = $request->validate([
            'ingredients'                 => ['present', 'array'],
            'ingredients.*.ingredient_id' => ['required', 'integer', 'distinct'],
            'ingredients.*.quantity'      => ['required', 'numeric', 'gt:0'],
        ], [
            'ingredients.*.ingredient_id.distinct' => 'Hay ingredientes repetidos en la receta.',
            'ingredients.*.quantity.gt'            => 'La cantidad de cada ingrediente debe ser mayor que cero.',
        ]);

        $lineas = collect($data['ingredients']);

        // Los ingredientes tienen que ser del mismo restaurante que el producto.
        $propios = Ingredient::whereIn('id', $lineas->pluck('ingredient_id'))
            ->where('restaurant_id', $product->restaurant_id)
            ->pluck('id');

        $ajenos = $lineas->pluck('ingredient_id')->diff($propios);

        if ($ajenos->isNotEmpty()) {
            return response()->json([
                'message'     => 'Uno o más ingredientes no pertenecen a este restaurante.',
                'invalid_ids' => $ajenos->values(),
            ], 422);
        }

        DB::transaction(function () use ($product, $lineas) {
            ProductIngredient::where('product_id', $product->id)->delete();

            foreach ($lineas as $linea) {
                ProductIngredient::create([
                    'product_id'    => $product->id,
                    'ingredient_id' => $linea['ingredient_id'],
                    'quantity'      => $linea['quantity'],
                ]);
            }
        });

        return response()->json($this->receta($product));
    }

    /**
     * Receta con el costo teórico que implica.
     *
     * Se devuelve junto al costo registrado en el producto para que la
     * diferencia sea visible: products.cost alimenta los reportes de utilidad,
     * y si se desvía de la receta esos márgenes mienten.
     */
    private function receta(Product $product): array
    {
        $lineas = ProductIngredient::with('ingredient:id,name,unit,cost_per_unit,stock')
            ->where('product_id', $product->id)
            ->get();

        $costoReceta = $lineas->sum(
            fn($l) => (float) $l->quantity * (float) ($l->ingredient?->cost_per_unit ?? 0)
        );

        return [
            'product_id'      => $product->id,
            'product_name'    => $product->name,
            'ingredients'     => $lineas->map(fn($l) => [
                'ingredient_id' => $l->ingredient_id,
                'name'          => $l->ingredient?->name,
                'unit'          => $l->ingredient?->unit,
                'quantity'      => (float) $l->quantity,
                'cost_per_unit' => (float) ($l->ingredient?->cost_per_unit ?? 0),
                'line_cost'     => round((float) $l->quantity * (float) ($l->ingredient?->cost_per_unit ?? 0), 2),
                'current_stock' => (float) ($l->ingredient?->stock ?? 0),
            ])->values(),
            'calculated_cost' => round($costoReceta, 2),
            'registered_cost' => round((float) $product->cost, 2),
            'cost_difference' => round((float) $product->cost - $costoReceta, 2),
        ];
    }

    private function authorizeRestaurant(Request $request, Product $product): void
    {
        abort_if($product->restaurant_id !== $request->input('restaurant_id'), 403);
    }
}
