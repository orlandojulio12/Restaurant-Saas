<?php

namespace App\Services;

use App\Events\LowStockAlert;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /** Tipos que restan stock. */
    private const SALIDAS = ['out', 'waste'];

    /**
     * Descuenta los ingredientes de los productos de una orden cerrada
     * y registra el movimiento correspondiente.
     *
     * Solo actúa si el plan del restaurante incluye inventario.
     */
    public function deductForOrder(Order $order): void
    {
        $order->loadMissing([
            'restaurant.plan',
            'items.product.productIngredients.ingredient',
        ]);

        if (!$order->restaurant?->plan?->has_inventory) {
            return;
        }

        /** @var Ingredient[] $bajoMinimo */
        $bajoMinimo = [];

        DB::transaction(function () use ($order, &$bajoMinimo) {
            foreach ($order->items as $item) {
                $product = $item->product;

                if (!$product) {
                    continue;
                }

                foreach ($product->productIngredients as $pi) {
                    if (!$pi->ingredient) {
                        continue;
                    }

                    $movimiento = $this->aplicar(
                        ingredientId: $pi->ingredient->id,
                        type:         'out',
                        quantity:     (float) $pi->quantity * (int) $item->quantity,
                        reason:       "Orden #{$order->id} cerrada",
                        userId:       Auth::id(),
                        orderId:      $order->id,
                    );

                    if ($movimiento['bajo_minimo']) {
                        $bajoMinimo[$movimiento['ingrediente']->id] = $movimiento['ingrediente'];
                    }
                }
            }
        });

        // Fuera de la transacción: si se emitiera dentro, el listener podría
        // leer un stock que aún no está confirmado (o que termina revertido).
        foreach ($bajoMinimo as $ingredient) {
            event(new LowStockAlert($ingredient));
        }
    }

    /**
     * Registra un movimiento manual (entrada, salida, merma o ajuste).
     *
     * En 'adjustment' el stock resultante es $newStock —el conteo físico— y la
     * cantidad del movimiento queda como la diferencia observada.
     */
    public function registerMovement(
        Ingredient $ingredient,
        string $type,
        ?float $quantity = null,
        ?float $newStock = null,
        ?string $reason = null,
        ?float $unitCost = null,
        ?int $userId = null,
    ): InventoryMovement {
        $resultado = DB::transaction(fn() => $this->aplicar(
            ingredientId: $ingredient->id,
            type:         $type,
            quantity:     $quantity,
            newStock:     $newStock,
            reason:       $reason,
            unitCost:     $unitCost,
            userId:       $userId,
        ));

        if ($resultado['bajo_minimo']) {
            event(new LowStockAlert($resultado['ingrediente']));
        }

        return $resultado['movimiento'];
    }

    /**
     * Núcleo compartido: bloquea la fila, calcula el nuevo stock y deja el
     * movimiento registrado. Debe llamarse siempre dentro de una transacción.
     *
     * @return array{movimiento: InventoryMovement, ingrediente: Ingredient, bajo_minimo: bool}
     */
    private function aplicar(
        int $ingredientId,
        string $type,
        ?float $quantity = null,
        ?float $newStock = null,
        ?string $reason = null,
        ?float $unitCost = null,
        ?int $userId = null,
        ?int $orderId = null,
    ): array {
        // Bloqueo de fila: dos movimientos simultáneos no deben leer el mismo
        // stock previo y perder uno de los dos.
        $ingredient  = Ingredient::lockForUpdate()->findOrFail($ingredientId);
        $stockBefore = (float) $ingredient->stock;

        $stockAfter = match (true) {
            $type === 'adjustment'      => max(0, (float) $newStock),
            in_array($type, self::SALIDAS, true) => max(0, $stockBefore - (float) $quantity),
            default                     => $stockBefore + (float) $quantity,
        };

        // En un ajuste la "cantidad" es cuánto se movió respecto a lo que había.
        $cantidad = $type === 'adjustment'
            ? abs($stockAfter - $stockBefore)
            : (float) $quantity;

        $ingredient->update(['stock' => $stockAfter]);

        $movimiento = InventoryMovement::create([
            'restaurant_id' => $ingredient->restaurant_id,
            'ingredient_id' => $ingredient->id,
            'user_id'       => $userId,
            'type'          => $type,
            'quantity'      => $cantidad,
            'stock_before'  => $stockBefore,
            'stock_after'   => $stockAfter,
            'unit_cost'     => $unitCost ?? $ingredient->cost_per_unit ?? 0,
            'reason'        => $reason,
            'order_id'      => $orderId,
        ]);

        return [
            'movimiento'  => $movimiento,
            'ingrediente' => $ingredient,
            // Solo alerta al cruzar el umbral hacia abajo: si ya estaba bajo
            // mínimo, una entrada que no lo resuelve no dispara otro aviso.
            'bajo_minimo' => $stockAfter <= (float) $ingredient->min_stock && $stockAfter < $stockBefore,
        ];
    }
}
