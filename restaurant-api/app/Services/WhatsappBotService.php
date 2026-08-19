<?php

namespace App\Services;

use App\Events\OrderCreated;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAdditional;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\WhatsappSession;
use Illuminate\Support\Facades\DB;

/**
 * Bot conversacional de pedidos por WhatsApp.
 *
 * Flujo: saludo → categoría → producto → adicionales → cantidad → carrito →
 * dirección → confirmación → pedido creado.
 *
 * El paso de adicionales se salta si el producto no tiene ninguno.
 *
 * El estado vive en whatsapp_sessions.state y el contexto (listas mostradas,
 * carrito, dirección) en whatsapp_sessions.context. Las listas que ve el
 * usuario se guardan en el contexto porque el "2" que responde solo tiene
 * sentido contra lo que se le mostró.
 */
class WhatsappBotService
{
    /** Tras este tiempo sin hablar, la conversación empieza de cero. */
    private const MINUTOS_EXPIRACION = 120;

    public function __construct(
        private readonly WhatsappService $whatsapp,
        private readonly PlanService $plans,
    ) {}

    public function handle(Restaurant $restaurant, string $phone, string $texto, ?string $messageId = null): void
    {
        $session = WhatsappSession::firstOrNew([
            'restaurant_id' => $restaurant->id,
            'phone'         => $phone,
        ]);

        // Meta reintenta los webhooks: sin esta guarda, un reintento avanzaría
        // la conversación dos veces.
        if ($messageId && ($session->context['last_message_id'] ?? null) === $messageId) {
            return;
        }

        if ($this->expirada($session)) {
            $session->state   = 'greeting';
            $session->context = [];
        }

        $texto   = trim($texto);
        $context = $session->context ?? [];
        $context['last_message_id'] = $messageId;

        // Atajos disponibles en cualquier punto de la conversación.
        $normalizado = $this->normalizar($texto);

        if (in_array($normalizado, ['menu', 'hola', 'inicio', 'buenas'], true)) {
            $session->state = 'greeting';
            $context = ['last_message_id' => $messageId];
        } elseif ($normalizado === 'cancelar') {
            $this->responder($phone, '❌ Pedido cancelado. Escribe *menú* cuando quieras empezar de nuevo.');
            $this->guardar($session, 'greeting', ['last_message_id' => $messageId]);

            return;
        }

        [$nuevoEstado, $context] = match ($session->state ?: 'greeting') {
            'greeting' => $this->saludar($restaurant, $phone, $context),
            'category' => $this->elegirCategoria($restaurant, $phone, $texto, $context),
            'product'  => $this->elegirProducto($restaurant, $phone, $texto, $context),
            'additionals' => $this->elegirAdicionales($restaurant, $phone, $texto, $context),
            'quantity' => $this->elegirCantidad($restaurant, $phone, $texto, $context),
            'cart'     => $this->decidirCarrito($restaurant, $phone, $texto, $context),
            'address'  => $this->recibirDireccion($restaurant, $phone, $texto, $context),
            'confirm'  => $this->confirmar($restaurant, $phone, $texto, $context, $session),
            default    => $this->saludar($restaurant, $phone, $context),
        };

        $this->guardar($session, $nuevoEstado, $context);
    }

    // ── Pasos de la conversación ────────────────────────────────────────────

    private function saludar(Restaurant $restaurant, string $phone, array $context): array
    {
        $categorias = $this->categorias($restaurant);

        if ($categorias->isEmpty()) {
            $this->responder($phone, "Hola 👋 Ahora mismo no tenemos productos disponibles. Vuelve a intentarlo más tarde.");

            return ['greeting', $context];
        }

        $lineas = ["👋 ¡Hola! Bienvenido a *{$restaurant->name}*.", '', '¿Qué te apetece? Responde con el número:', ''];

        foreach ($categorias as $i => $categoria) {
            $lineas[] = ($i + 1) . ". {$categoria->name}";
        }

        $lineas[] = '';
        $lineas[] = '_Escribe *cancelar* en cualquier momento para salir._';

        $this->responder($phone, implode("\n", $lineas));
        $context['categories'] = $categorias->pluck('id')->all();

        return ['category', $context];
    }

