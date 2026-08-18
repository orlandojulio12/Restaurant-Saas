<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\AdditionalGroup;
use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Services\ImageService;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function __construct(
        private readonly ImageService $images,
        private readonly PlanService $plans,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->input('restaurant_id');

        $query = Product::with(['additionalGroups.additionals'])
            ->where('restaurant_id', $restaurantId);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->has('is_available')) {
            $query->where('is_available', filter_var($request->input('is_available'), FILTER_VALIDATE_BOOL));
        }

        $products = $query->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(ProductResource::collection($products));
    }

    public function store(Request $request): JsonResponse
    {
        $restaurantId = $request->input('restaurant_id');
        $data         = $request->validate($this->rules());

        $this->plans->assertRoomFor(Restaurant::findOrFail($restaurantId), 'products');

        $this->assertCategoryBelongs($data['category_id'], $restaurantId);

        $product = DB::transaction(function () use ($data, $request, $restaurantId) {
            $product = Product::create([
                'restaurant_id'    => $restaurantId,
                'category_id'      => $data['category_id'],
                'name'             => $data['name'],
                'description'      => $data['description'] ?? null,
                'price'            => $data['price'],
                'cost'             => $data['cost'] ?? 0,
                'preparation_time' => $data['preparation_time'] ?? 0,
                'is_available'     => $data['is_available'] ?? true,
                'sort_order'       => $data['sort_order'] ?? $this->nextSortOrder($restaurantId),
                'image_url'        => $request->hasFile('image')
                    ? $this->images->store($request->file('image'), "products/{$restaurantId}")
                    : ($data['image_url'] ?? null),
            ]);

            $this->syncGroups($product, $data['additional_group_ids'] ?? null, $restaurantId);

            return $product;
        });

        return response()->json(new ProductResource($this->fresh($product)), 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorizeRestaurant($request, $product);

        return response()->json(new ProductResource($this->fresh($product)));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeRestaurant($request, $product);

        $restaurantId = $request->input('restaurant_id');
        $data         = $request->validate($this->rules(updating: true));

        if (isset($data['category_id'])) {
            $this->assertCategoryBelongs($data['category_id'], $restaurantId);
        }

        DB::transaction(function () use ($data, $request, $product, $restaurantId) {
            $anterior = $product->image_url;

            $campos = collect($data)
                ->only(['category_id', 'name', 'description', 'price', 'cost', 'preparation_time', 'is_available', 'sort_order', 'image_url'])
                ->all();

            if ($request->hasFile('image')) {
                $campos['image_url'] = $this->images->store($request->file('image'), "products/{$restaurantId}");
            }

            $product->update($campos);

            // La imagen vieja se borra después de persistir la nueva, para no
            // quedarse sin ninguna si la escritura falla.
            if (isset($campos['image_url']) && $campos['image_url'] !== $anterior) {
                $this->images->delete($anterior);
            }

            if (array_key_exists('additional_group_ids', $data)) {
                $this->syncGroups($product, $data['additional_group_ids'] ?? [], $restaurantId);
            }
        });

        return response()->json(new ProductResource($this->fresh($product)));
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeRestaurant($request, $product);

        // order_items.product_id es RESTRICT: un producto ya vendido no puede
        // borrarse sin romper el histórico de pedidos.
        $vendido = DB::table('order_items')->where('product_id', $product->id)->count();

        if ($vendido > 0) {
            return response()->json([
                'message' => "No se puede eliminar: el producto aparece en {$vendido} pedido(s). "
                    . 'Márcalo como no disponible para retirarlo de la carta.',
            ], 422);
        }

        $imagen = $product->image_url;
        $product->delete();
        $this->images->delete($imagen);

        return response()->json(null, 204);
    }

    private function syncGroups(Product $product, ?array $groupIds, int $restaurantId): void
    {
        if ($groupIds === null) {
            return;
        }

        // Solo grupos del propio restaurante: descarta ids ajenos en el payload.
        $validos = AdditionalGroup::whereIn('id', $groupIds)
            ->where('restaurant_id', $restaurantId)
            ->pluck('id');

        $product->additionalGroups()->sync($validos);
    }

    private function assertCategoryBelongs(int $categoryId, int $restaurantId): void
    {
        $existe = Category::where('id', $categoryId)
            ->where('restaurant_id', $restaurantId)
            ->exists();

        abort_unless($existe, 422, 'La categoría no pertenece a este restaurante.');
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'category_id'            => [$required, 'integer'],
            'name'                   => [$required, 'string', 'max:150'],
            'price'                  => [$required, 'numeric', 'min:0'],
            'description'            => ['nullable', 'string'],
            'cost'                   => ['nullable', 'numeric', 'min:0'],
            'preparation_time'       => ['nullable', 'integer', 'min:0'],
            'is_available'           => ['nullable', 'boolean'],
            'sort_order'             => ['nullable', 'integer', 'min:0'],
            'image'                  => ['nullable', 'image', 'max:5120'],   // 5 MB
            'image_url'              => ['nullable', 'string', 'max:500'],
            'additional_group_ids'   => ['sometimes', 'nullable', 'array'],
            'additional_group_ids.*' => ['integer'],
        ];
    }

    private function fresh(Product $product): Product
    {
        return $product->load('additionalGroups.additionals');
    }

    private function nextSortOrder(int $restaurantId): int
    {
        return (int) Product::where('restaurant_id', $restaurantId)->max('sort_order') + 1;
    }

    private function authorizeRestaurant(Request $request, Product $product): void
    {
        abort_if($product->restaurant_id !== $request->input('restaurant_id'), 403);
    }
}
