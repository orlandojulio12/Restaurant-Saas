<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::where('restaurant_id', $request->input('restaurant_id'));

        if ($request->filled('search')) {
            $search = $request->input('search');

            $query->where(fn($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }

        $orden = $request->input('sort', 'recent');

        match ($orden) {
            'top'  => $query->orderByDesc('total_spent'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('last_order_at')->orderByDesc('id'),
        };

        $customers = $query->paginate((int) $request->input('per_page', 20));

        return response()->json($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request));

        $customer = Customer::create([
            ...$data,
            'restaurant_id' => $request->input('restaurant_id'),
        ]);

        return response()->json($customer, 201);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeRestaurant($request, $customer);

        $customer->load(['orders' => fn($q) => $q->latest()->limit(10)]);

        return response()->json($customer);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeRestaurant($request, $customer);

        // total_orders, total_spent y last_order_at son derivados: los mantiene
        // PaymentController al cerrar un pedido, no se editan a mano.
        $data = $request->validate($this->rules($request, $customer));

        $customer->update($data);

        return response()->json($customer);
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeRestaurant($request, $customer);

        // orders.customer_id es nullOnDelete: los pedidos sobreviven, pero
        // pierden la referencia al cliente. Se pide confirmación explícita.
        $pedidos = $customer->orders()->count();
        $force   = filter_var($request->input('force', false), FILTER_VALIDATE_BOOL);

        if ($pedidos > 0 && !$force) {
            return response()->json([
                'message'       => "El cliente tiene {$pedidos} pedido(s). Si lo eliminas, quedarán sin cliente asociado.",
                'orders_count'  => $pedidos,
                'hint'          => 'Repite la petición con force=true para confirmar.',
            ], 422);
        }

        $customer->delete();

        return response()->json(null, 204);
    }

    private function rules(Request $request, ?Customer $customer = null): array
    {
        $required = $customer ? 'sometimes' : 'nullable';

        return [
            'name'    => [$required, 'nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'notes'   => ['nullable', 'string'],
            'phone'   => [
                'nullable', 'string', 'max:30',
                // El teléfono identifica al cliente dentro del restaurante,
                // pero puede repetirse entre restaurantes distintos.
                Rule::unique('customers')
                    ->where('restaurant_id', $request->input('restaurant_id'))
                    ->ignore($customer?->id),
            ],
        ];
    }

    private function authorizeRestaurant(Request $request, Customer $customer): void
    {
        abort_if($customer->restaurant_id !== $request->input('restaurant_id'), 403);
    }
}