    private function elegirCategoria(Restaurant $restaurant, string $phone, string $texto, array $context): array
    {
        $ids   = $context['categories'] ?? [];
        $indice = $this->indiceElegido($texto, count($ids));

        if ($indice === null) {
            $this->responder($phone, "No entendí 🤔 Responde con el número de una categoría (1 a " . count($ids) . ").");

            return ['category', $context];
        }

        $productos = $this->productos($restaurant, $ids[$indice]);

        if ($productos->isEmpty()) {
            $this->responder($phone, 'Esa categoría no tiene productos disponibles. Elige otra.');

            return ['category', $context];
        }

        $categoria = Category::find($ids[$indice]);
        $lineas    = ["📂 *{$categoria->name}*", '', 'Elige un producto:', ''];

        foreach ($productos as $i => $producto) {
            $precio   = $this->dinero($producto->price, $restaurant);
            $lineas[] = ($i + 1) . ". {$producto->name} — {$precio}";
        }

        $this->responder($phone, implode("\n", $lineas));
        $context['products'] = $productos->pluck('id')->all();

        return ['product', $context];
    }

    private function elegirProducto(Restaurant $restaurant, string $phone, string $texto, array $context): array
    {
        $ids    = $context['products'] ?? [];
        $indice = $this->indiceElegido($texto, count($ids));

        if ($indice === null) {
            $this->responder($phone, "Responde con el número del producto (1 a " . count($ids) . ").");

            return ['product', $context];
        }

        $producto = Product::find($ids[$indice]);

        $context['pending'] = [
            'product_id'  => $producto->id,
            'name'        => $producto->name,
            'price'       => (float) $producto->price,
            'additionals' => [],
        ];

        // Solo se pregunta por adicionales si el producto tiene alguno; si no,
        // se va directo a la cantidad para no alargar la conversación.
        $grupos = $this->gruposDeAdicionales($producto);

        if ($grupos) {
            $context['pending']['groups']      = $grupos;
            $context['pending']['group_index'] = 0;

            $this->preguntarGrupo($phone, $restaurant, $grupos[0]);

            return ['additionals', $context];
        }

        $this->responder($phone, "¿Cuántas unidades de *{$producto->name}* quieres? Responde con un número.");

        return ['quantity', $context];
    }

    /**
     * Recorre los grupos de adicionales del producto, uno por mensaje.
     *
     * Las opciones van en el contexto por la misma razón que las demás listas:
     * el número que responde el cliente solo significa algo contra lo que se
     * le mostró.
     */
    private function elegirAdicionales(Restaurant $restaurant, string $phone, string $texto, array $context): array
    {
        $pendiente = $context['pending'] ?? null;

        if (!$pendiente || empty($pendiente['groups'])) {
            return $this->saludar($restaurant, $phone, $context);
        }

        $indice = $pendiente['group_index'] ?? 0;
        $grupo  = $pendiente['groups'][$indice] ?? null;

        if (!$grupo) {
            return $this->pedirCantidad($restaurant, $phone, $context);
        }

        $elegidos = $this->interpretarAdicionales($texto, $grupo);

        if ($elegidos === null) {
            $this->preguntarGrupo($phone, $restaurant, $grupo, reintento: true);

            return ['additionals', $context];
        }

        $pendiente['additionals'] = array_merge($pendiente['additionals'] ?? [], $elegidos);
        $pendiente['group_index'] = $indice + 1;
        $context['pending']       = $pendiente;

        $siguiente = $pendiente['groups'][$pendiente['group_index']] ?? null;

        if ($siguiente) {
            $this->preguntarGrupo($phone, $restaurant, $siguiente);

            return ['additionals', $context];
        }

        return $this->pedirCantidad($restaurant, $phone, $context);
    }

