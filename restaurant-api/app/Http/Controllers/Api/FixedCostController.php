<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FixedCost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedCostController extends Controller
{
    private const CATEGORIAS   = ['rent', 'utilities', 'staff', 'supplies', 'marketing', 'other'];
    private const FRECUENCIAS  = ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly'];

    public function index(Request $request): JsonResponse
    {
        $query = FixedCost::where('restaurant_id', $request->input('restaurant_id'));

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOL));
        }

        $costs = $query->orderBy('category')->orderBy('name')->get();

        // El total mensual normalizado es lo que consume el punto de equilibrio;
        // devolverlo aquí evita que el frontend replique la conversión.
        $totalMensual = $costs->where('is_active', true)->sum(fn($c) => $c->monthlyAmount());

        return response()->json([
            'data'                => $costs,
            'monthly_total'       => round($totalMensual, 2),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $cost = FixedCost::create([
            ...$data,
            'restaurant_id' => $request->input('restaurant_id'),
            'category'      => $data['category']  ?? 'other',
            'frequency'     => $data['frequency'] ?? 'monthly',
            'is_active'     => $data['is_active'] ?? true,
        ]);

        return response()->json($cost, 201);
    }

    public function show(Request $request, FixedCost $fixedCost): JsonResponse
    {
        $this->authorizeRestaurant($request, $fixedCost);

        return response()->json($fixedCost);
    }

    public function update(Request $request, FixedCost $fixedCost): JsonResponse
    {
        $this->authorizeRestaurant($request, $fixedCost);

        $fixedCost->update($request->validate($this->rules(updating: true)));

        return response()->json($fixedCost);
    }

    public function destroy(Request $request, FixedCost $fixedCost): JsonResponse
    {
        $this->authorizeRestaurant($request, $fixedCost);

        // Nada referencia fixed_costs: el punto de equilibrio se recalcula solo.
        $fixedCost->delete();

        return response()->json(null, 204);
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name'      => [$required, 'string', 'max:150'],
            'amount'    => [$required, 'numeric', 'min:0'],
            'category'  => ['nullable', 'in:' . implode(',', self::CATEGORIAS)],
            'frequency' => ['nullable', 'in:' . implode(',', self::FRECUENCIAS)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function authorizeRestaurant(Request $request, FixedCost $cost): void
    {
        abort_if($cost->restaurant_id !== $request->input('restaurant_id'), 403);
    }
}
