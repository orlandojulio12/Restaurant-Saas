<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function public(Request $request, string $restaurantSlug): JsonResponse
    {
        $restaurant = Restaurant::where('slug', $restaurantSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $categories = Category::with([
                'products' => fn($q) => $q->where('is_available', true)
                    ->orderBy('sort_order')
                    ->with(['additionalGroups.additionals' => fn($q) => $q->where('is_available', true)]),
            ])
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // La mesa llega por su código único, no por id: el id es secuencial y
        // bastaría con cambiarlo en la URL para pedir a cuenta de otra mesa.
        $mesa = null;

        if ($request->filled('qr')) {
            $encontrada = RestaurantTable::where('restaurant_id', $restaurant->id)
                ->where('qr_code', $request->input('qr'))
                ->first();

            if ($encontrada) {
                $mesa = ['id' => $encontrada->id, 'number' => $encontrada->number];
            }
        }

        return response()->json([
            'restaurant' => [
                'id'          => $restaurant->id,
                'name'        => $restaurant->name,
                'logo_url'    => $restaurant->logo_url,
                // Nulos mientras el restaurante no configure su marca; la carta
                // cae entonces en la paleta por defecto.
                'brand_color' => $restaurant->brand_color,
                'cover_url'   => $restaurant->cover_url,
                'address'     => $restaurant->address,
                'currency'    => $restaurant->currency,
            ],
            // null si el QR no corresponde a ninguna mesa de este restaurante.
            'table'      => $mesa,
            'categories' => $categories->map(fn($cat) => [
                'id'       => $cat->id,
                'name'     => $cat->name,
                'image_url'=> $cat->image_url,
                'products' => $cat->products->map(fn($p) => [
                    'id'               => $p->id,
                    'name'             => $p->name,
                    'description'      => $p->description,
                    'image_url'        => $p->image_url,
                    'price'            => $p->price,
                    'preparation_time' => $p->preparation_time,
                    'additional_groups'=> $p->additionalGroups->map(fn($g) => [
                        'id'             => $g->id,
                        'name'           => $g->name,
                        'selection_type' => $g->selection_type,
                        'is_required'    => $g->is_required,
                        'additionals'    => $g->additionals->map(fn($a) => [
                            'id'          => $a->id,
                            'name'        => $a->name,
                            'extra_price' => $a->extra_price,
                        ]),
                    ]),
                ]),
            ]),
        ]);
    }

    /**
     * Lo que la mesa lleva pedido, para el propio comensal.
     *
     * Sin esto cada móvil pide a ciegas: tres personas sentadas juntas escanean
     * el mismo código, cada una arma su carrito por su cuenta y ninguna ve lo
     * que ya mandaron las demás. El resultado son limonadas duplicadas y gente
     * preguntándole al mesero si su plato entró.
     *
     * Se identifica por el código del QR, no por el id de la mesa: quien tiene
     * el código está sentado ahí. Aun así solo salen líneas, estados y totales
     * —nunca el teléfono ni el nombre del cliente registrado—, porque el código
     * está impreso a la vista de cualquiera que pase.
     */
    public function tableState(string $restaurantSlug, string $qrCode): JsonResponse
    {
        $restaurant = Restaurant::where('slug', $restaurantSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $mesa = RestaurantTable::where('restaurant_id', $restaurant->id)
            ->where('qr_code', $qrCode)
            ->firstOrFail();

        // La cuenta abierta de la mesa son sus pedidos activos: al cobrar pasan
        // a 'closed' y desaparecen de aquí solos. No hace falta más sesión.
        $pedidos = Order::with('items.additionals')
            ->where('restaurant_id', $restaurant->id)
            ->where('table_id', $mesa->id)
            ->whereIn('status', Order::ACTIVOS)
            ->oldest()
            ->get();

        return response()->json([
            'table'  => ['id' => $mesa->id, 'number' => $mesa->number],
            'orders' => $pedidos->values()->map(fn($pedido, $i) => [
                'id'         => $pedido->id,
                // Número de ronda dentro de la mesa, que es como lo cuenta el
                // comensal: "lo que pedimos primero" y no el id global.
                'round'      => $i + 1,
                'status'     => $pedido->status,
                'type'       => $pedido->type,
                'diner_name' => $pedido->diner_name,
                'total'      => $pedido->total,
                'created_at' => $pedido->created_at,
                'items'      => $pedido->items->map(fn($item) => [
                    'name'        => $item->product_name,
                    'quantity'    => $item->quantity,
                    'subtotal'    => $item->subtotal,
                    'additionals' => $item->additionals->pluck('additional_name'),
                ]),
            ]),
            // Lo que le toca a cada uno. El comensal quiere saber lo suyo, no
            // solo el total de la mesa, y el dato ya está: cada pedido trae su
            // `diner_name` porque cada quien pidió desde su propio móvil.
            'diners' => Order::repartoPorComensal($pedidos),
            // Sin impuestos ni descuentos: es una referencia para el comensal,
            // no la cuenta definitiva. Esa la cierra la caja.
            'total'  => round($pedidos->sum(fn($p) => (float) $p->total), 2),
        ]);
    }
}
