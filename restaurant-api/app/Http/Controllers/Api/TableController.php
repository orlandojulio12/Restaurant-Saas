<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\TableResource;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TableController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    public function index(Request $request): JsonResponse
    {
        $tables = RestaurantTable::with(['zone', 'orders' => fn($q) =>
                $q->whereNotIn('status', ['closed', 'cancelled'])
                  ->with('items')
            ])
            ->where('restaurant_id', $request->input('restaurant_id'))
            ->orderBy('number')
            ->get();

        return response()->json(TableResource::collection($tables));
    }

    public function store(Request $request): JsonResponse
    {
        $this->plans->assertRoomFor(
            Restaurant::findOrFail($request->input('restaurant_id')),
            'tables'
        );

        // number es string(20) en la base: admite "A1" o "Terraza-3", no solo
        // enteros. Y es único por restaurante.
        $data = $request->validate([
            'zone_id'  => ['nullable', 'integer'],
            'number'   => [
                'required', 'string', 'max:20',
                Rule::unique('tables')->where('restaurant_id', $request->input('restaurant_id')),
            ],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'status'   => ['nullable', 'in:available,occupied,reserved,disabled'],
        ]);

        $table = RestaurantTable::create(array_merge($data, [
            'restaurant_id' => $request->input('restaurant_id'),
            'qr_code'       => \Illuminate\Support\Str::uuid()->toString(),
            'status'        => $data['status'] ?? 'available',
        ]));

        $table->load('zone');

        return response()->json(new TableResource($table), 201);
    }

    public function show(Request $request, RestaurantTable $table): JsonResponse
    {
        abort_if($table->restaurant_id !== $request->input('restaurant_id'), 403);
        $table->load(['zone', 'orders' => fn($q) =>
            $q->whereNotIn('status', ['closed', 'cancelled'])->with('items')
        ]);

        return response()->json(new TableResource($table));
    }

    public function update(Request $request, RestaurantTable $table): JsonResponse
    {
        abort_if($table->restaurant_id !== $request->input('restaurant_id'), 403);

        $data = $request->validate([
            'zone_id'  => ['nullable', 'integer'],
            'number'   => [
                'sometimes', 'required', 'string', 'max:20',
                Rule::unique('tables')
                    ->where('restaurant_id', $request->input('restaurant_id'))
                    ->ignore($table->id),
            ],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'status'   => ['nullable', 'in:available,occupied,reserved,disabled'],
        ]);

        $table->update($data);

        return response()->json(new TableResource($table));
    }

    public function destroy(Request $request, RestaurantTable $table): JsonResponse
    {
        abort_if($table->restaurant_id !== $request->input('restaurant_id'), 403);
        $table->delete();

        return response()->json(null, 204);
    }

    public function activeOrders(Request $request, RestaurantTable $table): JsonResponse
    {
        abort_if($table->restaurant_id !== $request->input('restaurant_id'), 403);

        $orders = Order::with(['items.additionals', 'user'])
            ->where('table_id', $table->id)
            ->whereIn('status', Order::ACTIVOS)
            ->latest()
            ->get();

        return response()->json(OrderResource::collection($orders));
    }
}