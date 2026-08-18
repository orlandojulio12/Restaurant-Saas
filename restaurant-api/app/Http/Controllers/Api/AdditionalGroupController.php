<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Additional;
use App\Models\AdditionalGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdditionalGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $groups = AdditionalGroup::with(['additionals' => fn($q) => $q->orderBy('sort_order')->orderBy('name')])
            ->withCount('products')
            ->where('restaurant_id', $request->input('restaurant_id'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json($groups);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $group = DB::transaction(function () use ($data, $request) {
            $group = AdditionalGroup::create([
                'restaurant_id'  => $request->input('restaurant_id'),
                'name'           => $data['name'],
                'selection_type' => $data['selection_type'] ?? 'single',
                'is_required'    => $data['is_required'] ?? false,
                'sort_order'     => $data['sort_order'] ?? $this->nextSortOrder($request),
            ]);

            foreach ($data['additionals'] ?? [] as $i => $add) {
                $group->additionals()->create([
                    'name'         => $add['name'],
                    'extra_price'  => $add['extra_price']  ?? 0,
                    'is_available' => $add['is_available'] ?? true,
                    'sort_order'   => $add['sort_order']   ?? $i,
                ]);
            }

            return $group;
        });

        return response()->json($this->fresh($group), 201);
    }

    public function show(Request $request, AdditionalGroup $additionalGroup): JsonResponse
    {
        $this->authorizeRestaurant($request, $additionalGroup);

        return response()->json($this->fresh($additionalGroup));
    }

    public function update(Request $request, AdditionalGroup $additionalGroup): JsonResponse
    {
        $this->authorizeRestaurant($request, $additionalGroup);

        $data = $request->validate($this->rules(updating: true));

        DB::transaction(function () use ($data, $additionalGroup) {
            $additionalGroup->update(array_filter(
                [
                    'name'           => $data['name']           ?? null,
                    'selection_type' => $data['selection_type'] ?? null,
                    'is_required'    => $data['is_required']    ?? null,
                    'sort_order'     => $data['sort_order']     ?? null,
                ],
                fn($v) => $v !== null
            ));

            // Sin la clave 'additionals' no se toca la lista: permite editar solo
            // los datos del grupo sin tener que reenviar todos sus adicionales.
            if (array_key_exists('additionals', $data)) {
                $this->syncAdditionals($additionalGroup, $data['additionals'] ?? []);
            }
        });

        return response()->json($this->fresh($additionalGroup));
    }

    public function destroy(Request $request, AdditionalGroup $additionalGroup): JsonResponse
    {
        $this->authorizeRestaurant($request, $additionalGroup);

        // Borrar el grupo arrastra sus adicionales (cascade), pero los que ya se
        // pidieron están protegidos por order_item_additionals (RESTRICT): la
        // operación fallaría a nivel de base de datos.
        $usados = Additional::where('group_id', $additionalGroup->id)
            ->whereHas('orderItemAdditionals')
            ->count();

        if ($usados > 0) {
            return response()->json([
                'message' => "No se puede eliminar: {$usados} adicional(es) del grupo ya aparecen en pedidos. "
                    . 'Desactívalos o desvincula el grupo de los productos.',
            ], 422);
        }

        $additionalGroup->delete();

        return response()->json(null, 204);
    }

    /**
     * Deja la lista de adicionales igual a la recibida.
     *
     * Los que traen id se actualizan, los nuevos se crean y los ausentes se
     * eliminan — salvo que ya se hayan pedido, en cuyo caso solo se desactivan
     * para no romper el histórico.
     */
    private function syncAdditionals(AdditionalGroup $group, array $additionals): void
    {
        $enviadosConId = collect($additionals)->pluck('id')->filter()->all();

        $sobrantes = Additional::where('group_id', $group->id)
            ->whereNotIn('id', $enviadosConId)
            ->withCount('orderItemAdditionals')
            ->get();

        foreach ($sobrantes as $sobrante) {
            $sobrante->order_item_additionals_count > 0
                ? $sobrante->update(['is_available' => false])
                : $sobrante->delete();
        }

        foreach ($additionals as $i => $add) {
            $payload = [
                'name'         => $add['name'],
                'extra_price'  => $add['extra_price']  ?? 0,
                'is_available' => $add['is_available'] ?? true,
                'sort_order'   => $add['sort_order']   ?? $i,
            ];

            if (!empty($add['id'])) {
                // Acotado al grupo: impide colar el id de un adicional ajeno.
                Additional::where('id', $add['id'])
                    ->where('group_id', $group->id)
                    ->update($payload);

                continue;
            }

            $group->additionals()->create($payload);
        }
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name'                     => [$required, 'string', 'max:100'],
            'selection_type'           => ['nullable', 'in:single,multiple'],
            'is_required'              => ['nullable', 'boolean'],
            'sort_order'               => ['nullable', 'integer', 'min:0'],
            'additionals'              => ['sometimes', 'array'],
            'additionals.*.id'         => ['nullable', 'integer'],
            'additionals.*.name'       => ['required', 'string', 'max:100'],
            'additionals.*.extra_price'=> ['nullable', 'numeric', 'min:0'],
            'additionals.*.is_available' => ['nullable', 'boolean'],
            'additionals.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function fresh(AdditionalGroup $group): AdditionalGroup
    {
        return $group->load(['additionals' => fn($q) => $q->orderBy('sort_order')->orderBy('name')])
            ->loadCount('products');
    }

    private function nextSortOrder(Request $request): int
    {
        return (int) AdditionalGroup::where('restaurant_id', $request->input('restaurant_id'))
            ->max('sort_order') + 1;
    }

    private function authorizeRestaurant(Request $request, AdditionalGroup $group): void
    {
        abort_if($group->restaurant_id !== $request->input('restaurant_id'), 403);
    }
}
