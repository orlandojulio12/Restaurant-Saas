# CLAUDE.md — Restaurante Dinámico (SaaS)

Backend API en Laravel 12 para un SaaS multi-restaurante: gestión de menú, mesas con QR,
pedidos en tiempo real (cocina/mozos), pagos, inventario por ingredientes, reportes y
módulo financiero (punto de equilibrio). Fase 2 contempla un bot de WhatsApp.

El frontend React vive en un proyecto aparte (`restaurant-front`), **no** está en este repo.

## Estructura

```
Restaurant-Saas/
└── restaurant-api/          ← proyecto Laravel (todo el trabajo ocurre aquí)
    ├── app/
    │   ├── Console/Commands/ GenerateDailySummaries.php
    │   ├── Events/           4 eventos de broadcast
    │   ├── Http/
    │   │   ├── Controllers/Api/  19 controladores
    │   │   ├── Middleware/   RestaurantScope, EnsureUserRole, EnsurePlanFeature
    │   │   └── Resources/    5 API Resources
    │   ├── Jobs/             GenerateDailySummary.php
    │   ├── Models/           24 modelos
    │   └── Services/         Inventory, Order, Plan, Image, Whatsapp, WhatsappBot
    ├── database/migrations/  26 migraciones
    ├── database/seeders/     Plan, Restaurant, User, Menu, Table
    ├── tests/Feature/        16 archivos · 172 tests
    └── routes/api.php, channels.php, console.php
```

Comandos (siempre desde `restaurant-api/`):

```bash
php artisan migrate:fresh --seed
```

```bash
php artisan serve
```

```bash
php artisan reverb:start
```

```bash
php artisan queue:work
```

`composer dev` levanta serve + queue + pail + vite en paralelo.

## Stack

- PHP 8.2 · Laravel 12 (estructura slim: `bootstrap/app.php`, sin `Kernel.php`)
- MySQL (`restaurante_db`) · queue/cache/session en driver `database`
- **laravel/sanctum** 4 — tokens Bearer (no cookies) para la SPA
- **laravel/reverb** 1 — WebSockets, `BROADCAST_CONNECTION=reverb`, puerto 8080
- **spatie/laravel-permission** 6 — instalado pero sin usar (ver "Autenticación")
- **intervention/image** 3 — subida de imágenes de productos y logo (`ImageService`)

## Multi-tenancy — regla central del proyecto

Todo el aislamiento entre restaurantes se hace con la columna `restaurant_id` y el
middleware `restaurant.scope` (`app/Http/Middleware/RestaurantScope.php`), que inyecta el
`restaurant_id` del usuario autenticado dentro del `Request`:

```php
$request->merge(['restaurant_id' => $user->restaurant_id]);
```

Por lo tanto, **en cualquier controlador nuevo**:

- Leer el tenant con `$request->input('restaurant_id')` — nunca de un parámetro del cliente.
- Filtrar **siempre** las consultas por `restaurant_id`.
- En route-model binding, validar pertenencia antes de tocar el modelo:
  `abort_if($model->restaurant_id !== $request->input('restaurant_id'), 403);`
  (patrón ya usado en `TableController` y `OrderController::authorizeRestaurant`).

No hay Global Scopes ni Policies; la verificación es manual en cada método.

## Autenticación

**Alta de un restaurante.** `POST /api/auth/register` es la puerta de entrada al
servicio: crea el restaurante, su primer usuario admin y los ajustes iniciales en
una transacción, y devuelve un token para entrar sin iniciar sesión aparte. Plan
`free` por defecto; el slug se deriva del nombre y admite sufijo si está ocupado.
Ruta pública, 3 altas por hora.

- `POST /api/auth/login` acepta `email` + `password`, y opcionalmente `restaurant_id` o
  `restaurant_slug` (el email es único **por restaurante**, no global: `unique(email, restaurant_id)`).
- Devuelve `{ user, token, restaurant: { ..., plan: { has_whatsapp, has_inventory, has_reports, has_financials } } }`.
  El frontend usa esos flags del plan para mostrar/ocultar módulos.
- Rechaza usuarios con `is_active = false` (403) y actualiza `last_login_at`.
- `GET /api/auth/me`, `POST /api/auth/logout` (borra el token actual, 204).
- Roles: enum en `users.role` → `admin | waiter | kitchen | cashier`.

**Un correo puede administrar varios restaurantes**, porque el email es único por
restaurante y no globalmente. Cuando eso ocurre, el login devuelve **409** con la
lista de restaurantes a los que pertenece —solo tras verificar la contraseña, así
que no filtra nada— y el cliente repite la petición con `restaurant_slug`.

