<?php

namespace App\Services;

use App\Events\OrderStatusUpdated;
use App\Events\TableStatusUpdated;
use App\Models\Order;
use App\Models\RestaurantTable;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Cierra una orden y ejecuta todo lo que eso implica.
     *
     * Se llama desde dos sitios —el cambio manual de estado y el registro del
     * pago— y por eso vive aquí: dos implementaciones acabarían divergiendo.
     */
    public function close(Order $order): Order
    {
        $mesaLiberada = DB::transaction(function () use ($order) {
            $order->update([
                'status'    => 'closed',
                'closed_at' => $order->closed_at ?? now(),
            ]);

            $this->actualizarContadoresCliente($order);

            return $this->liberarMesaSiProcede($order);
        });

        // El descuento de inventario abre su propia transacción y emite sus
        // propios eventos de stock bajo.
        $order->load(['items.product.productIngredients.ingredient']);
        $this->inventory->deductForOrder($order);

        if ($mesaLiberada) {
            event(new TableStatusUpdated($mesaLiberada));
        }

        $order->loadMissing('table');
        event(new OrderStatusUpdated($order));

        return $order;
    }

    /**
     * La mesa vuelve a estar libre solo si no le quedan pedidos vivos.
     */
    private function liberarMesaSiProcede(Order $order): ?RestaurantTable
    {
        if (!$order->table_id) {
            return null;
        }

        $siguenAbiertos = Order::where('table_id', $order->table_id)
            ->where('id', '!=', $order->id)
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->exists();

        if ($siguenAbiertos) {
            return null;
        }

        $table = RestaurantTable::find($order->table_id);
        $table?->update(['status' => 'available']);

        return $table;
    }

    /**
     * Los contadores del cliente son derivados: se mantienen al cerrar, que es
     * cuando la venta se considera realizada.
     */
    private function actualizarContadoresCliente(Order $order): void
    {
        if (!$order->customer_id) {
            return;
        }

        $order->customer()->increment('total_orders');

        $order->customer()->update([
            'total_spent'   => DB::raw('total_spent + ' . (float) $order->total),
            'last_order_at' => now(),
        ]);
    }
}
