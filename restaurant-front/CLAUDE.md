# CLAUDE.md — Frontend (restaurant-front)

Panel React del SaaS de restaurantes. Cubre cuatro roles con necesidades muy
distintas y, además, la única pantalla que ve el cliente final: el menú del QR.

El backend está en `../restaurant-api`, con su propio CLAUDE.md.

## Stack

- **Vite 8 · React 19 · TypeScript** — `npm run dev` en el 5173
- **Tailwind 4** con tokens en `@theme` (`src/index.css`), sin `tailwind.config.js`
- **TanStack Query 5** para el estado del servidor; no hay Redux ni Zustand
- **React Router 7**
- **axios** con dos clientes: uno con sesión, otro para lo público
- **qrcode** para la hoja de códigos imprimibles

El proxy de Vite manda `/api` y `/storage` al 8000, así que en desarrollo no hay
CORS que pelear. Hacen falta los dos servidores a la vez.

## Estructura

```
src/
├── api/          cliente, tipos y llamadas por dominio
├── components/   Layout, ui.tsx (piezas compartidas), FotoProducto
├── features/     una carpeta por dominio
│   ├── auth/     sesión, login
│   ├── mesas/    plano de sala
│   ├── pedidos/  lista, alta, detalle, cobro
│   ├── cocina/   tablero, canal de Reverb
│   ├── menu/     categorías, productos, adicionales
│   ├── inventario/ ingredientes, movimientos, recetas
│   ├── reportes/ · finanzas/ · clientes/ · ajustes/ · inicio/
│   └── publico/  menú del QR — sin sesión
└── lib/formato.ts  dinero, fechas, estados
```

## Los tipos son un espejo escrito a mano

`src/api/tipos.ts` refleja los API Resources del backend. No se generan solos, así
que **cuando toques un Resource en Laravel hay que actualizarlos aquí**.

Casi todos los fallos entre capas han salido de suponer la forma en vez de mirarla:
`elapsed_min` (no `minutes_elapsed`), la mesa no expone `zone_id`, el pedido no trae
`table_id` suelto. Antes de escribir un tipo, léelo del Resource.

Ojo con la paginación, que tiene **dos formas**:

- `/orders` devuelve `{ data, meta }` — es una colección de Resources → `PaginadoResource<T>`
- `/inventory/movements`, `/customers` devuelven los contadores en la raíz → `Paginado<T>`

## Reglas que atraviesan todo

**El `restaurant_id` no se envía nunca.** El backend lo deduce del token. Mandarlo
sería, además de inútil, una puerta a confundirse de inquilino.

**Las fechas se formatean en la zona del restaurante**, no en la del navegador.
`configurarZona()` se llama al abrir sesión y los helpers de `lib/formato.ts` la
aplican. Sin eso, un dueño mirando desde otro país vería las ventas de la noche
caer en el día siguiente.

**Los estados activos del pedido incluyen `proposed`.** Es el que arma el comensal
desde el QR y espera confirmación del mesero. Cualquier filtro de "pedidos en
curso" que lo olvide deja mesas ocupadas sin mostrar su pedido.

**Las rutas públicas están excluidas del interceptor de 401** (`RUTAS_PUBLICAS` en
`api/client.ts`). Sin eso, un token viejo en el móvil de un cliente lo expulsaba
del menú del QR a una pantalla de login que no le corresponde.

## Diseño

El sistema vive en `src/index.css`, dentro de `@theme`. Hay una skill de diseño
instalada en `.claude/skills/frontend-design`.

**Tipografía en dos papeles.** El texto de interfaz usa la pila del sistema —ya
está en el dispositivo y se pinta sin descargar nada, que en una tablet de gama
baja se nota. Los titulares y las cifras usan Bricolage Grotesque, alojada en
`public/fuentes/` (76 KB, variable). Se aplica por elemento (`h1`, `h2`, `h3`,
`.cifras`), no clase por clase, para que ninguna pantalla nueva se quede atrás.

**Elevación con significado**: `.alzado` para lo que responde al toque, `.flotante`
para lo que tapa la pantalla, plano para lo que solo agrupa.

**La comanda** (`.comanda`) es el elemento firma del producto: el tablero de cocina
son tickets de papel con borde troquelado y lomo de color según la espera. No es
decoración — papel claro sobre fondo oscuro se lee mejor a dos metros, y el troquel
deja contar comandas sin leerlas.

### El listón, que no es negociable

- **Contraste AA (4.5)** en todo texto. Se verifica midiendo, no a ojo: varios
  tonos que parecían bien daban 2.3. Cuidado con el papel de la comanda (`#fbf8f2`),
  que baja el contraste un par de décimas frente al blanco.
- **44 px mínimo** en cualquier cosa que se toque. Los enlaces de texto en prosa
  pueden conservar su tamaño visual ampliando el área con padding.
- **Sin desbordes horizontales** a 375 px.
- Foco visible y `prefers-reduced-motion` respetado (ya está en `index.css`).

## Cada rol ve lo suyo

`MENU` en `components/Layout.tsx` filtra por rol y por función de plan. No es
cosmético: un panel con ocho opciones irrelevantes obliga a leerlas todas.

En móvil caben cuatro entradas y el resto va tras **Más**. La lista no se recorta
nunca: hacerlo dejó en su día cuatro secciones inalcanzables en tablet.

El aterrizaje también depende del rol (`Aterrizaje` en `App.tsx`): el admin entra
al panel de inicio, quien atiende va a mesas, la cocina a su tablero.

## La cocina va aparte

`/cocina` se salta el armazón (`sinMarco` en `Layout`): es una pantalla colgada que
se mira a dos metros con las manos ocupadas, y cualquier barra le roba sitio.

Su altura sale de un flex real, no de restar una cabecera que se dé por fija —en
pantallas estrechas la cabecera envuelve y crece. Cada columna tiene su propio
scroll. En móvil se ve una columna a la vez con pestañas que llevan el conteo.

## Tiempo real

`useCanalCocina` se suscribe al canal privado de Reverb. **Sin `queue:work` en el
backend no llega ningún evento**, así que hay un `refetchInterval` de red de
seguridad: sin él la cocina se quedaría mirando una pantalla congelada sin saberlo.

## Comandos

```bash
npm run dev
```

```bash
npm run build
```

```bash
npx tsc -b --noEmit
```

`tsc -b` usa la configuración del proyecto y detecta cosas que `tsc --noEmit` a
secas deja pasar — un import sin usar salió así.

## Verificación

No hay tests automatizados en el frontend. Lo que sí se hace, y conviene mantener,
es comprobar en el navegador midiendo: contraste resuelto con canvas (Tailwind 4
emite `oklch` y un parseo a mano de RGB da números falsos), tamaños táctiles y
desbordes a 375, 768 y 1440.

## Pendiente

- **Sin tests.** Un Vitest + Testing Library sobre `lib/formato.ts` y el carrito
  de `NuevoPedido` cubriría lo que más duele si se rompe.
- **El bundle ronda los 560 KB** (166 gzip). Cuando moleste, partir por rutas con
  `React.lazy` — la cocina y el menú público no necesitan el panel entero.
- **Fotos de producto**: la interfaz ya las muestra y tiene un sustituto digno,
  pero los productos sembrados vienen sin imagen.
