<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Tag;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Módulos de la API, en el orden en que conviene leerlos.
     *
     * Scramble etiqueta cada operación con el nombre del controlador, pero deja
     * vacío el array `tags` de la raíz del documento — y de ahí es de donde los
     * visores construyen su índice, así que solo aparecía el primer grupo.
     */
    private const MODULOS = [
        'Auth'              => 'Registro de restaurantes, inicio de sesión y sesión actual.',
        'Menu'              => 'Menú público que ve el cliente al escanear el QR. No requiere token.',
        'Category'          => 'Categorías de la carta.',
        'Product'           => 'Productos, con subida de imagen y grupos de adicionales.',
        'ProductIngredient' => 'Receta de cada producto: lo que hace posible descontar inventario al vender.',
        'AdditionalGroup'   => 'Grupos de adicionales y sus opciones.',
        'Zone'              => 'Zonas del local (salón, terraza).',
        'Table'             => 'Mesas y su código QR.',
        'Order'             => 'Pedidos y su máquina de estados.',
        'Payment'           => 'Cobro y cierre del pedido.',
        'Customer'          => 'Clientes y su historial de consumo.',
        'Ingredient'        => 'Ingredientes del inventario.',
        'Inventory'         => 'Movimientos de stock y alertas de mínimo.',
        'Report'            => 'Reportes operativos: ventas por día, top de productos y resumen.',
        'Financial'         => 'Punto de equilibrio, proyección y panel financiero.',
        'FixedCost'         => 'Costos fijos que alimentan el punto de equilibrio.',
        'Settings'          => 'Datos y preferencias del restaurante.',
        'User'              => 'Personal del restaurante. Solo administradores.',
        'Whatsapp'          => 'Webhook del bot de pedidos por WhatsApp.',
        'Broadcast'         => 'Autorización de los canales privados de WebSocket.',
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Scramble::configure()->withDocumentTransformers(function (OpenApi $document) {
            $document->tags = $this->tagsOrdenados($document);
        });
    }

    /**
     * Declara solo las etiquetas que el documento usa de verdad, en el orden de
     * MODULOS. Las que aparezcan y no estén en la lista se añaden al final, así
     * un módulo nuevo nunca desaparece del índice por olvidar describirlo aquí.
     *
     * @return Tag[]
     */
    private function tagsOrdenados(OpenApi $document): array
    {
        $enUso = [];

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                foreach ($operation->tags as $tag) {
                    $enUso[$tag] = true;
                }
            }
        }

        $tags = [];

        foreach (self::MODULOS as $nombre => $descripcion) {
            if (isset($enUso[$nombre])) {
                $tags[] = new Tag($nombre, $descripcion);
                unset($enUso[$nombre]);
            }
        }

        foreach (array_keys($enUso) as $sinDescribir) {
            $tags[] = new Tag($sinDescribir);
        }

        return $tags;
    }
}
