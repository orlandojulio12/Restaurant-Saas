# CLAUDE.md — Restaurante Dinámico (SaaS)

SaaS multi-restaurante: gestión de menú, mesas con QR, pedidos en tiempo real,
pagos, inventario por ingredientes, reportes, módulo financiero y bot de WhatsApp.

Dos proyectos, cada uno con su propio CLAUDE.md más detallado:

| Carpeta | Qué es | Documentación |
|---|---|---|
| `restaurant-api/` | API en Laravel 12 | [CLAUDE.md](restaurant-api/CLAUDE.md) |
| `restaurant-front/` | Panel en React 19 | [CLAUDE.md](restaurant-front/CLAUDE.md) |

**Empieza por el CLAUDE.md de la carpeta en la que vayas a trabajar.** Este archivo
solo cubre lo que afecta a las dos a la vez.

## Levantar el entorno

Hacen falta varios procesos a la vez. En desarrollo, como mínimo:

```bash
php artisan serve
```

```bash
npm run dev
```

Y si vas a tocar tiempo real, dos más en `restaurant-api/`:

```bash
php artisan reverb:start
```

```bash
php artisan queue:work
```

**Sin `queue:work` no se emite ningún evento.** Los eventos implementan
`ShouldBroadcast` con cola en base de datos, así que se acumulan en la tabla `jobs`
en silencio: la cocina deja de recibir pedidos sin ningún error visible. Es el
punto de fallo más silencioso del sistema.

El frontend proxea `/api` y `/storage` al 8000, así que no hay CORS que configurar
en desarrollo.

Usuarios sembrados, todos con contraseña `password`:
`admin@test.com`, `mozo@test.com`, `cocina@test.com`, `caja@test.com`.

## Lo que cruza las dos capas

**Los tipos del frontend son un espejo escrito a mano de los API Resources.** No se
generan solos. Al cambiar un Resource, un enum o un código de error en Laravel, hay
que actualizar `restaurant-front/src/api/tipos.ts`. La mayoría de los fallos entre
capas han salido de suponer la forma de una respuesta en vez de leerla.

**El `restaurant_id` nunca viaja en la petición.** El backend lo deduce del token
vía el middleware `restaurant.scope`. Cualquier endpoint nuevo debe leerlo de ahí y
filtrar por él; cualquier llamada nueva del frontend no debe mandarlo.

**Las fechas se calculan en la zona del restaurante, no en UTC ni en la del
navegador.** El backend usa `Restaurant::localNow()` y `dayBoundsUtc()`; el frontend
fija la zona al abrir sesión. Romper esto desplaza el corte del día cinco horas y
las ventas de la noche caen en el día siguiente.

**Los estados del pedido son un contrato compartido.** La máquina de estados vive en
`OrderController::TRANSITIONS` y el frontend la replica en `lib/formato.ts` solo
para no ofrecer botones que darían 422. Al añadir un estado hay que tocar ambos, más
`Order::ACTIVOS`. Lo mismo con los tipos: `TIPOS` en `formato.ts` es un
`Record<TipoPedido, string>`, así que ampliar el enum rompe la compilación hasta
que se le pone etiqueta — que es justo lo que debe pasar.

**La mesa es la cuenta, no el pedido.** Con el QR cada comensal manda el suyo, así
que una mesa acumula varios `orders` a la vez. Cualquier pantalla que muestre "lo
que debe la mesa" tiene que sumar sus pedidos activos (`active_total` en
`TableResource`), no leer uno. Y cobrar va por `POST /payments/table/{table}`, que
los cierra todos juntos — o solo los `order_ids` que se le pasen, que es como se
cobra a un comensal suelto. Ver *La mesa es la cuenta compartida* en el CLAUDE.md
del backend.

**El reparto por comensal se calcula en un solo sitio.** `Order::repartoPorComensal()`
manda, y el frontend lo replica en `Cobrar.tsx` solo porque ahí los pedidos llegan
por otra ruta (`/tables/{id}/orders`). Si cambia la regla —cómo se normaliza el
nombre, qué pasa con los pedidos sin nombre, en qué orden salen— hay que tocar los
dos. Ya pasó una vez: el panel agrupaba sobre una lista en orden `latest()` y
mostraba "luis" donde la carta mostraba "Luis".

**La carta pública lleva la marca del restaurante, no la del producto.** El hex de
`restaurants.brand_color` se convierte en la paleta entera en `lib/paleta.ts` y se
inyecta como variables CSS sobre el contenedor de la carta: `bg-marca-600` ya es
`var(--color-marca-600)`, así que recolorear no toca una sola clase. La cabecera usa
el hex exacto que eligieron —un peso derivado convertiría un amarillo de marca en
oliva— y el color del texto encima **se decide midiendo contraste**, nunca dando por
hecho que la marca es oscura.

## Estado

Backend y panel completos y verificados de punta a punta. **240 tests** en el
backend (`php artisan test`); el frontend no tiene tests automatizados todavía.

### Antes de producción

- **Supervisar el worker y el cron.** Hace falta Supervisor o systemd que reinicie
  `queue:work`, y una entrada de cron que ejecute `php artisan schedule:run` cada
  minuto para el resumen diario y el vencimiento de suscripciones.
- **Recuperación de contraseña.** Hoy solo un admin puede cambiar la de un
  empleado. Falta el flujo por correo, y con él configurar el envío real:
  `MAIL_MAILER` apunta al log.
- **Credenciales de WhatsApp.** El bot está construido y probado con `Http::fake`,
  pero nunca habló con Meta. El uso previsto —responder dentro de la ventana de 24 h
  que abre el cliente— no tiene coste; solo se pagan plantillas fuera de esa
  ventana, que el diseño actual no usa.
- **Imágenes en disco local.** Van por `storage:link`. Sirve con una sola instancia;
  con varias hay que mover el disco a S3 o equivalente.

### Aplazado a una 2.0

Turnos de caja (`shifts` tiene tabla y modelo, sin controlador), pasarela de pago
—el cobro es manual a propósito— y que el bot procese audio, imágenes y ubicación.
