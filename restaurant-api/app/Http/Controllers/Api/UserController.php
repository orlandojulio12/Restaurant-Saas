<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const ROLES = ['admin', 'waiter', 'kitchen', 'cashier'];

    public function index(Request $request): JsonResponse
    {
        $query = User::where('restaurant_id', $request->input('restaurant_id'));

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOL));
        }

        $users = $query->orderBy('name')->get();

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request));

        // El cast 'hashed' del modelo se encarga de encriptar la contraseña.
        $user = User::create([
            ...$data,
            'restaurant_id' => $request->input('restaurant_id'),
            'is_active'     => $data['is_active'] ?? true,
        ]);

        return response()->json($user, 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeRestaurant($request, $user);

        return response()->json($user);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeRestaurant($request, $user);

        $data = $request->validate($this->rules($request, $user));

        // Nadie puede quitarse a sí mismo el acceso: se quedaría fuera del panel.
        // Como además quien ejecuta esto es siempre un admin activo, esta única
        // regla garantiza que el restaurante nunca se queda sin administrador.
        if ($request->user()->id === $user->id) {
            if (array_key_exists('is_active', $data) && !$data['is_active']) {
                return $this->error('No puedes desactivar tu propia cuenta.');
            }

            if (isset($data['role']) && $data['role'] !== 'admin') {
                return $this->error('No puedes quitarte a ti mismo el rol de administrador.');
            }
        }

        // Sin password en el payload no se toca la actual.
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        // Nada comprueba is_active después del login: si no se revocan los
        // tokens, un usuario desactivado seguiría entrando con el que ya tenía.
        if (array_key_exists('is_active', $data) && !$data['is_active']) {
            $user->tokens()->delete();
        }

        return response()->json($user);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizeRestaurant($request, $user);

        if ($request->user()->id === $user->id) {
            return $this->error('No puedes eliminar tu propia cuenta.');
        }

        // shifts.user_id es RESTRICT: con turnos registrados el borrado falla.
        $turnos = $user->shifts()->count();

        if ($turnos > 0) {
            return $this->error(
                "El usuario tiene {$turnos} turno(s) registrados y no puede eliminarse. Desactívalo en su lugar."
            );
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(null, 204);
    }

    private function rules(Request $request, ?User $user = null): array
    {
        $creando = $user === null;

        return [
            'name'     => [$creando ? 'required' : 'sometimes', 'string', 'max:100'],
            'role'     => [$creando ? 'required' : 'sometimes', Rule::in(self::ROLES)],
            'password' => [$creando ? 'required' : 'nullable', 'string', 'min:8'],
            'avatar_url' => ['nullable', 'string', 'max:500'],
            'is_active'  => ['sometimes', 'boolean'],
            'email'      => [
                $creando ? 'required' : 'sometimes', 'email', 'max:150',
                // El email es único por restaurante, no globalmente.
                Rule::unique('users')
                    ->where('restaurant_id', $request->input('restaurant_id'))
                    ->ignore($user?->id),
            ],
        ];
    }

    private function error(string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje], 422);
    }

    private function authorizeRestaurant(Request $request, User $user): void
    {
        abort_if($user->restaurant_id !== $request->input('restaurant_id'), 403);
    }
}
