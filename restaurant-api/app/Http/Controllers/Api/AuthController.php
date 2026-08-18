<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            // restaurant_id o restaurant_slug son opcionales:
            // si no vienen, busca el usuario solo por email (útil para super-admin futuro)
            'restaurant_id'   => 'nullable|integer|exists:restaurants,id',
            'restaurant_slug' => 'nullable|string|exists:restaurants,slug',
        ]);

        // Resolver restaurant_id desde slug si viene el slug
        $restaurantId = $request->restaurant_id;

        if (!$restaurantId && $request->restaurant_slug) {
            $restaurant = \App\Models\Restaurant::where('slug', $request->restaurant_slug)->first();
            $restaurantId = $restaurant?->id;
        }

        // Buscar usuario
        $query = User::with(['restaurant.plan'])->where('email', $request->email);

        if ($restaurantId) {
            $query->where('restaurant_id', $restaurantId);
        }

        $user = $query->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Credenciales incorrectas.'
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Tu cuenta está desactivada. Contacta al administrador.'
            ], 403);
        }

        // Actualizar último login
        $user->update(['last_login_at' => now()]);

        // Generar token con nombre descriptivo
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'role'       => $user->role,
                'avatar_url' => $user->avatar_url,
            ],
            'token'      => $token,
            'restaurant' => [
                'id'       => $user->restaurant->id,
                'name'     => $user->restaurant->name,
                'slug'     => $user->restaurant->slug,
                'currency' => $user->restaurant->currency,
                'timezone' => $user->restaurant->timezone,
                'logo_url' => $user->restaurant->logo_url,
                'plan'     => [
                    'name'           => $user->restaurant->plan->name,
                    'has_whatsapp'   => $user->restaurant->plan->has_whatsapp,
                    'has_inventory'  => $user->restaurant->plan->has_inventory,
                    'has_reports'    => $user->restaurant->plan->has_reports,
                    'has_financials' => $user->restaurant->plan->has_financials,
                ],
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['restaurant.plan']);

        return response()->json([
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
                    'has_whatsapp'   => $user->restaurant->plan->has_whatsapp,
                    'has_inventory'  => $user->restaurant->plan->has_inventory,
                    'has_reports'    => $user->restaurant->plan->has_reports,
                    'has_financials' => $user->restaurant->plan->has_financials,
                ],
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent(); // 204
    }
}