**Autorización por rol.** El middleware `role` es propio
(`app/Http/Middleware/EnsureUserRole.php`) y resuelve contra la columna `users.role`:
`->middleware('role:admin')` o `role:admin,cashier`. Devuelve 401 sin usuario y 403 sin rol.
`spatie/laravel-permission` sigue instalado y su migración corre, pero **no se usa**: `User`
no tiene el trait `HasRoles` y no hay filas en `model_has_roles`. Se puede desinstalar.

## WebSockets (Reverb)

Canal privado único parametrizado, autorizado en `routes/channels.php`:

```
restaurant.{restaurantId}.{scope}     scope = kitchen | waiters | tables | admin
```

La autorización compara `user->restaurant_id === restaurantId`. El endpoint de auth es
`POST /api/broadcasting/auth` (`BroadcastController`), fuera del grupo con `restaurant.scope`
pero bajo `auth:sanctum`.

| Evento | Canal | `broadcastAs` | Se dispara en |
|---|---|---|---|
| `OrderCreated` | `.kitchen` | `order.created` | `OrderController@store` |
| `OrderStatusUpdated` | `.kitchen`, `.waiters` | `order.status.updated` | `OrderController@updateStatus` |
| `TableStatusUpdated` | `.tables` | `table.status.updated` | al liberar mesa en `updateStatus` |
| `LowStockAlert` | `.admin` | `stock.low` | `InventoryService` al cruzar el mínimo |

Todos los eventos definen `broadcastWith()` con un payload plano pensado para el front.

**Los eventos se encolan.** Implementan `ShouldBroadcast` (no `ShouldBroadcastNow`) y
`QUEUE_CONNECTION=database`, así que **sin `php artisan queue:work` no se emite nada**: los
jobs se acumulan en la tabla `jobs` en silencio, sin error visible. Para desarrollo hacen
falta tres procesos a la vez:

```bash
php artisan serve
```

```bash
php artisan reverb:start
```

```bash
php artisan queue:work
```

Verificado de punta a punta con un cliente WebSocket (protocolo Pusher) contra Reverb:
`order.created` llega solo a cocina; `order.status.updated` a cocina y mozos en cada
transición; `table.status.updated` al canal de mesas al liberarse; y `stock.low` al canal
admin. Un intento de suscribirse al canal de otro restaurante devuelve 403 en
`/api/broadcasting/auth`.

## Máquina de estados del pedido

Definida como constante `TRANSITIONS` en `OrderController`; toda transición inválida
devuelve 422 con la lista de estados permitidos.

```
pending     → preparing | cancelled
preparing   → ready | cancelled
ready       → delivered | on_the_way
on_the_way  → delivered
delivered   → closed
closed, cancelled → (terminales)
```

Cada transición estampa su timestamp (`preparing_at`, `ready_at`, `delivered_at`, `closed_at`).
Al pasar a `closed`: si no quedan pedidos activos en la mesa, la mesa vuelve a `available`
(+ evento) y se descuenta el inventario vía `InventoryService::deductForOrder()`.

Nota: el enum de la migración incluye además `paid`, que ninguna transición usa.

## Modelo de datos

25 migraciones, prefijo `2026_04_01_0101xx`, en orden de dependencias:

`plans` → `restaurants` → `restaurant_settings`, `users`, `shifts` → `categories` →
`products` → `additional_groups` → `additionals` → `product_additional_groups` →
`zones` → `tables` → `customers` → `whatsapp_sessions` → `orders` → `order_items` →
`order_item_additionals` → `payments` → `ingredients` → `product_ingredients` →
`inventory_movements` → `fixed_costs` → `financial_goals` → `daily_summaries` → `subscriptions`

Puntos a recordar:

- `RestaurantTable` mapea a la tabla **`tables`** (`protected $table = 'tables'`), porque
  `Table` choca con el Query Builder. La FK en `orders` es `table_id`.
- `Additional` cuelga de `AdditionalGroup` por `group_id` (no `additional_group_id`).
- Los grupos de adicionales se asocian a productos por el pivote `product_additional_groups`
  con clave primaria compuesta.
- `order_items` y `order_item_additionals` **desnormalizan** `product_name`,
  `additional_name`, `unit_price` y `extra_price`: el histórico del pedido no cambia si
  luego se edita el menú. Mantener este patrón.
- `daily_summaries` es la fuente de datos de todo el módulo financiero y de reportes
  (`unique(restaurant_id, date)`). La escribe el job diario — ver sección propia abajo.
- Precios en `decimal`, moneda por defecto COP, timezone `America/Bogota`.
- Métodos de pago pensados para Colombia: `cash, card, nequi, daviplata, bancolombia, transfer, other`.

## Resumen diario (`daily_summaries`)

Pipeline que alimenta `ReportController` y todo `FinancialController`:

