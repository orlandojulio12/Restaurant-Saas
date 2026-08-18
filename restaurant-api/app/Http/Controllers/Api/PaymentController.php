<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    private const METODOS = ['cash', 'card', 'nequi', 'daviplata', 'bancolombia', 'transfer', 'other'];

    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->input('restaurant_id');
        $restaurant   = Restaurant::findOrFail($restaurantId);

        $query = Payment::with(['order:id,type,total', 'cashier:id,name'])
            ->where('restaurant_id', $restaurantId);

        if ($request->filled('method')) {
            $query->where('method', $request->input('method'));
        }

        // Las fechas llegan como día local del restaurante.
        if ($request->filled('from')) {
            [$desde] = $restaurant->dayBoundsUtc($request->input('from'));
            $query->where('created_at', '>=', $desde);
        }

        if ($request->filled('to')) {
            [, $hasta] = $restaurant->dayBoundsUtc($request->input('to'));
            $query->where('created_at', '<=', $hasta);
        }

        $pagos = $query->latest()->paginate((int) $request->input('per_page', 30));

        return response()->json($pagos);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurantId = $request->input('restaurant_id');

        $data = $request->validate([
            'order_id'  => ['required', 'integer'],
            // Literal en vez de implode(self::METODOS): así el generador de
            // documentación puede leer los valores admitidos.
            'method'    => ['required', 'in:cash,card,nequi,daviplata,bancolombia,transfer,other'],
            'amount'    => ['required', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes'     => ['nullable', 'string', 'max:255'],
        ]);

        $order = Order::with('payment')
            ->where('restaurant_id', $restaurantId)
            ->find($data['order_id']);

        if (!$order) {
            return response()->json(['message' => 'El pedido no pertenece a este restaurante.'], 422);
        }

        if ($order->payment) {
            return response()->json(['message' => 'Este pedido ya tiene un pago registrado.'], 422);
        }

        if ($order->status === 'cancelled') {
            return response()->json(['message' => 'No se puede cobrar un pedido cancelado.'], 422);
        }

        $total   = (float) $order->total;
        $recibido = (float) $data['amount'];

        // Con una tolerancia de un peso para absorber el redondeo del cliente.
        if ($recibido + 0.01 < $total) {
            return response()->json([
                'message'  => 'El monto recibido no cubre el total del pedido.',
                'total'    => round($total, 2),
                'received' => round($recibido, 2),
            ], 422);
        }

        $payment = DB::transaction(function () use ($data, $order, $restaurantId, $request, $total, $recibido) {
            return Payment::create([
                'restaurant_id' => $restaurantId,
                'order_id'      => $order->id,
                'cashier_id'    => $request->user()?->id,
                'method'        => $data['method'],
                'amount'        => $recibido,
                // El vuelto solo tiene sentido en efectivo.
                'change_amount' => $data['method'] === 'cash' ? round($recibido - $total, 2) : 0,
                'reference'     => $data['reference'] ?? null,
                'status'        => 'completed',
                'notes'         => $data['notes'] ?? null,
            ]);
        });

        // Cerrar libera la mesa, descuenta inventario, actualiza los contadores
        // del cliente y emite los eventos correspondientes.
        if ($order->status !== 'closed') {
            $this->orders->close($order);
        }

        return response()->json([
            'payment' => $payment,
            'order'   => $order->fresh()->load(['items.additionals', 'table', 'customer']),
        ], 201);
    }
}
