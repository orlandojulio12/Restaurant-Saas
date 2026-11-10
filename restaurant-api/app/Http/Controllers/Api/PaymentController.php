<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
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

    /**
     * Cobrar de una vez todo lo que lleva una mesa.
     *
     * Con el QR, cada comensal manda su propio pedido: una mesa de cuatro puede
     * acumular cuatro pedidos y hasta ahora la caja tenía que cobrarlos uno a
     * uno y sumar el total a mano.
     *
     * Sigue habiendo un pago por pedido —`payments.order_id` no es nulable y
     * los reportes cuentan por pedido—, pero se crean juntos y en una sola
     * transacción. El vuelto se calcula sobre el total de la mesa y se carga en
     * el último, de forma que `amount - change_amount` sigue siendo el total de
     * cada pedido, igual que en un cobro suelto.
     */
    public function storeForTable(Request $request, RestaurantTable $table): JsonResponse
    {
        $restaurantId = (int) $request->input('restaurant_id');

        abort_if($table->restaurant_id !== $restaurantId, 403);

        $data = $request->validate([
            'method'    => ['required', 'in:cash,card,nequi,daviplata,bancolombia,transfer,other'],
            'amount'    => ['required', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes'     => ['nullable', 'string', 'max:255'],
            // Cobro parcial: qué pedidos de la mesa se pagan ahora. Sin esto
            // se cobra la mesa entera, que sigue siendo el caso normal.
            //
            // Va por ids y no por nombre de comensal a propósito. El reparto
            // (`Order::repartoPorComensal`) ya le dice al cliente qué pedidos
            // son de quién, así que al cobrar no hay que volver a casar nombres
            // ni decidir cómo se piden "los que no pusieron nombre". Y admite
            // agrupaciones que un nombre no expresa: Ana y Luis pagando juntos
            // es una lista de ids, no un caso especial.
            'order_ids'   => ['nullable', 'array', 'min:1'],
            'order_ids.*' => ['integer'],
        ]);

        $abiertos = Order::with('payment')
            ->where('restaurant_id', $restaurantId)
            ->where('table_id', $table->id)
            ->whereIn('status', Order::ACTIVOS)
            ->oldest()
            ->get();

        $cobrables = $abiertos->filter(fn(Order $o) => !$o->payment)->values();

        $parcial = !empty($data['order_ids']);

        if ($parcial) {
            $pedidos  = array_values(array_unique($data['order_ids']));
            $elegidos = $cobrables->whereIn('id', $pedidos)->values();

            // Que el id exista no basta: tiene que ser de esta mesa, seguir
            // abierto y no estar cobrado ya. Si alguno falla no se cobra nada,
            // porque un cobro a medias de lo que pidió Ana es peor que ninguno.
            if ($elegidos->count() !== count($pedidos)) {
                return response()->json([
                    'message' => 'Alguno de esos pedidos no está pendiente de cobro en esta mesa.',
                    'pending' => $cobrables->pluck('id')->values(),
                ], 422);
            }

            $cobrables = $elegidos;
        }

        // Una propuesta del QR no está confirmada por nadie: cobrarla sería dar
        // por buena una comanda que el mesero todavía no ha mirado.
        //
        // El alcance de la comprobación sigue al del cobro. Cobrando la mesa
        // entera estorba cualquier propuesta, porque la mesa quedaría ocupada
        // por un pedido pendiente justo después de pagar. Cobrando solo lo de
        // Ana, que Luis tenga una propuesta sin confirmar no es asunto suyo:
        // ahí la mesa sigue abierta a propósito.
        $propuestos = ($parcial ? $cobrables : $abiertos)->where('status', 'proposed');

        if ($propuestos->isNotEmpty()) {
            return response()->json([
                'message'  => $parcial
                    ? 'Ese comensal tiene pedidos sin confirmar. Confírmalos o descártalos antes de cobrar.'
                    : 'La mesa tiene pedidos sin confirmar. Confírmalos o descártalos antes de cobrar.',
                'proposed' => $propuestos->pluck('id')->values(),
            ], 422);
        }

        if ($cobrables->isEmpty()) {
            return response()->json(['message' => 'Esta mesa no tiene nada pendiente de cobro.'], 422);
        }

        $total    = round($cobrables->sum(fn(Order $o) => (float) $o->total), 2);
        $recibido = (float) $data['amount'];

        // Misma tolerancia de un peso que en el cobro suelto, para absorber el
        // redondeo del cliente.
        if ($recibido + 0.01 < $total) {
            return response()->json([
                'message'  => 'El monto recibido no cubre el total de la mesa.',
                'total'    => $total,
                'received' => round($recibido, 2),
                'orders'   => $cobrables->pluck('id')->values(),
            ], 422);
        }

        $sobrante = round($recibido - $total, 2);
        $ultimo   = $cobrables->count() - 1;

        $pagos = DB::transaction(function () use ($cobrables, $data, $restaurantId, $request, $sobrante, $ultimo) {
            return $cobrables->map(function (Order $order, int $i) use ($data, $restaurantId, $request, $sobrante, $ultimo) {
                $excedente = $i === $ultimo ? $sobrante : 0;

                return Payment::create([
                    'restaurant_id' => $restaurantId,
                    'order_id'      => $order->id,
                    'cashier_id'    => $request->user()?->id,
                    'method'        => $data['method'],
                    'amount'        => round((float) $order->total + $excedente, 2),
                    // El vuelto solo tiene sentido en efectivo.
                    'change_amount' => $data['method'] === 'cash' ? $excedente : 0,
                    'reference'     => $data['reference'] ?? null,
                    'status'        => 'completed',
                    'notes'         => $data['notes'] ?? null,
                ]);
            })->all();
        });

        // Fuera de la transacción, como en el cobro suelto: cerrar descuenta
        // inventario y emite eventos. La mesa se libera sola al cerrar el
        // último, porque `close()` mira si queda algo abierto.
        foreach ($cobrables as $order) {
            if ($order->status !== 'closed') {
                $this->orders->close($order);
            }
        }

        return response()->json([
            'payments' => $pagos,
            'total'    => $total,
            'change'   => $data['method'] === 'cash' ? $sobrante : 0,
            'orders'   => $cobrables->pluck('id')->values(),
            'table'    => ['id' => $table->id, 'number' => $table->number, 'status' => $table->fresh()->status],
            // Lo que la mesa sigue debiendo. En un cobro parcial la caja tiene
            // que saber a quién le falta pagar sin volver a preguntar.
            'pending'  => Order::repartoPorComensal(
                Order::where('restaurant_id', $restaurantId)
                    ->where('table_id', $table->id)
                    ->whereIn('status', Order::ACTIVOS)
                    ->doesntHave('payment')
                    ->oldest()
                    ->get(),
            ),
        ], 201);
    }
}
