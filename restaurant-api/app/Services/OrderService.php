<?php

namespace App\Services;

use App\Events\OrderCreated;
use App\Events\OrderStatusUpdated;
use App\Events\TableStatusUpdated;
use App\Models\Additional;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAdditional;
use App\Models\RestaurantTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Crea un pedido con sus ítems y adicionales.
     *
     * Vive aquí porque hay dos puertas de entrada —el personal y el QR del
     * cliente— y antes la segunda reutilizaba la acción de la primera falsificando
     * la petición con merge(), que es justo como se coló el bug del user_id.
     *
     * @param  array  $data          Datos ya validados
     * @param  Product[]|Collection  $products  Productos del restaurante, indexados por id
     */
    public function create(
        array $data,
        int $restaurantId,
        ?int $userId,
        Collection $products,
        string $status = 'pending',
    ): Order {
        $order = DB::transaction(function () use ($data, $restaurantId, $userId, $products, $status) {
            $subtotal = 0;

            $order = Order::create([
                'restaurant_id'    => $restaurantId,
                'table_id'         => $data['table_id'] ?? null,
                'customer_id'      => $data['customer_id'] ?? null,
                // Null en los pedidos por QR: no hay usuario autenticado detrás.
                'user_id'          => $userId,
                'type'             => $data['type'],
                'status'           => $status,
                'delivery_address' => $data['delivery_address'] ?? null,
                'delivery_notes'   => $data['delivery_notes'] ?? null,
                'notes'            => $data['notes'] ?? null,
                'subtotal'         => 0,
                'tax_amount'       => 0,
                'discount_amount'  => 0,
                'total'            => 0,
            ]);

            foreach ($data['items'] as $itemData) {
                $product   = $products[$itemData['product_id']];
                $unitPrice = (float) $product->price;

                // Los adicionales suman al precio unitario del ítem.
                $additionalModels = collect();
                $extraPrice       = 0;

                if (!empty($itemData['additionals'])) {
                    $additionalModels = Additional::whereIn('id', $itemData['additionals'])->get()->keyBy('id');
                    $extraPrice       = (float) $additionalModels->sum('extra_price');
                }

                $itemSubtotal = ($unitPrice + $extraPrice) * $itemData['quantity'];
                $subtotal    += $itemSubtotal;

                $item = OrderItem::create([
                    'order_id'     => $order->id,
                    'product_id'   => $product->id,
                    // Desnormalizado: el histórico no cambia si luego se edita el menú.
                    'product_name' => $product->name,
                    'unit_price'   => $unitPrice + $extraPrice,
                    'quantity'     => $itemData['quantity'],
                    'subtotal'     => $itemSubtotal,
                    'notes'        => $itemData['notes'] ?? null,
                    'status'       => 'pending',
                ]);

                foreach ($additionalModels as $add) {
                    OrderItemAdditional::create([
                        'order_item_id'   => $item->id,
                        'additional_id'   => $add->id,
                        'additional_name' => $add->name,
                        'extra_price'     => $add->extra_price,
                    ]);
                }
            }

            $order->update(['subtotal' => $subtotal, 'total' => $subtotal]);

            if ($data['type'] === 'dine_in' && !empty($data['table_id'])) {
                RestaurantTable::where('id', $data['table_id'])
                    ->where('restaurant_id', $restaurantId)
                    ->update(['status' => 'occupied']);
            }

            return $order;
        });

        $order->load(['items.additionals', 'table', 'customer', 'user']);

        event(new OrderCreated($order));

        return $order;
    }

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
