<?php

namespace Database\Seeders;

use App\Models\Additional;
use App\Models\AdditionalGroup;
use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class MenuSeeder extends Seeder
{
    /**
     * Fotos de demostración, en `database/seeders/fotos/`.
     *
     * Solo llevan licencias sin obligación de compartir igual —dominio público,
     * CC0 y CC BY—; la autoría de cada una está en `fotos/creditos.json`. Dos
     * platos se quedan sin foto a propósito: así el panel enseña también cómo
     * se ve un producto sin imagen, que es como empieza cualquier restaurante.
     */
    private const FOTOS = [
        'Empanadas x3'          => 'empanadas.jpg',
        'Bandeja Paisa'         => 'bandeja-paisa.jpg',
        'Arroz con Pollo'       => 'arroz-con-pollo.jpg',
        'Sobrebarriga al horno' => 'sobrebarriga.jpg',
        'Jugo Natural'          => 'jugo-natural.jpg',
        'Gaseosa'               => 'gaseosa.jpg',
        'Agua'                  => 'agua.jpg',
        'Tres Leches'           => 'tres-leches.jpg',
    ];

    public function run(): void
    {
        $restaurant = Restaurant::where('slug', 'el-rincon-de-prueba')->firstOrFail();

        // Categorías
        $categories = [
            ['name' => 'Entradas',       'sort_order' => 1],
            ['name' => 'Platos Fuertes', 'sort_order' => 2],
            ['name' => 'Bebidas',        'sort_order' => 3],
            ['name' => 'Postres',        'sort_order' => 4],
        ];

        $createdCategories = [];
        foreach ($categories as $data) {
            $createdCategories[$data['name']] = Category::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'name' => $data['name']],
                ['sort_order' => $data['sort_order'], 'is_active' => true]
            );
        }

        // Productos por categoría
        $menu = [
            'Entradas' => [
                ['name' => 'Patacón con hogao', 'price' => 8000,  'cost' => 2500, 'preparation_time' => 10],
                ['name' => 'Empanadas x3',      'price' => 7000,  'cost' => 2000, 'preparation_time' => 8],
            ],
            'Platos Fuertes' => [
                ['name' => 'Bandeja Paisa',          'price' => 25000, 'cost' => 9000, 'preparation_time' => 20],
                ['name' => 'Arroz con Pollo',         'price' => 18000, 'cost' => 6000, 'preparation_time' => 15],
                ['name' => 'Sobrebarriga al horno',   'price' => 22000, 'cost' => 8000, 'preparation_time' => 25],
            ],
            'Bebidas' => [
                ['name' => 'Jugo Natural', 'price' => 5000, 'cost' => 1500, 'preparation_time' => 5],
                ['name' => 'Gaseosa',      'price' => 3500, 'cost' => 1200, 'preparation_time' => 2],
                ['name' => 'Agua',         'price' => 2000, 'cost' => 500,  'preparation_time' => 1],
            ],
            'Postres' => [
                ['name' => 'Tres Leches',        'price' => 8000,  'cost' => 2500, 'preparation_time' => 5],
                ['name' => 'Brownie con helado', 'price' => 10000, 'cost' => 3500, 'preparation_time' => 7],
            ],
        ];

        $bebidasProducts = [];

        foreach ($menu as $categoryName => $products) {
            $category = $createdCategories[$categoryName];

            foreach ($products as $i => $data) {
                $product = Product::updateOrCreate(
                    ['restaurant_id' => $restaurant->id, 'name' => $data['name']],
                    [
                        'category_id'      => $category->id,
                        'price'            => $data['price'],
                        'cost'             => $data['cost'],
                        'preparation_time' => $data['preparation_time'],
                        'is_available'     => true,
                        'sort_order'       => $i + 1,
                    ]
                );

                $this->ponerFoto($product, $restaurant->id);

                if ($categoryName === 'Bebidas') {
                    $bebidasProducts[] = $product;
                }
            }
        }

        // Grupo de adicionales para bebidas
        $group = AdditionalGroup::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Tamaño'],
            [
                'selection_type' => 'single',
                'is_required'    => true,
                'sort_order'     => 1,
            ]
        );

        $additionals = [
            ['name' => 'Pequeño', 'extra_price' => 0,    'sort_order' => 1],
            ['name' => 'Grande',  'extra_price' => 2000,  'sort_order' => 2],
        ];

        foreach ($additionals as $data) {
            Additional::updateOrCreate(
                ['group_id' => $group->id, 'name' => $data['name']],
                ['extra_price' => $data['extra_price'], 'is_available' => true, 'sort_order' => $data['sort_order']]
            );
        }

        // Asociar grupo a productos de bebidas
        foreach ($bebidasProducts as $product) {
            $product->additionalGroups()->syncWithoutDetaching([$group->id]);
        }
    }

    /**
     * Copia la foto al disco público y deja la URL en el producto.
     *
     * Se hace aquí y no en el propio seeder de datos porque `storage/` está
     * fuera del repositorio: las imágenes viajan en `database/seeders/fotos/` y
     * se publican en cada `migrate:fresh --seed`.
     */
    private function ponerFoto(Product $product, int $restaurantId): void
    {
        $archivo = self::FOTOS[$product->name] ?? null;

        if (!$archivo) {
            return;
        }

        $origen = database_path("seeders/fotos/{$archivo}");

        if (!is_file($origen)) {
            return;
        }

        $ruta = "products/{$restaurantId}/{$archivo}";

        Storage::disk('public')->put($ruta, file_get_contents($origen));

        $product->update(['image_url' => Storage::disk('public')->url($ruta)]);
    }
}
