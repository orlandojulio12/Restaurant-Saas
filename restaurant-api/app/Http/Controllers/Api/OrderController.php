<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Services\OrderService;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PlanService $plans,
    ) {}

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

    /**
     * Crear un pedido desde el panel del restaurante.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'                  => ['required', 'in:dine_in,delivery,counter'],
            'table_id'              => ['nullable', 'integer'],
            'customer_id'           => ['nullable', 'integer'],
            'delivery_address'      => ['nullable', 'string'],
            'delivery_notes'        => ['nullable', 'string'],
            'notes'                 => ['nullable', 'string'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.product_id'    => ['required', 'integer'],
            'items.*.quantity'      => ['required', 'integer', 'min:1'],
            'items.*.notes'         => ['nullable', 'string'],
            'items.*.additionals'   => ['nullable', 'array'],
            'items.*.additionals.*' => ['integer'],
        ]);

        $restaurantId = (int) $request->input('restaurant_id');

        if ($error = $this->validarPedido($data, $restaurantId)) {
            return $error;
        }

        $order = $this->orders->create(
            $data,
            $restaurantId,
            $request->user()?->id,
            $this->productosDelRestaurante($data, $restaurantId),
        );

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

    /**
     * Crear un pedido desde el QR de la mesa, sin sesión.
     *
     * Ruta pública: el restaurante se resuelve por el slug y el pedido queda sin
     * usuario asociado.
     */
    public function storeFromQr(Request $request): JsonResponse
    {
        $data = $request->validate([
            'restaurant_slug'       => ['required', 'string'],
            // El código del QR identifica la mesa sin ser adivinable. table_id
            // sigue admitiéndose para el personal, pero no para el comensal:
            // es secuencial y cambiarlo en la URL dejaría pedir a cuenta ajena.
            'qr_code'               => ['required_without:table_id', 'nullable', 'string'],
            'table_id'              => ['required_without:qr_code', 'nullable', 'integer'],
            'notes'                 => ['nullable', 'string'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.product_id'    => ['required', 'integer'],
            'items.*.quantity'      => ['required', 'integer', 'min:1'],
            'items.*.notes'         => ['nullable', 'string'],
            'items.*.additionals'   => ['nullable', 'array'],
            'items.*.additionals.*' => ['integer'],
        ]);

        $restaurant = Restaurant::where('slug', $data['restaurant_slug'])
            ->where('is_active', true)
            ->firstOrFail();

        // La mesa llega del cliente, así que se resuelve siempre contra este
        // restaurante: ni el código ni el id valen si son de otro local.
        $mesa = RestaurantTable::where('restaurant_id', $restaurant->id)
            ->when(
                !empty($data['qr_code']),
                fn($q) => $q->where('qr_code', $data['qr_code']),
                fn($q) => $q->where('id', $data['table_id']),
            )
            ->first();

        if (!$mesa) {
            return response()->json(['message' => 'La mesa no pertenece a este restaurante.'], 422);
        }

        $data['table_id'] = $mesa->id;

        $data['type'] = 'dine_in';

        if ($error = $this->validarPedido($data, $restaurant->id)) {
            return $error;
        }

        $order = $this->orders->create(
            $data,
            $restaurant->id,
            null,
            $this->productosDelRestaurante($data, $restaurant->id),
        );

        return response()->json(new OrderResource($order), 201);
    }

    /**
     * Reglas comunes a las dos formas de crear un pedido.
     */
    private function validarPedido(array $data, int $restaurantId): ?JsonResponse
    {
        $this->plans->assertRoomFor(Restaurant::findOrFail($restaurantId), 'daily_orders');

        if ($data['type'] === 'dine_in' && empty($data['table_id'])) {
            return response()->json(['message' => 'table_id es requerido para pedidos en mesa.'], 422);
        }

        $productIds = collect($data['items'])->pluck('product_id')->unique();

        $existentes = Product::where('restaurant_id', $restaurantId)
            ->whereIn('id', $productIds)
            ->count();

        if ($existentes !== $productIds->count()) {
            return response()->json(['message' => 'Uno o más productos no pertenecen a este restaurante.'], 422);
        }

        return null;
    }

    private function productosDelRestaurante(array $data, int $restaurantId): Collection
    {
        return Product::where('restaurant_id', $restaurantId)
            ->whereIn('id', collect($data['items'])->pluck('product_id')->unique())
            ->get()
            ->keyBy('id');
    }

    private function authorizeRestaurant(Request $request, Order $order): void
    {
        abort_if($order->restaurant_id !== $request->input('restaurant_id'), 403);
    }
}