```
Schedule (routes/console.php, 04:00)
  → summaries:generate            app/Console/Commands/GenerateDailySummaries.php
    → GenerateDailySummary job    app/Jobs/GenerateDailySummary.php   (uno por restaurante)
      → daily_summaries
```

- Solo cuentan las órdenes en estado `closed`, filtradas por `closed_at`.
- **La app corre en UTC y los restaurantes en `America/Bogota`**: el job convierte los
  límites del día local del restaurante a UTC antes de filtrar. Sin eso, el corte del día
  quedaría desplazado 5 horas. Cualquier reporte nuevo debe hacer lo mismo.
- `total_cost` se estima con `products.cost × quantity` de los `order_items`.
- Idempotente (`updateOrCreate` sobre `restaurant_id + date`): se puede reprocesar cualquier
  fecha. Un día sin ventas escribe una fila en cero, no la omite.
- Requiere un worker activo (`php artisan queue:work`), salvo que se use `--sync`.

Reprocesar a mano:

```bash
php artisan summaries:generate 2026-03-10 --sync
```

Cubierto por `tests/Feature/GenerateDailySummaryTest.php` (zona horaria, idempotencia, día vacío).

### Regla: en vivo vs. consolidado

`daily_summaries` **solo contiene días ya cerrados**. Leer de ahí el día en curso devuelve 0.
Todo endpoint que reporte cifras debe seguir este reparto:

| Periodo | Fuente |
|---|---|
| Hoy (y ayer) | En vivo desde `orders` (`status = closed`, filtrado por `closed_at`) |
| Días pasados, mes, tendencias | `daily_summaries` |
| Mes en curso | `daily_summaries` hasta ayer **+** hoy en vivo |

`FinancialController::liveDay()` implementa la parte en vivo y usa la misma definición de
venta que el job (orden cerrada), para que las cifras reconcilien entre endpoints.

### Fechas: siempre en la zona del restaurante

Nunca usar `now()` para calcular hoy/ayer/inicio de mes: la app corre en UTC. Usar los
helpers del modelo `Restaurant`:

```php
$restaurant->localNow();              // Carbon en la zona del restaurante
$restaurant->dayBoundsUtc('2026-08-16'); // [inicio, fin] UTC de ese día local
```

Ambos los usan `GenerateDailySummary` y `FinancialController`.

## Bot de WhatsApp (Meta Cloud API)

```
POST /api/webhook/whatsapp  →  WhatsappController
  → resuelve el restaurante por el número que recibió el mensaje
  → WhatsappBotService (máquina de estados)     → WhatsappService (envía a Meta)
```

**Multi-inquilino por número.** Cada restaurante atiende con su propio
`restaurants.whatsapp_number`; el webhook compara solo los dígitos del
`metadata.display_phone_number` que manda Meta. Un mensaje a un número sin
restaurante asociado se registra y se ignora.

Estados en `whatsapp_sessions.state`, con el carrito y las listas mostradas en
`context`:

```
greeting → category → product → quantity → cart → address → confirm → (pedido creado)
```

Las listas que ve el usuario se guardan en el contexto: el «2» que responde solo
significa algo contra lo que se le mostró. Atajos en cualquier punto: *menú*,
*hola* e *inicio* reinician; *cancelar* aborta.

Reglas que conviene no romper:

- **El webhook siempre responde 200.** Cualquier otro código hace que Meta
  reintente en bucle; los fallos van al log.
- **Se descarta el reintento del mismo `message_id`** (guardado en el contexto);
  si no, un reintento de Meta avanzaría la conversación dos veces.
- Todo el payload se trata como entrada externa y se comprueba nivel a nivel.
- La sesión caduca a las 2 horas: un carrito viejo no se reanuda al día siguiente.
- El pedido nace como `delivery`, `pending` y con `user_id` nulo (lo origina el
  cliente, no el personal), y respeta el cupo `max_daily_orders` del plan.
- Solo se procesan mensajes de tipo `text`; audio, imágenes y ubicación aún no.

Config en `services.whatsapp`: `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_ID` y
`WHATSAPP_VERIFY_TOKEN`. Sin token ni phone_id el servicio no envía nada y lo
avisa por log. Cubierto por `tests/Feature/WhatsappBotTest.php` con `Http::fake`.

## Planes y límites

`plans` define `max_tables`, `max_products`, `max_daily_orders` (0 = ilimitado) y los flags
`has_whatsapp`, `has_inventory`, `has_reports`, `has_financials`.
Seeder: `free` (2 mesas / 20 productos / 20 pedidos día), `basic` ($49.000/mes),
`pro` ($99.000/mes, todo ilimitado).

**Ningún límite ni flag se valida hoy en el backend** — solo se exponen en el login.

## Convenciones del código