    private function elegirCantidad(Restaurant $restaurant, string $phone, string $texto, array $context): array
    {
        $cantidad = (int) filter_var($texto, FILTER_SANITIZE_NUMBER_INT);

        if ($cantidad < 1 || $cantidad > 50) {
            $this->responder($phone, 'Indica una cantidad entre 1 y 50.');

            return ['quantity', $context];
        }

        $pendiente = $context['pending'] ?? null;

        if (!$pendiente) {
            return $this->saludar($restaurant, $phone, $context);
        }

        $extras      = $pendiente['additionals'] ?? [];
        $extraUnidad = array_sum(array_column($extras, 'extra_price'));

        $carrito = $context['cart'] ?? [];

        // Solo lo que describe la línea: los grupos y el índice del recorrido
        // eran andamiaje del paso anterior y no pintan nada en el carrito.
        // El precio unitario ya lleva los adicionales sumados, igual que en
        // OrderController, para que el total cuadre entre ambos caminos.
        $carrito[] = [
            'product_id'  => $pendiente['product_id'],
            'name'        => $pendiente['name'],
            'base_price'  => $pendiente['price'],
            'price'       => $pendiente['price'] + $extraUnidad,
            'additionals' => $extras,
            'quantity'    => $cantidad,
        ];

        $context['cart'] = $carrito;
        unset($context['pending']);

        $this->responder($phone, implode("\n", [
            "✅ Agregado: {$cantidad} × {$pendiente['name']}",
            '',
            $this->resumenCarrito($carrito, $restaurant),
            '',
            '¿Qué quieres hacer?',
            '1. Agregar algo más',
            '2. Finalizar pedido',
        ]));

        return ['cart', $context];
    }

    private function decidirCarrito(Restaurant $restaurant, string $phone, string $texto, array $context): array
    {
        $opcion = $this->indiceElegido($texto, 2);

        if ($opcion === 0) {
            return $this->saludarSinBienvenida($restaurant, $phone, $context);
        }

        if ($opcion === 1) {
            $this->responder($phone, '📍 ¿A qué dirección lo enviamos? Escríbela con detalles (barrio, torre, apto).');

            return ['address', $context];
        }

        $this->responder($phone, 'Responde *1* para agregar algo más o *2* para finalizar.');

        return ['cart', $context];
    }

    private function recibirDireccion(Restaurant $restaurant, string $phone, string $texto, array $context): array
    {
        if (mb_strlen($texto) < 8) {
            $this->responder($phone, 'Necesito una dirección un poco más completa para poder llegar 🙏');

            return ['address', $context];
        }

        $context['address'] = $texto;
        $carrito            = $context['cart'] ?? [];

        $this->responder($phone, implode("\n", [
            '📋 *Resumen de tu pedido*',
            '',
            $this->resumenCarrito($carrito, $restaurant),
            '',
            "📍 Dirección: {$texto}",
            '',
            '1. Confirmar pedido',
            '2. Cancelar',
        ]));

        return ['confirm', $context];
    }

    private function confirmar(
        Restaurant $restaurant,
        string $phone,
        string $texto,
        array $context,
        WhatsappSession $session,
    ): array {
        $opcion = $this->indiceElegido($texto, 2);

        if ($opcion === 1) {
            $this->responder($phone, '❌ Pedido cancelado. Escribe *menú* para empezar de nuevo.');

            return ['greeting', ['last_message_id' => $context['last_message_id'] ?? null]];
        }

        if ($opcion !== 0) {
            $this->responder($phone, 'Responde *1* para confirmar o *2* para cancelar.');

            return ['confirm', $context];
        }

        if (empty($context['cart'])) {
            return $this->saludar($restaurant, $phone, $context);
        }

        if (!$this->plans->hasRoomFor($restaurant, 'daily_orders')) {
            $this->responder($phone, 'Hoy ya no podemos recibir más pedidos por este canal 😔 Escríbenos mañana.');

            return ['greeting', ['last_message_id' => $context['last_message_id'] ?? null]];
        }

        $order = $this->crearPedido($restaurant, $phone, $context, $session);

        $this->responder($phone, implode("\n", [
            "🎉 ¡Listo! Tu pedido *#{$order->id}* fue recibido.",
            '',
            $this->resumenCarrito($context['cart'], $restaurant),
            '',
            "📍 {$context['address']}",
            '',
            'Te avisamos cuando salga para tu dirección. ¡Gracias! 🙌',
        ]));

        return ['greeting', ['last_message_id' => $context['last_message_id'] ?? null]];
    }

    // ── Apoyo ───────────────────────────────────────────────────────────────

