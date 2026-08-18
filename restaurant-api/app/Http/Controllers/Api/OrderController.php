<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderCreated;
use App\Events\OrderStatusUpdated;
use App\Events\TableStatusUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Additional;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAdditional;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Services\OrderService;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // Transiciones de estado válidas
    private const TRANSITIONS = [
        'pending'    => ['preparing', 'cancelled'],
        'preparing'  => ['ready',     'cancelled'],
        'ready'      => ['delivered', 'on_the_way'],
        'on_the_way' => ['delivered'],
        'delivered'  => ['closed'],
        'closed'     => [],
        'cancelled'  => [],
    ];

    // Timestamp que se actualiza en cada transición
    private const STATUS_TIMESTAMPS = [
        'preparing'  => 'preparing_at',
        'ready'      => 'ready_at',
        'delivered'  => 'delivered_at',
        'on_the_way' => 'delivered_at',
        'closed'     => 'closed_at',
        'confirmed'  => 'confirmed_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->input('restaurant_id');

        $query = Order::with(['items.additionals', 'table', 'customer', 'user'])
            ->where('restaurant_id', $restaurantId);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('table_id')) {
            $query->where('table_id', $request->input('table_id'));
        }
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->input('date'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $orders  = $query->latest()->paginate($perPage);

        return response()->json(OrderResource::collection($orders)->response()->getData(true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'                         => ['required', 'in:dine_in,delivery,counter'],
            'table_id'                     => ['nullable', 'integer'],
            'customer_id'                  => ['nullable', 'integer'],
            'delivery_address'             => ['nullable', 'string'],
            'delivery_notes'               => ['nullable', 'string'],
            'notes'                        => ['nullable', 'string'],
            'items'                        => ['required', 'array', 'min:1'],
            'items.*.product_id'           => ['required', 'integer'],
            'items.*.quantity'             => ['required', 'integer', 'min:1'],
            'items.*.notes'                => ['nullable', 'string'],
            'items.*.additionals'          => ['nullable', 'array'],
            'items.*.additionals.*'        => ['integer'],
        ]);

        $restaurantId = $request->input('restaurant_id');

        // El cupo diario se cuenta sobre el día local del restaurante.
        app(PlanService::class)->assertRoomFor(
            Restaurant::findOrFail($restaurantId),
            'daily_orders'
        );

        if ($data['type'] === 'dine_in' && empty($data['table_id'])) {
            return response()->json(['message' => 'table_id es requerido para pedidos en mesa.'], 422);
        }

        // Verificar que todos los productos pertenecen al restaurante
        $productIds = collect($data['items'])->pluck('product_id')->unique();
        $products   = Product::where('restaurant_id', $restaurantId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            return response()->json(['message' => 'Uno o más productos no pertenecen a este restaurante.'], 422);
        }

        $order = DB::transaction(function () use ($data, $restaurantId, $products, $request) {
            $subtotal = 0;

            $order = Order::create([
                'restaurant_id'    => $restaurantId,
                'table_id'         => $data['table_id'] ?? null,
                'customer_id'      => $data['customer_id'] ?? null,
                // Null en pedidos por QR: la ruta pública no tiene usuario autenticado.
                'user_id'          => $request->user()?->id,
                'type'             => $data['type'],
                'status'           => 'pending',
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

                // Calcular precio de adicionales
                $additionalIds    = $itemData['additionals'] ?? [];
                $extraPrice       = 0;
                $additionalModels = [];

                if (!empty($additionalIds)) {
                    $additionalModels = Additional::whereIn('id', $additionalIds)->get()->keyBy('id');
                    foreach ($additionalModels as $add) {
                        $extraPrice += (float) $add->extra_price;
                    }
                }

                $itemSubtotal = ($unitPrice + $extraPrice) * $itemData['quantity'];
                $subtotal    += $itemSubtotal;

                $item = OrderItem::create([
                    'order_id'     => $order->id,
                    'product_id'   => $product->id,
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

            $order->update([
                'subtotal' => $subtotal,
                'total'    => $subtotal,
            ]);

            // Marcar mesa como ocupada
            if ($data['type'] === 'dine_in' && !empty($data['table_id'])) {
                RestaurantTable::where('id', $data['table_id'])
                    ->where('restaurant_id', $restaurantId)
                    ->update(['status' => 'occupied']);
            }

            return $order;
        });

        $order->load(['items.additionals', 'table', 'customer', 'user']);

        event(new OrderCreated($order));

        return response()->json(new OrderResource($order), 201);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeRestaurant($request, $order);
        $order->load(['items.additionals', 'table', 'customer', 'user', 'payment']);

        return response()->json(new OrderResource($order));
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $this->authorizeRestaurant($request, $order);

        $data = $request->validate([
            'notes'            => ['nullable', 'string'],
            'delivery_address' => ['nullable', 'string'],
            'delivery_notes'   => ['nullable', 'string'],
        ]);

        $order->update($data);

        return response()->json(new OrderResource($order));
    }

    public function destroy(Request $request, Order $order): JsonResponse
    {
        $this->authorizeRestaurant($request, $order);
        $order->update(['status' => 'cancelled']);

        return response()->json(null, 204);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $this->authorizeRestaurant($request, $order);

        $data = $request->validate([
            'status' => ['required', 'string'],
        ]);

        $newStatus  = $data['status'];
        $validNext  = self::TRANSITIONS[$order->status] ?? [];

        if (!in_array($newStatus, $validNext)) {
            return response()->json([
                'message' => "Transición inválida: {$order->status} → {$newStatus}. Permitidos: " . implode(', ', $validNext),
            ], 422);
        }

        $updates = ['status' => $newStatus];

        if (isset(self::STATUS_TIMESTAMPS[$newStatus])) {
            $updates[self::STATUS_TIMESTAMPS[$newStatus]] = now();
        }

        if ($newStatus === 'closed') {
            // Liberar mesa, descontar inventario, actualizar contadores del
            // cliente y emitir eventos: todo vive en OrderService, que comparte
            // con el registro de pagos.
            $order->update($updates);
            app(OrderService::class)->close($order);
        } else {
            $order->update($updates);

            $order->loadMissing('table');
            event(new OrderStatusUpdated($order));
        }

        $order->load(['items.additionals', 'table', 'customer', 'user']);

        return response()->json(new OrderResource($order));
    }

    public function storeFromQr(Request $request): JsonResponse
    {
        // El pedido QR no tiene usuario autenticado; se resuelve por restaurantSlug
        $data = $request->validate([
            'restaurant_slug' => ['required', 'string'],
            'table_id'        => ['required', 'integer'],
            'items'           => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['required', 'integer'],
            'items.*.quantity'    => ['required', 'integer', 'min:1'],
            'items.*.notes'       => ['nullable', 'string'],
            'items.*.additionals' => ['nullable', 'array'],
        ]);

        $restaurant = \App\Models\Restaurant::where('slug', $data['restaurant_slug'])
            ->where('is_active', true)
            ->firstOrFail();

        // La mesa llega del cliente: verificar que pertenece a este restaurante.
        $tableBelongs = RestaurantTable::where('id', $data['table_id'])
            ->where('restaurant_id', $restaurant->id)
            ->exists();

        if (!$tableBelongs) {
            return response()->json(['message' => 'La mesa no pertenece a este restaurante.'], 422);
        }

        $request->merge([
            'restaurant_id' => $restaurant->id,
            'type'          => 'dine_in',
        ]);

        return $this->store($request);
    }

    private function authorizeRestaurant(Request $request, Order $order): void
    {
        abort_if($order->restaurant_id !== $request->input('restaurant_id'), 403);
    }
}