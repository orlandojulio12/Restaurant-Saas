<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Restaurant;
use App\Models\RestaurantSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /** Ajustes con los que arranca todo restaurante nuevo. */
    private const AJUSTES_INICIALES = [
        'mode'          => 'tables',
        'tax_percent'   => '0',
        'print_kitchen' => '1',
        'notify_sound'  => '1',
    ];

    /**
     * Registrar un restaurante y su primer administrador.
     *
     * Ruta pública: es la puerta de entrada al servicio. Devuelve un token, así
     * que quien se registra queda dentro sin tener que iniciar sesión aparte.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'restaurant_name' => ['required', 'string', 'max:150'],
            // Es la URL del menú público y va impresa en los QR: si no la
            // eligen, se deriva del nombre.
            'slug'            => ['nullable', 'string', 'max:150', 'alpha_dash', 'unique:restaurants,slug'],
            'phone'           => ['nullable', 'string', 'max:20'],
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'address'         => ['nullable', 'string', 'max:255'],
            'city'            => ['nullable', 'string', 'max:100'],
            'country'         => ['nullable', 'string', 'max:60'],
            'currency'        => ['nullable', 'string', 'max:10'],
            'timezone'        => ['nullable', 'string', 'max:50', 'timezone'],

            'admin_name'      => ['required', 'string', 'max:100'],
            'email'           => ['required', 'email', 'max:150'],
            'password'        => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $plan = Plan::where('name', 'free')->where('is_active', true)->first();

        if (!$plan) {
            return response()->json([
                'message' => 'No hay planes disponibles para registrarse. Contacta con soporte.',
            ], 503);
        }

        $user = DB::transaction(function () use ($data, $plan) {
            $restaurant = Restaurant::create([
                'plan_id'         => $plan->id,
                'name'            => $data['restaurant_name'],
                'slug'            => $data['slug'] ?? $this->slugDisponible($data['restaurant_name']),
                'phone'           => $data['phone'] ?? null,
                'whatsapp_number' => $data['whatsapp_number'] ?? null,
                'address'         => $data['address'] ?? null,
                'city'            => $data['city'] ?? null,
                'country'         => $data['country']  ?? 'CO',
                'currency'        => $data['currency'] ?? 'COP',
                'timezone'        => $data['timezone'] ?? 'America/Bogota',
                'is_active'       => true,
            ]);

            foreach (self::AJUSTES_INICIALES as $clave => $valor) {
                RestaurantSetting::create([
                    'restaurant_id' => $restaurant->id,
                    'key_name'      => $clave,
                    'value'         => $valor,
                ]);
            }

            return User::create([
                'restaurant_id' => $restaurant->id,
                'name'          => $data['admin_name'],
                'email'         => $data['email'],
                'password'      => $data['password'],
                'role'          => 'admin',
                'is_active'     => true,
            ]);
        });

        $user->load('restaurant.plan');
        $user->update(['last_login_at' => now()]);

        return response()->json(
            $this->sesion($user, $user->createToken('auth_token')->plainTextToken),
            201
        );
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            // Opcionales: solo hacen falta si el mismo correo administra más de
            // un restaurante.
            'restaurant_id'   => 'nullable|integer|exists:restaurants,id',
            'restaurant_slug' => 'nullable|string|exists:restaurants,slug',
        ]);

        $restaurantId = $request->restaurant_id;

        if (!$restaurantId && $request->restaurant_slug) {
            $restaurantId = Restaurant::where('slug', $request->restaurant_slug)->value('id');
        }

        $query = User::with(['restaurant.plan'])->where('email', $request->email);

        if ($restaurantId) {
            $query->where('restaurant_id', $restaurantId);
        }

        // El correo es único por restaurante, no globalmente: el mismo puede
        // administrar varios. Antes se tomaba el primero que apareciera, así que
        // con dos cuentas la contraseña correcta podía dar «credenciales
        // incorrectas» según cuál saliera primero.
        $candidatos = $query->get()->filter(
            fn(User $u) => Hash::check($request->password, $u->password)
        );

        if ($candidatos->isEmpty()) {
            return response()->json(['message' => 'Credenciales incorrectas.'], 401);
        }

        if ($candidatos->count() > 1) {
            // La contraseña ya está verificada, así que enumerar los
            // restaurantes a los que pertenece no revela nada de terceros.
            return response()->json([
                'message'     => 'Este correo administra varios restaurantes. Indica cuál.',
                'restaurants' => $candidatos->map(fn(User $u) => [
                    'id'   => $u->restaurant->id,
                    'name' => $u->restaurant->name,
                    'slug' => $u->restaurant->slug,
                ])->values(),
            ], 409);
        }

        $user = $candidatos->first();

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Tu cuenta está desactivada. Contacta al administrador.',
            ], 403);
        }

        $user->update(['last_login_at' => now()]);

        return response()->json($this->sesion($user, $user->createToken('auth_token')->plainTextToken));
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['restaurant.plan']);

        return response()->json($this->sesion($user, null));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * Respuesta común de sesión: la usan registro, login y /auth/me.
     */
    private function sesion(User $user, ?string $token): array
    {
        $payload = [
            'user' => [
                'id'            => $user->id,
                'name'          => $user->name,
                'email'         => $user->email,
                'role'          => $user->role,
                'avatar_url'    => $user->avatar_url,
                'last_login_at' => $user->last_login_at,
            ],
            'restaurant' => [
                'id'       => $user->restaurant->id,
                'name'     => $user->restaurant->name,
                'slug'     => $user->restaurant->slug,
                'currency' => $user->restaurant->currency,
                'timezone' => $user->restaurant->timezone,
                'logo_url' => $user->restaurant->logo_url,
                'plan'     => [
                    'name'           => $user->restaurant->plan->name,
                    'display_name'   => $user->restaurant->plan->display_name,
                    'has_whatsapp'   => (bool) $user->restaurant->plan->has_whatsapp,
                    'has_inventory'  => (bool) $user->restaurant->plan->has_inventory,
                    'has_reports'    => (bool) $user->restaurant->plan->has_reports,
                    'has_financials' => (bool) $user->restaurant->plan->has_financials,
                ],
            ],
        ];

        return $token ? ['token' => $token, ...$payload] : $payload;
    }

    /**
     * Deriva un slug del nombre y le añade sufijo hasta que quede libre.
     */
    private function slugDisponible(string $nombre): string
    {
        $base = Str::slug($nombre) ?: 'restaurante';
        $slug = $base;
        $n    = 2;

        while (Restaurant::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }
}