    private function crearPedido(Restaurant $restaurant, string $phone, array $context, WhatsappSession $session): Order
    {
        $customer = Customer::firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'phone' => $phone],
            ['name' => 'Cliente WhatsApp', 'address' => $context['address']]
        );

        $session->customer_id = $customer->id;

        $order = DB::transaction(function () use ($restaurant, $context, $customer) {
            $total = collect($context['cart'])->sum(fn($l) => $l['price'] * $l['quantity']);

            $order = Order::create([
                'restaurant_id'    => $restaurant->id,
                'customer_id'      => $customer->id,
                // Sin usuario: el pedido lo origina el cliente, no el personal.
                'user_id'          => null,
                'type'             => 'delivery',
                'status'           => 'pending',
                'delivery_address' => $context['address'],
                'notes'            => 'Pedido por WhatsApp',
                'subtotal'         => $total,
                'total'            => $total,
            ]);

            foreach ($context['cart'] as $linea) {
                $item = OrderItem::create([
                    'order_id'     => $order->id,
                    'product_id'   => $linea['product_id'],
                    'product_name' => $linea['name'],
                    'unit_price'   => $linea['price'],
                    'quantity'     => $linea['quantity'],
                    'subtotal'     => $linea['price'] * $linea['quantity'],
                ]);

                // Se desnormalizan nombre y precio, como en el resto del
                // sistema: el histórico no cambia si luego se edita la carta.
                foreach ($linea['additionals'] ?? [] as $extra) {
                    OrderItemAdditional::create([
                        'order_item_id'   => $item->id,
                        'additional_id'   => $extra['id'],
                        'additional_name' => $extra['name'],
                        'extra_price'     => $extra['extra_price'],
                    ]);
                }
            }

            return $order;
        });

        $order->load(['items.additionals', 'table', 'customer']);
        event(new OrderCreated($order));

        return $order;
    }

    /** Igual que saludar(), pero sin repetir la bienvenida al volver del carrito. */
    private function saludarSinBienvenida(Restaurant $restaurant, string $phone, array $context): array
    {
        $categorias = $this->categorias($restaurant);
        $lineas     = ['¿Qué más quieres agregar?', ''];

        foreach ($categorias as $i => $categoria) {
            $lineas[] = ($i + 1) . ". {$categoria->name}";
        }

        $this->responder($phone, implode("\n", $lineas));
        $context['categories'] = $categorias->pluck('id')->all();

        return ['category', $context];
    }

    /**
     * Pide la cantidad, recordando lo que se lleva elegido del producto.
     */
    private function pedirCantidad(Restaurant $restaurant, string $phone, array $context): array
    {
        $pendiente = $context['pending'];
        $extras    = $pendiente['additionals'] ?? [];

        $lineas = ["*{$pendiente['name']}*"];

        foreach ($extras as $extra) {
            $precio   = $extra['extra_price'] > 0
                ? ' (+' . $this->dinero($extra['extra_price'], $restaurant) . ')'
                : '';
            $lineas[] = "  + {$extra['name']}{$precio}";
        }

        $lineas[] = '';
        $lineas[] = '¿Cuántas unidades quieres? Responde con un número.';

        $this->responder($phone, implode("
", $lineas));

        return ['quantity', $context];
    }

    /**
     * Muestra las opciones de un grupo de adicionales.
     */
    private function preguntarGrupo(string $phone, Restaurant $restaurant, array $grupo, bool $reintento = false): void
    {
        $lineas = [];

        if ($reintento) {
            $lineas[] = 'No entendí tu respuesta.';
            $lineas[] = '';
        }

        $varias   = $grupo['selection_type'] === 'multiple';
        $lineas[] = "*{$grupo['name']}*" . ($varias ? ' _(puedes elegir varias)_' : ' _(elige una)_');
        $lineas[] = '';

        foreach ($grupo['options'] as $i => $opcion) {
            $precio   = $opcion['extra_price'] > 0
                ? ' — +' . $this->dinero($opcion['extra_price'], $restaurant)
                : '';
            $lineas[] = ($i + 1) . ". {$opcion['name']}{$precio}";
        }

        $lineas[] = '';
        $lineas[] = $varias
            ? 'Responde con los números separados por coma'
                . ($grupo['is_required'] ? '.' : ', o *0* si no quieres ninguno.')
            : 'Responde con el número'
                . ($grupo['is_required'] ? '.' : ', o *0* si no quieres ninguno.');

        $this->responder($phone, implode("
", $lineas));
    }

    /**
     * Interpreta la respuesta del cliente para un grupo.
     *
     * Devuelve los adicionales elegidos, una lista vacía si dijo que ninguno,
     * o null si la respuesta no sirve y hay que volver a preguntar.
     *
     * @return array<int, array{id: int, name: string, extra_price: float}>|null
     */
    private function interpretarAdicionales(string $texto, array $grupo): ?array
    {
        $normalizado = $this->normalizar($texto);

        if (in_array($normalizado, ['0', 'ninguno', 'ninguna', 'nada', 'no'], true)) {
            // Un grupo obligatorio no admite quedarse sin elegir.
            return $grupo['is_required'] ? null : [];
        }

        preg_match_all('/\d+/', $texto, $coincidencias);

        $opciones  = $grupo['options'];
        $seleccion = [];

        foreach ($coincidencias[0] as $numero) {
            $numero = (int) $numero;

            if ($numero < 1 || $numero > count($opciones)) {
                continue;
            }

            // Indexado por id: repetir el mismo número no lo duplica.
            $seleccion[$opciones[$numero - 1]['id']] = $opciones[$numero - 1];

            if ($grupo['selection_type'] !== 'multiple') {
                break;
            }
        }

        return $seleccion ? array_values($seleccion) : null;
    }

    /**
     * Grupos de adicionales del producto, con sus opciones disponibles.
     *
     * Se omiten los grupos que se quedaron sin opciones activas: preguntar por
     * un grupo vacío dejaría la conversación sin salida.
     *
     * @return array<int, array{id: int, name: string, selection_type: string, is_required: bool, options: array}>
     */
    private function gruposDeAdicionales(Product $producto): array
    {
        $producto->load([
            'additionalGroups' => fn($q) => $q->orderBy('sort_order')->orderBy('name'),
            'additionalGroups.additionals' => fn($q) => $q
                ->where('is_available', true)
                ->orderBy('sort_order')
                ->orderBy('name'),
        ]);

        $grupos = [];

        foreach ($producto->additionalGroups as $grupo) {
            if ($grupo->additionals->isEmpty()) {
                continue;
            }

            $grupos[] = [
                'id'             => $grupo->id,
                'name'           => $grupo->name,
                'selection_type' => $grupo->selection_type,
                'is_required'    => (bool) $grupo->is_required,
                'options'        => $grupo->additionals->map(fn($a) => [
                    'id'          => $a->id,
                    'name'        => $a->name,
                    'extra_price' => (float) $a->extra_price,
                ])->values()->all(),
            ];
        }

        return $grupos;
    }

    private function categorias(Restaurant $restaurant)
    {
        return Category::where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->whereHas('products', fn($q) => $q->where('is_available', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function productos(Restaurant $restaurant, int $categoryId)
    {
        return Product::where('restaurant_id', $restaurant->id)
            ->where('category_id', $categoryId)
            ->where('is_available', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'price']);
    }

    private function resumenCarrito(array $carrito, Restaurant $restaurant): string
    {
        $lineas = ['🛒 *Tu pedido:*'];
        $total  = 0;

        foreach ($carrito as $linea) {
            $subtotal = $linea['price'] * $linea['quantity'];
            $total   += $subtotal;
            $lineas[] = "• {$linea['quantity']} × {$linea['name']} — " . $this->dinero($subtotal, $restaurant);

            foreach ($linea['additionals'] ?? [] as $extra) {
                $lineas[] = "    + {$extra['name']}";
            }
        }

        $lineas[] = '*Total: ' . $this->dinero($total, $restaurant) . '*';

        return implode("\n", $lineas);
    }

    /**
     * Índice (base 0) de la opción elegida, o null si no es válida.
     */
    private function indiceElegido(string $texto, int $totalOpciones): ?int
    {
        $numero = (int) filter_var($texto, FILTER_SANITIZE_NUMBER_INT);

        return ($numero >= 1 && $numero <= $totalOpciones) ? $numero - 1 : null;
    }

    private function dinero(float $monto, Restaurant $restaurant): string
    {
        return '$' . number_format($monto, 0, ',', '.') . ' ' . ($restaurant->currency ?? 'COP');
    }

    private function normalizar(string $texto): string
    {
        $sinTildes = strtr(mb_strtolower($texto), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        return trim($sinTildes);
    }

    private function expirada(WhatsappSession $session): bool
    {
        return $session->exists
            && $session->last_message_at
            && $session->last_message_at->diffInMinutes(now()) > self::MINUTOS_EXPIRACION;
    }

    private function responder(string $phone, string $mensaje): void
    {
        $this->whatsapp->sendText($phone, $mensaje);
    }

    private function guardar(WhatsappSession $session, string $estado, array $context): void
    {
        $session->fill([
            'state'           => $estado,
            'context'         => $context,
            'last_message_at' => now(),
        ])->save();
    }
}