- Controladores API en `App\Http\Controllers\Api`, retornan `JsonResponse`.
- Validación inline con `$request->validate([...])` en sintaxis de array
  (`['required', 'in:a,b']`); no hay Form Requests.
- Respuestas: `201` al crear, `204` al borrar, `403` en cross-tenant, `422` en regla de
  negocio violada, con `message` en español.
- Los mensajes de error y los comentarios del código están **en español**; el código
  (nombres de variables, métodos) en inglés. Mantener esa mezcla.
- Los API Resources solo se usan en Order, OrderItem, Product, Table y el dashboard
  financiero; el resto de controladores devuelve el modelo directo.
- Operaciones multi-tabla dentro de `DB::transaction()` (ver `OrderController@store`).

## Seeders de desarrollo

Restaurante `el-rincon-de-prueba` (plan pro), zona "Salón Principal" con 8 mesas, menú demo.
Usuarios, todos con contraseña `password`:
`admin@test.com`, `mozo@test.com`, `cocina@test.com`, `caja@test.com`.

---

## Estado

Los 19 controladores están implementados; no queda ningún esqueleto. Cobertura:
172 tests, 660 aserciones (`php artisan test`).

| Módulo | Estado |
|---|---|
| Migraciones (26), modelos (24), seeders | ✅ |
| Auth + `EnsureUserRole` + `RestaurantScope` | ✅ |
| Pedidos, mesas, menú público por QR | ✅ |
| Menú: categorías, productos (con imagen), grupos de adicionales, zonas | ✅ |
| Clientes, usuarios, configuración | ✅ |
| Inventario: ingredientes, recetas, movimientos, alertas | ✅ |
| Pagos y cierre de pedido (`OrderService`) | ✅ |
| Reportes y módulo financiero | ✅ |
| Resumen diario (job + comando + schedule) | ✅ |
| Límites y funciones de plan (`PlanService` + `EnsurePlanFeature`) | ✅ |
| WebSockets con Reverb | ✅ verificado en vivo |
| Bot de WhatsApp | ✅ con `Http::fake`; falta probar contra Meta real |

### Pendiente

- **Probar el bot contra la API real de Meta.** `WHATSAPP_TOKEN` y `WHATSAPP_PHONE_ID`
  están vacíos en `.env`; hace falta una cuenta de WhatsApp Business y exponer el webhook
  por HTTPS (ngrok o similar) para el handshake de verificación.
- El bot solo maneja mensajes de texto, y no ofrece adicionales al armar el pedido.
- Facturación/`subscriptions`: la tabla existe, no hay lógica ni pasarela.
- `shifts` (turnos de caja): tabla y modelo existen, sin controlador ni rutas.
- El enum `orders.status` incluye `paid`, que ninguna transición usa: el pago cierra
  directamente a `closed`.
- **Sanctum `guard => ['web']`** en `config/sanctum.php` con auth por token Bearer.
  Funciona por el fallback, pero merece revisión si aparece comportamiento raro de sesión.
- **`.env` está presente en el directorio** con credenciales de Reverb y WhatsApp.
  Verificar que quede fuera de cualquier repo antes de publicar.
- **El proyecto no es un repositorio git.** No hay historial ni respaldo.

### Bugs corregidos que conviene no reintroducir

- `role:admin` no funcionaba: spatie estaba instalado pero `User` no usa `HasRoles`.
  Ahora el rol se resuelve contra la columna `users.role`.
- **El login estaba roto por completo**: faltaba el trait `HasApiTokens` en `User`, así que
  `createToken()` no existía. Solo se detecta ejecutando la API, no leyendo el código.
- `storeFromQr` reventaba con 500 al llamar `$request->user()->id` en una ruta pública.
- `orders.type` validaba `takeaway`, que no está en el enum (ahora `counter`).
- `tables.status` validaba `unavailable` en vez de `disabled`, y `number` como entero
  pese a ser `string(20)`.
- `$fillable` de `Plan` y `Restaurant` no coincidía con sus migraciones: el seeder perdía
  `whatsapp_number`, `city` y `country` en silencio.
- `daily_summaries.date` se guardaba como datetime y el job duplicaba filas al reprocesar.
- Las métricas de "hoy" del dashboard salían siempre en 0: leían de `daily_summaries`,
  que solo cubre días ya cerrados.
- Los costos fijos `daily` se sumaban como si fueran mensuales en el punto de equilibrio,
  y `biweekly`/`quarterly`/`yearly` eran ramas muertas fuera del enum.
- `InventoryService` no respetaba el plan, no bloqueaba la fila al descontar y nunca
  emitía `LowStockAlert`.
- Desactivar un usuario no revocaba sus tokens: seguía entrando con el que ya tenía.
- `product_ingredients` no estaba expuesta por ninguna ruta, así que el inventario no
  tenía recetas que descontar.
