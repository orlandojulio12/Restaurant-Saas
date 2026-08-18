<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Category::withCount('products')
            ->where('restaurant_id', $request->input('restaurant_id'));

        // El menú público solo muestra activas; la gestión las quiere todas.
        if ($request->filled('only_active')) {
            $query->where('is_active', filter_var($request->input('only_active'), FILTER_VALIDATE_BOOL));
        }

        $categories = $query->orderBy('sort_order')->orderBy('name')->get();

        return response()->json($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'image_url'   => ['nullable', 'string', 'max:500'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $category = Category::create([
            ...$data,
            'restaurant_id' => $request->input('restaurant_id'),
            'sort_order'    => $data['sort_order'] ?? $this->nextSortOrder($request),
            'is_active'     => $data['is_active']  ?? true,
        ]);

        return response()->json($category->loadCount('products'), 201);
    }

    public function show(Request $request, Category $category): JsonResponse
    {
        $this->authorizeRestaurant($request, $category);

        return response()->json($category->loadCount('products'));
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorizeRestaurant($request, $category);

        $data = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'image_url'   => ['nullable', 'string', 'max:500'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $category->update($data);

        return response()->json($category->loadCount('products'));
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorizeRestaurant($request, $category);

        // products.category_id no tiene cascade: borrar arrastraría un error de
        // integridad. Se avisa y se sugiere desactivar en su lugar.
        $productCount = $category->products()->count();

        if ($productCount > 0) {
            return response()->json([
                'message' => "No se puede eliminar: la categoría tiene {$productCount} producto(s). "
                    . 'Muévelos a otra categoría o desactiva la categoría.',
            ], 422);
        }

        $category->delete();

        return response()->json(null, 204);
    }

    private function nextSortOrder(Request $request): int
    {
        return (int) Category::where('restaurant_id', $request->input('restaurant_id'))
            ->max('sort_order') + 1;
    }

    private function authorizeRestaurant(Request $request, Category $category): void
    {
        abort_if($category->restaurant_id !== $request->input('restaurant_id'), 403);
    }
}
