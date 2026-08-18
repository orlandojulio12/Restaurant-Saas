<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $zones = Zone::withCount('tables')
            ->where('restaurant_id', $request->input('restaurant_id'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json($zones);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $zone = Zone::create([
            ...$data,
            'restaurant_id' => $request->input('restaurant_id'),
            'sort_order'    => $data['sort_order'] ?? $this->nextSortOrder($request),
        ]);

        return response()->json($zone->loadCount('tables'), 201);
    }

    public function show(Request $request, Zone $zone): JsonResponse
    {
        $this->authorizeRestaurant($request, $zone);

        return response()->json($zone->load('tables')->loadCount('tables'));
    }

    public function update(Request $request, Zone $zone): JsonResponse
    {
        $this->authorizeRestaurant($request, $zone);

        $data = $request->validate([
            'name'       => ['sometimes', 'required', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $zone->update($data);

        return response()->json($zone->loadCount('tables'));
    }

    public function destroy(Request $request, Zone $zone): JsonResponse
    {
        $this->authorizeRestaurant($request, $zone);

        // tables.zone_id es nullOnDelete: borrar la zona no rompe nada, pero deja
        // las mesas sin ubicación en silencio. Se exige confirmación explícita.
        $tableCount = $zone->tables()->count();
        $force      = filter_var($request->input('force', false), FILTER_VALIDATE_BOOL);

        if ($tableCount > 0 && !$force) {
            return response()->json([
                'message'     => "La zona tiene {$tableCount} mesa(s). Si la eliminas, quedarán sin zona asignada.",
                'tables_count' => $tableCount,
                'hint'        => 'Repite la petición con force=true para confirmar.',
            ], 422);
        }

        $zone->delete();

        return response()->json(null, 204);
    }

    private function nextSortOrder(Request $request): int
    {
        return (int) Zone::where('restaurant_id', $request->input('restaurant_id'))
            ->max('sort_order') + 1;
    }

    private function authorizeRestaurant(Request $request, Zone $zone): void
    {
        abort_if($zone->restaurant_id !== $request->input('restaurant_id'), 403);
    }
}
