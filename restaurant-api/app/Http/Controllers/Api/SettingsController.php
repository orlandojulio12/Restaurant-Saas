<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantSetting;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    /**
     * Ajustes admitidos en restaurant_settings, con su tipo.
     *
     * La columna `value` es TEXT: sin este mapa todo volvería como string
     * ("1", "0") y el frontend tendría que adivinar el tipo.
     */
    private const SETTINGS = [
        'mode'          => 'string',   // tables | counter | delivery
        'tax_percent'   => 'float',
        'print_kitchen' => 'bool',
        'notify_sound'  => 'bool',
        // Si el pedido del QR pasa por el mesero antes de bajar a cocina.
        'qr_confirm'    => 'bool',
    ];

    private const DEFAULTS = [
        'mode'          => 'tables',
        'tax_percent'   => 0,
        'print_kitchen' => true,
        'notify_sound'  => true,
        // Activado por defecto: que un desconocido mande comanda directa a
        // cocina debe ser una decisión consciente del restaurante.
        'qr_confirm'    => true,
    ];

    public function __construct(private readonly ImageService $images) {}

    public function index(Request $request): JsonResponse
    {
        $restaurant = Restaurant::with('plan')->findOrFail($request->input('restaurant_id'));

        return response()->json($this->payload($restaurant));
    }

    public function update(Request $request): JsonResponse
    {
        $restaurant = Restaurant::with('plan')->findOrFail($request->input('restaurant_id'));

        if ($desconocidos = $this->unknownSettings($request)) {
            return response()->json([
                'message' => 'Ajustes no reconocidos: ' . implode(', ', $desconocidos),
                'allowed' => array_keys(self::SETTINGS),
            ], 422);
        }

        $data = $request->validate([
            // slug queda fuera a propósito: es la URL del menú público y
            // cambiarlo invalidaría los QR ya impresos en las mesas.
            'name'            => ['sometimes', 'required', 'string', 'max:150'],
            'phone'           => ['nullable', 'string', 'max:20'],
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'address'         => ['nullable', 'string', 'max:255'],
            'city'            => ['nullable', 'string', 'max:100'],
            'country'         => ['nullable', 'string', 'max:60'],
            'currency'        => ['nullable', 'string', 'max:10'],
            'timezone'        => ['nullable', 'string', 'max:50', 'timezone'],
            'logo_url'        => ['nullable', 'string', 'max:500'],
            'logo'            => ['nullable', 'image', 'max:5120'],

            'settings'                 => ['sometimes', 'array'],
            'settings.mode'            => ['nullable', 'in:tables,counter,delivery'],
            'settings.tax_percent'     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.print_kitchen'   => ['nullable', 'boolean'],
            'settings.notify_sound'    => ['nullable', 'boolean'],
            'settings.qr_confirm'      => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $request, $restaurant) {
            $campos = collect($data)
                ->only(['name', 'phone', 'whatsapp_number', 'address', 'city', 'country', 'currency', 'timezone', 'logo_url'])
                ->all();

            if ($request->hasFile('logo')) {
                $anterior            = $restaurant->logo_url;
                $campos['logo_url']  = $this->images->store($request->file('logo'), "logos/{$restaurant->id}");
                $restaurant->update($campos);
                $this->images->delete($anterior);
            } elseif ($campos) {
                $restaurant->update($campos);
            }

            foreach ($data['settings'] ?? [] as $key => $value) {
                RestaurantSetting::updateOrCreate(
                    ['restaurant_id' => $restaurant->id, 'key_name' => $key],
                    ['value' => $this->toStorage($value)]
                );
            }
        });

        return response()->json($this->payload($restaurant->fresh('plan')));
    }

    private function payload(Restaurant $restaurant): array
    {
        return [
            'restaurant' => [
                'id'              => $restaurant->id,
                'name'            => $restaurant->name,
                'slug'            => $restaurant->slug,
                'phone'           => $restaurant->phone,
                'whatsapp_number' => $restaurant->whatsapp_number,
                'address'         => $restaurant->address,
                'city'            => $restaurant->city,
                'country'         => $restaurant->country,
                'logo_url'        => $restaurant->logo_url,
                'currency'        => $restaurant->currency,
                'timezone'        => $restaurant->timezone,
            ],
            'plan' => [
                'name'           => $restaurant->plan?->name,
                'display_name'   => $restaurant->plan?->display_name,
                'has_whatsapp'   => (bool) $restaurant->plan?->has_whatsapp,
                'has_inventory'  => (bool) $restaurant->plan?->has_inventory,
                'has_reports'    => (bool) $restaurant->plan?->has_reports,
                'has_financials' => (bool) $restaurant->plan?->has_financials,
            ],
            'settings' => $this->settings($restaurant),
        ];
    }

    /**
     * Ajustes guardados, con los valores por defecto de los que aún no existen.
     */
    private function settings(Restaurant $restaurant): array
    {
        $guardados = RestaurantSetting::where('restaurant_id', $restaurant->id)
            ->pluck('value', 'key_name');

        $salida = [];

        foreach (self::SETTINGS as $key => $tipo) {
            $salida[$key] = $guardados->has($key)
                ? $this->cast($guardados[$key], $tipo)
                : self::DEFAULTS[$key];
        }

        return $salida;
    }

    private function cast(?string $value, string $tipo): string|float|bool|null
    {
        return match ($tipo) {
            'bool'  => filter_var($value, FILTER_VALIDATE_BOOL),
            'float' => (float) $value,
            default => $value,
        };
    }

    private function toStorage(mixed $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    /**
     * @return string[] claves enviadas que no están en la lista blanca
     */
    private function unknownSettings(Request $request): array
    {
        $enviados = $request->input('settings');

        return is_array($enviados)
            ? array_values(array_diff(array_keys($enviados), array_keys(self::SETTINGS)))
            : [];
    }
}
