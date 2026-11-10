import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams, useSearchParams } from 'react-router-dom'
import axios from 'axios'
import FotoProducto from '../../components/FotoProducto'
import { dinero } from '../../lib/formato'
import { variablesDeMarca } from '../../lib/paleta'
import type { EstadoPedido, TipoPedidoQr } from '../../api/tipos'

/**
 * La carta del QR.
 *
 * Es lo único que ve el cliente final y no se parece al panel: quien la abre no
 * ha iniciado sesión, no ha instalado nada, está sentado a una mesa con su
 * propio móvil y probablemente con mala señal. Ni barra de navegación, ni
 * roles, ni nada que aprender.
 *
 * Dos cosas la separan de una carta digital cualquiera.
 *
 * La primera es que la mesa es compartida. Cuatro personas escanean el mismo
 * adhesivo y cada una manda su pedido; sin ver lo que ya pidieron los demás,
 * piden dos veces la misma limonada y le preguntan al mesero si su plato entró.
 * Por eso la cuenta de la mesa está siempre presente abajo, se actualiza sola y
 * es el mismo sitio donde vive el carrito: dos barras compitiendo en un móvil
 * es una barra de más.
 *
 * La segunda es que esto es un SaaS: la carta tiene que ser del restaurante, no
 * del producto. El color que eligió el administrador manda sobre la paleta
 * entera (ver `lib/paleta.ts`) y su logo y su fachada encabezan la página. Sin
 * marca configurada cae en la paleta por defecto, que es lo correcto mientras
 * no hayan subido nada.
 */

// Cliente propio, sin el interceptor que redirige al login: aquí no hay sesión
// que expirar y un 401 no debe sacar a nadie a ninguna parte.
const publico = axios.create({ baseURL: '/api', headers: { Accept: 'application/json' } })

/** Cada cuánto se vuelve a mirar lo que lleva pedido la mesa. */
const REFRESCO_MESA = 15_000

/**
 * Lo que la barra de categorías ocupa arriba, con un respiro.
 *
 * Son 49 px reales —`min-h-12` más el borde inferior—, y al saltar a una
 * sección el rótulo quedaba pegado justo debajo. Estos sirven de tope tanto
 * para el salto como para decidir qué sección se está mirando.
 */
const ALTO_BARRA = 56

type AdicionalPub = { id: number; name: string; extra_price: string }
type GrupoPub = {
  id: number
  name: string
  selection_type: 'single' | 'multiple'
  is_required: boolean
  additionals: AdicionalPub[]
}
type ProductoPub = {
  id: number
  name: string
  description: string | null
  image_url: string | null
  price: string
  preparation_time: number
  additional_groups: GrupoPub[]
}
type MenuPub = {
  restaurant: {
    id: number
    name: string
    logo_url: string | null
    brand_color: string | null
    cover_url: string | null
    address: string | null
    currency: string
  }
  table: { id: number; number: string } | null
  categories: { id: number; name: string; image_url: string | null; products: ProductoPub[] }[]
}

type RondaMesa = {
  id: number
  round: number
  status: EstadoPedido
  type: TipoPedidoQr
  diner_name: string | null
  total: string
  created_at: string
  items: { name: string; quantity: number; subtotal: string; additionals: string[] }[]
}
/** Lo que le toca a un comensal. `diner_name` nulo = los pedidos sin nombre. */
type ComensalPub = {
  diner_name: string | null
  orders: number[]
  total: number
}

type EstadoMesaPub = {
  table: { id: number; number: string }
  orders: RondaMesa[]
  diners: ComensalPub[]
  total: number
}

type Linea = {
  clave: string
  producto: ProductoPub
  cantidad: number
  notas: string
  adicionales: AdicionalPub[]
}

/**
 * Lo que ve el comensal de cada estado. No es el mismo vocabulario del panel:
 * al cliente no le dice nada "pending", le dice algo "el mesero lo está
 * confirmando". Y no aparecen los estados terminales porque un pedido cerrado
 * ya salió de la cuenta de la mesa.
 */
const ESTADO_COMENSAL: Partial<Record<EstadoPedido, { texto: string; clase: string }>> = {
  proposed: { texto: 'Esperando al mesero', clase: 'bg-pendiente-suave text-pendiente' },
  pending: { texto: 'Confirmado', clase: 'bg-pendiente-suave text-pendiente' },
  preparing: { texto: 'En la cocina', clase: 'bg-preparando-suave text-preparando' },
  ready: { texto: 'Listo, ya sale', clase: 'bg-listo-suave text-listo' },
  on_the_way: { texto: 'En camino', clase: 'bg-camino-suave text-camino' },
  delivered: { texto: 'Servido', clase: 'bg-entregado-suave text-entregado' },
}

/**
 * Recuerda un valor en el móvil de quien pide, sin romperse en modo privado.
 *
 * Devuelve el mismo par que `useState`, con actualizador funcional incluido: sin
 * él, dos toques seguidos en la carta —que es exactamente como se añade a un
 * carrito— leen el mismo estado viejo y el segundo plato se pierde.
 */
function useRecordado<T>(clave: string, inicial: T): [T, React.Dispatch<React.SetStateAction<T>>] {
  const [valor, setValor] = useState<T>(() => {
    try {
      const guardado = localStorage.getItem(clave)
      return guardado ? (JSON.parse(guardado) as T) : inicial
    } catch {
      return inicial
    }
  })

  useEffect(() => {
    try {
      localStorage.setItem(clave, JSON.stringify(valor))
    } catch {
      // Navegación privada o almacenamiento lleno. Se pierde el carrito al
      // recargar, que es exactamente lo que pasaba antes de recordarlo.
    }
  }, [clave, valor])

  return [valor, setValor]
}

/** El desplazamiento suave se apaga si el sistema lo pide. */
function comportamiento(): ScrollBehavior {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
}

/**
 * Qué sección de la carta se está mirando.
 *
 * La barra de categorías no filtra, solo señala: la carta se recorre entera de
 * arriba abajo como la de papel, y esconder el resto sería quitarle al comensal
 * justo lo que vino a ojear.
 *
 * La regla es la última sección cuyo rótulo ya pasó por debajo de la barra. Se
 * resuelve leyendo posiciones en el propio scroll y no con IntersectionObserver:
 * hacía falta un listener igualmente para el final de la página, y sostener dos
 * mecanismos que pueden discrepar cuesta más que las cuatro lecturas que esto
 * hace. Se leen en cada scroll, sin cachear, porque las fotos de los platos
 * llegan tarde y mueven las secciones bajo los pies.
 */
function useSeccionVisible(claves: string) {
  const [activa, setActiva] = useState<number | null>(null)

  useEffect(() => {
    const ids = claves ? claves.split(',').map(Number) : []
    if (ids.length === 0) return

    const calcular = () => {
      // Abajo del todo ya no queda recorrido para subir la última sección hasta
      // la barra: empieza más abajo de donde el scroll puede llegar. Sin este
      // caso aparte su pestaña no se marca nunca, por mucho que se baje.
      if (window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 4) {
        setActiva(ids[ids.length - 1])
        return
      }

      const limite = ALTO_BARRA + 8
      let elegida = ids[0]

      for (const id of ids) {
        const nodo = document.getElementById(`seccion-${id}`)
        if (nodo && nodo.getBoundingClientRect().top <= limite) elegida = id
      }

      setActiva(elegida)
    }

    calcular()
    window.addEventListener('scroll', calcular, { passive: true })
    window.addEventListener('resize', calcular, { passive: true })

    return () => {
      window.removeEventListener('scroll', calcular)
      window.removeEventListener('resize', calcular)
    }
  }, [claves])

  return [activa, setActiva] as const
}

export default function MenuPublico() {
  const { slug } = useParams()
  const [params] = useSearchParams()
  const codigoMesa = params.get('mesa') ?? ''
  const cliente = useQueryClient()

  // El carrito sobrevive a recargar y a que se apague la pantalla, que en una
  // mesa pasa constantemente. Va por mesa: el mismo móvil en otra mesa empieza
  // de cero.
  const [carrito, setCarrito] = useRecordado<Linea[]>(`carrito:${slug}:${codigoMesa}`, [])
  const [nombre, setNombre] = useRecordado<string>(`comensal:${slug}`, '')

  const [configurando, setConfigurando] = useState<ProductoPub | null>(null)
  const [hoja, setHoja] = useState<'carrito' | 'comanda' | null>(null)
  const [notaGeneral, setNotaGeneral] = useState('')
  const [tipo, setTipo] = useState<TipoPedidoQr>('dine_in')
  const [reciente, setReciente] = useState<number | null>(null)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['menu-publico', slug, codigoMesa],
    queryFn: async () =>
      (
        await publico.get<MenuPub>(`/menu/${slug}`, {
          params: codigoMesa ? { qr: codigoMesa } : {},
        })
      ).data,
    retry: 1,
  })

  // La cuenta de la mesa se refresca sola: mientras miras la carta, alguien
  // sentado enfrente puede estar pidiendo.
  const { data: mesa } = useQuery({
    queryKey: ['estado-mesa', slug, codigoMesa],
    queryFn: async () =>
      (await publico.get<EstadoMesaPub>(`/menu/${slug}/mesa/${codigoMesa}`)).data,
    enabled: Boolean(codigoMesa),
    refetchInterval: REFRESCO_MESA,
    // Con la pantalla apagada no hay nadie mirando, y el móvil está en una mesa
    // sin cargador.
    refetchIntervalInBackground: false,
    retry: 1,
  })

  const moneda = data?.restaurant.currency ?? 'COP'
  const rondas = mesa?.orders ?? []

  // Solo las categorías con algo dentro: una pestaña que lleva a una sección
  // vacía es una promesa incumplida.
  const conPlatos = useMemo(
    () => (data?.categories ?? []).filter((c) => c.products.length > 0),
    [data],
  )
  const clavesSecciones = conPlatos.map((c) => c.id).join(',')
  const [seccionActiva, setSeccionActiva] = useSeccionVisible(clavesSecciones)

  const enviar = useMutation({
    mutationFn: async () => {
      const { data: pedido } = await publico.post('/orders/qr', {
        restaurant_slug: slug,
        qr_code: codigoMesa,
        type: tipo,
        diner_name: nombre.trim() || null,
        notes: notaGeneral || null,
        items: carrito.map((l) => ({
          product_id: l.producto.id,
          quantity: l.cantidad,
          notes: l.notas || null,
          additionals: l.adicionales.map((a) => a.id),
        })),
      })
      return pedido as { id: number; status: EstadoPedido }
    },
    onSuccess: (pedido) => {
      setCarrito([])
      setNotaGeneral('')
      setTipo('dine_in')
      setReciente(pedido.id)
      // A la cuenta de la mesa, no a una pantalla sin salida: ahí ve su ronda
      // junto a las de los demás y puede seguir pidiendo.
      setHoja('comanda')
      cliente.invalidateQueries({ queryKey: ['estado-mesa', slug, codigoMesa] })
    },
  })

  const total = useMemo(
    () =>
      carrito.reduce((suma, l) => {
        const extras = l.adicionales.reduce((s, a) => s + Number(a.extra_price), 0)
        return suma + (Number(l.producto.price) + extras) * l.cantidad
      }, 0),
    [carrito],
  )
  const unidades = carrito.reduce((s, l) => s + l.cantidad, 0)

  function agregar(
    producto: ProductoPub,
    adicionales: AdicionalPub[],
    notas: string,
    cantidad: number,
  ) {
    const clave = [
      producto.id,
      adicionales.map((a) => a.id).sort().join('-'),
      notas.trim(),
    ].join('|')

    setCarrito((previo) =>
      previo.some((l) => l.clave === clave)
        ? previo.map((l) => (l.clave === clave ? { ...l, cantidad: l.cantidad + cantidad } : l))
        : [...previo, { clave, producto, cantidad, notas, adicionales }],
    )

    setConfigurando(null)
  }

  if (isLoading) return <Cargando />

  if (isError || !data) {
    return (
      <Centrado
        titulo="No encontramos esta carta"
        texto="Puede que el enlace esté mal escrito o que el restaurante no esté abierto ahora mismo. Pídele ayuda a alguien del personal."
      />
    )
  }

  const { restaurant } = data
  // Sin mesa reconocida no se puede pedir: el pedido no sabría a dónde ir. La
  // carta se deja ver igualmente, que para eso también sirve.
  const sinMesa = !data.table

  return (
    <div
      // Aquí es donde la carta deja de ser del producto y pasa a ser del
      // restaurante: estas variables sustituyen la paleta para todo lo que
      // cuelgue debajo, sin tocar una sola clase.
      style={variablesDeMarca(restaurant.brand_color)}
      className="min-h-dvh bg-piedra-50"
    >
      <Portada restaurante={restaurant} mesa={data.table} />

      {sinMesa && (
        <div className="mx-auto max-w-2xl px-5 pt-5">
          <p className="rounded-xl border border-atencion/40 bg-white p-4 text-sm text-piedra-700">
            <strong className="font-semibold">Estás viendo la carta, pero no puedes pedir.</strong>{' '}
            Escanea el código de tu mesa para hacerlo. Si ya lo hiciste, avisa al personal: puede
            que el adhesivo esté dañado.
          </p>
        </div>
      )}

      {conPlatos.length > 1 && (
        <BarraCategorias
          categorias={conPlatos}
          activa={seccionActiva}
          onElegir={(id) => {
            setSeccionActiva(id)

            const seccion = document.getElementById(`seccion-${id}`)
            if (!seccion) return

            window.scrollTo({
              top: seccion.getBoundingClientRect().top + window.scrollY - ALTO_BARRA,
              behavior: comportamiento(),
            })
          }}
        />
      )}

      <main className="mx-auto max-w-2xl px-5 pb-40 pt-8">
        {conPlatos.map((categoria) => {
          return (
            <section
              key={categoria.id}
              id={`seccion-${categoria.id}`}
              data-categoria={categoria.id}
              className="mb-11"
            >
              <RotuloSeccion nombre={categoria.name} platos={categoria.products.length} />

              <ul>
                {categoria.products.map((producto) => (
                  <li key={producto.id}>
                    <Plato
                      producto={producto}
                      moneda={moneda}
                      deshabilitado={sinMesa}
                      onElegir={() =>
                        producto.additional_groups.some((g) => g.additionals.length > 0)
                          ? setConfigurando(producto)
                          : agregar(producto, [], '', 1)
                      }
                    />
                  </li>
                ))}
              </ul>
            </section>
          )
        })}

        {data.categories.every((c) => c.products.length === 0) && (
          <p className="rounded-2xl border border-dashed border-piedra-300 p-12 text-center text-piedra-500">
            Esta carta todavía no tiene platos.
          </p>
        )}
      </main>

      {!hoja && (
        <BarraMesa
          unidades={unidades}
          totalCarrito={total}
          rondas={rondas.length}
          totalMesa={mesa?.total ?? 0}
          moneda={moneda}
          onCarrito={() => setHoja('carrito')}
          onComanda={() => setHoja('comanda')}
        />
      )}

      {configurando && (
        <HojaProducto
          producto={configurando}
          moneda={moneda}
          onCancelar={() => setConfigurando(null)}
          onAgregar={(ad, notas, cantidad) => agregar(configurando, ad, notas, cantidad)}
        />
      )}

      {hoja === 'carrito' && (
        <HojaCarrito
          carrito={carrito}
          moneda={moneda}
          total={total}
          nota={notaGeneral}
          onNota={setNotaGeneral}
          nombre={nombre}
          onNombre={setNombre}
          tipo={tipo}
          onTipo={setTipo}
          mesa={data.table?.number ?? ''}
          enviando={enviar.isPending}
          error={enviar.isError ? 'No pudimos enviar el pedido. Inténtalo otra vez.' : null}
          onCerrar={() => setHoja(null)}
          onCambiarCantidad={(clave, cantidad) =>
            setCarrito((previo) =>
              cantidad <= 0
                ? previo.filter((l) => l.clave !== clave)
                : previo.map((l) => (l.clave === clave ? { ...l, cantidad } : l)),
            )
          }
          onEnviar={() => enviar.mutate()}
        />
      )}

      {hoja === 'comanda' && (
        <HojaComanda
          rondas={rondas}
          comensales={mesa?.diners ?? []}
          yo={nombre}
          total={mesa?.total ?? 0}
          moneda={moneda}
          mesa={data.table?.number ?? ''}
          reciente={reciente}
          onCerrar={() => {
            setHoja(null)
            setReciente(null)
          }}
        />
      )}
    </div>
  )
}

/**
 * La cabecera.
 *
 * El color va plano y a plena saturación, y la foto de la fachada va debajo
 * como una banda propia en vez de detrás del texto. Un degradado oscuro sobre
 * la foto es lo que hace todo el mundo, y neutraliza justo lo que aquí importa:
 * con él, la cabecera de todos los restaurantes acaba siendo del mismo gris.
 */
function Portada({
  restaurante,
  mesa,
}: {
  restaurante: MenuPub['restaurant']
  mesa: MenuPub['table']
}) {
  return (
    <header>
      <div
        className="px-5 pb-7 pt-9"
        style={{
          background: 'var(--marca-pura, var(--color-marca-700))',
          color: 'var(--tinta-marca, #ffffff)',
        }}
      >
        <div className="mx-auto flex max-w-2xl items-end gap-4">
          {restaurante.logo_url && (
            <img
              src={restaurante.logo_url}
              alt=""
              className="h-16 w-16 shrink-0 rounded-2xl bg-white/95 object-contain p-1.5"
            />
          )}

          <div className="min-w-0 flex-1">
            {/* El interletraje va en línea y no como utilidad: la regla base
                `h1,h2,h3` de index.css vive fuera de las capas de Tailwind y
                gana a `tracking-*`, que sí está dentro de una. */}
            <h1
              className="text-[2rem] font-extrabold leading-[1.05]"
              style={{ letterSpacing: '-0.03em' }}
            >
              {restaurante.name}
            </h1>
            {/* Sin opacidad. La jerarquía sale del tamaño y el peso, porque el
                color de marca lo elige cada restaurante: bajar la opacidad del
                texto sobre un fondo cualquiera es apostar a que el contraste
                aguante, y con un tono claro no aguanta. */}
            {restaurante.address && (
              <p className="mt-1.5 truncate text-sm font-medium">{restaurante.address}</p>
            )}
          </div>
        </div>

        {/* Troquelado en vez de relleno: un fondo translúcido tiñe la tinta
            con el color de la cabecera y le come contraste justo al dato que
            confirma que estás pidiendo a tu mesa y no a la de al lado. */}
        {mesa && (
          <div className="mx-auto mt-6 max-w-2xl">
            <p
              className="inline-flex items-baseline gap-2.5 rounded-full border px-4 py-1.5"
              style={{ borderColor: 'color-mix(in srgb, var(--tinta-marca, #fff) 45%, transparent)' }}
            >
              <span
                className="text-micro font-semibold uppercase"
                style={{ letterSpacing: '0.16em' }}
              >
                Mesa
              </span>
              <span className="cifras text-lg font-bold">{mesa.number}</span>
            </p>
          </div>
        )}
      </div>

      {restaurante.cover_url && (
        <img
          src={restaurante.cover_url}
          alt=""
          className="h-32 w-full object-cover sm:h-44"
        />
      )}
    </header>
  )
}

/**
 * Las categorías, siempre a mano.
 *
 * Señala dónde estás; no filtra. La carta se recorre entera como la de papel, y
 * esconder el resto sería quitarle al comensal justo lo que vino a ojear.
 *
 * Sin emoji por categoría, aunque quede más vistoso: no hay campo que los
 * guarde, y adivinarlos por el nombre acierta con "Sopas" y falla con
 * "Fritanga" o "Menú del día". Lo mismo que con las fotos de producto, aquí lo
 * que hay que aguantar es lo que escriba cada restaurante.
 */
function BarraCategorias({
  categorias,
  activa,
  onElegir,
}: {
  categorias: MenuPub['categories']
  activa: number | null
  onElegir: (id: number) => void
}) {
  const tira = useRef<HTMLDivElement>(null)

  // Hasta que el observador dispare no hay sección activa, y en el primer
  // render la carta ni siquiera ha llegado. Se deriva aquí en vez de sembrar el
  // estado: al abrir, lo que se está mirando es la primera sección.
  const actual = activa ?? categorias[0]?.id ?? null

  // La pestaña activa se trae al centro sola. Se mueve solo la tira, nunca la
  // página: `scrollIntoView` habría arrastrado también el scroll vertical y
  // peleado con el salto que acaba de hacer el usuario.
  useEffect(() => {
    const carril = tira.current
    const boton = carril?.querySelector<HTMLElement>('[data-activa="true"]')
    if (!carril || !boton) return

    carril.scrollTo({
      left: Math.max(0, boton.offsetLeft - carril.clientWidth / 2 + boton.clientWidth / 2),
      behavior: comportamiento(),
    })
  }, [actual])

  return (
    <nav
      aria-label="Secciones de la carta"
      className="sticky top-0 z-20 border-b border-piedra-200 bg-white/95 backdrop-blur"
    >
      <div
        ref={tira}
        className="mx-auto flex max-w-2xl gap-1 overflow-x-auto px-3
                   [-ms-overflow-style:none] [scrollbar-width:none]
                   [&::-webkit-scrollbar]:hidden"
      >
        {categorias.map((categoria) => {
          const puesta = categoria.id === actual

          return (
            <button
              key={categoria.id}
              data-activa={puesta}
              aria-current={puesta ? 'true' : undefined}
              onClick={() => onElegir(categoria.id)}
              className={`min-h-12 shrink-0 whitespace-nowrap border-b-2 px-3 text-sm font-semibold ${
                puesta ? 'border-marca-700 text-marca-800' : 'border-transparent text-piedra-500'
              }`}
            >
              {categoria.name}
            </button>
          )
        })}
      </div>
    </nav>
  )
}

/**
 * El rótulo de sección de una carta impresa: nombre, filete hasta el margen y
 * el número de platos donde en el papel irían los puntos suspensivos hasta el
 * precio. El número no es decoración — dice cuánto hay que recorrer antes de
 * decidir.
 */
function RotuloSeccion({ nombre, platos }: { nombre: string; platos: number }) {
  return (
    <div className="mb-5 flex items-center gap-4">
      <h2
        className="text-micro font-bold uppercase text-marca-800"
        style={{ letterSpacing: '0.18em' }}
      >
        {nombre}
      </h2>
      <span className="h-px flex-1 bg-piedra-300" />
      <span className="cifras text-micro text-piedra-500">{platos}</span>
    </div>
  )
}

function Plato({
  producto,
  moneda,
  deshabilitado,
  onElegir,
}: {
  producto: ProductoPub
  moneda: string
  deshabilitado: boolean
  onElegir: () => void
}) {
  return (
    <button
      onClick={onElegir}
      disabled={deshabilitado}
      className="flex w-full items-center gap-4 border-b border-piedra-200 py-4 text-left
                 last:border-b-0 disabled:opacity-55"
    >
      <FotoProducto
        nombre={producto.name}
        url={producto.image_url}
        redondeo="rounded-lg"
        className="h-[4.5rem] w-[4.5rem] shrink-0 text-xl"
      />

      {/* Nombre, descripción y precio en una sola columna: el ojo baja una vez
          por plato en lugar de cruzar la fila para emparejar precio y nombre. */}
      <div className="min-w-0 flex-1">
        <p className="font-semibold leading-snug text-piedra-900">{producto.name}</p>
        {producto.description && (
          <p className="mt-1 line-clamp-2 text-sm leading-snug text-piedra-500">
            {producto.description}
          </p>
        )}
        <p className="cifras mt-1.5 font-bold text-piedra-900">
          {dinero(producto.price, moneda)}
        </p>
      </div>

      {!deshabilitado && (
        <span
          aria-hidden
          className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-xl font-bold"
          style={{
            background: 'var(--marca-pura, var(--color-marca-700))',
            color: 'var(--tinta-marca, #ffffff)',
          }}
        >
          +
        </span>
      )}
    </button>
  )
}

/**
 * La barra de abajo, que es lo único que esta carta tiene y una impresa no
 * puede tener.
 *
 * Hace dos trabajos en un solo sitio: lo que llevas puesto tú y lo que lleva la
 * mesa entera. Separarlos en dos barras dejaría la mitad de un móvil ocupada
 * por controles, así que la cuenta de la mesa pasa a ser una línea fina encima
 * cuando además hay carrito.
 */
function BarraMesa({
  unidades,
  totalCarrito,
  rondas,
  totalMesa,
  moneda,
  onCarrito,
  onComanda,
}: {
  unidades: number
  totalCarrito: number
  rondas: number
  totalMesa: number
  moneda: string
  onCarrito: () => void
  onComanda: () => void
}) {
  const previo = useRef(rondas)
  const [avisa, setAvisa] = useState(false)

  useEffect(() => {
    // Creció sin que fuera cosa tuya: alguien más de la mesa acaba de pedir.
    if (rondas > previo.current) {
      setAvisa(true)
      const t = setTimeout(() => setAvisa(false), 2200)
      return () => clearTimeout(t)
    }
    previo.current = rondas
  }, [rondas])

  if (unidades === 0 && rondas === 0) return null

  if (unidades === 0) {
    return (
      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-piedra-200 bg-white/95 p-3 backdrop-blur">
        <button
          onClick={onComanda}
          className={`mx-auto flex w-full max-w-2xl items-center justify-between gap-3 rounded-xl
                      border border-piedra-300 px-4 py-3 text-left transition-colors
                      ${avisa ? 'border-marca-500 bg-marca-50' : ''}`}
        >
          <span>
            <span className="block text-micro font-semibold uppercase tracking-[0.14em] text-piedra-500">
              {avisa ? 'Alguien más acaba de pedir' : 'Cuenta de la mesa'}
            </span>
            <span className="text-sm font-medium text-piedra-800">
              {rondas === 1 ? '1 pedido' : `${rondas} pedidos`}
            </span>
          </span>
          <span className="cifras text-lg font-bold text-piedra-900">
            {dinero(totalMesa, moneda)}
          </span>
        </button>
      </div>
    )
  }

  return (
    <div className="fixed inset-x-0 bottom-0 z-30">
      {rondas > 0 && (
        <button
          onClick={onComanda}
          className="flex w-full items-center justify-center gap-2 border-t border-piedra-200
                     bg-white/95 px-5 py-2 text-xs text-piedra-600 backdrop-blur"
        >
          La mesa lleva{' '}
          <span className="cifras font-bold text-piedra-800">{dinero(totalMesa, moneda)}</span>
          <span className="text-piedra-400">· ver</span>
        </button>
      )}

      <button
        onClick={onCarrito}
        className="flex w-full items-center justify-between gap-3 px-5 py-4"
        style={{
          background: 'var(--marca-pura, var(--color-marca-700))',
          color: 'var(--tinta-marca, #ffffff)',
        }}
      >
        <span
          className="cifras flex h-8 w-8 items-center justify-center rounded-full border font-bold"
          style={{ borderColor: 'color-mix(in srgb, var(--tinta-marca, #fff) 45%, transparent)' }}
        >
          {unidades}
        </span>
        <span className="font-semibold">Ver mi pedido</span>
        <span className="cifras font-bold">{dinero(totalCarrito, moneda)}</span>
      </button>
    </div>
  )
}

/** La cuenta de la mesa, ronda por ronda. */
/**
 * Lo que le toca a cada uno.
 *
 * El dato sale solo: desde el QR cada comensal abre su propio pedido, así que
 * "lo de Ana" ya viene separado del servidor. Aquí solo hay que decidir qué
 * mirar primero, y eso es **lo tuyo**: quien abre esto lo hace en su móvil, con
 * su nombre guardado, y lo que quiere saber es cuánto pone él. El resto de la
 * mesa es contexto.
 *
 * El reparto a partes iguales va aparte y no sustituye al otro: son dos formas
 * distintas de repartir y en una mesa se discuten las dos.
 */
function Reparto({
  comensales,
  yo,
  total,
  moneda,
  modo,
  onModo,
}: {
  comensales: ComensalPub[]
  yo: string
  total: number
  moneda: string
  modo: 'suyo' | 'iguales'
  onModo: (v: 'suyo' | 'iguales') => void
}) {
  const normal = (v: string | null) => (v ?? '').trim().toLowerCase()
  const mio = yo.trim() ? comensales.find((c) => normal(c.diner_name) === normal(yo)) : undefined

  // A partes iguales se divide entre los grupos que hay. Los pedidos sin
  // nombre cuentan como uno: no se sabe cuántas personas son, y suponerlo
  // cambiaría la cifra que alguien va a pagar.
  const partes = comensales.length
  const igual = partes > 0 ? total / partes : 0

  return (
    <section className="mb-5">
      <div className="mb-3 grid grid-cols-2 gap-2">
        {(
          [
            ['suyo', 'Cada quien lo suyo'],
            ['iguales', 'A partes iguales'],
          ] as ['suyo' | 'iguales', string][]
        ).map(([valor, etiqueta]) => (
          <button
            key={valor}
            type="button"
            aria-pressed={modo === valor}
            onClick={() => onModo(valor)}
            className={`min-h-12 rounded-xl border px-3 text-sm font-semibold ${
              modo === valor
                ? 'border-marca-600 bg-marca-50 text-marca-800'
                : 'border-piedra-300 text-piedra-600'
            }`}
          >
            {etiqueta}
          </button>
        ))}
      </div>

      {modo === 'iguales' ? (
        <div className="rounded-xl border border-piedra-200 bg-white p-4 text-center">
          <p className="text-micro font-semibold uppercase text-piedra-500" style={{ letterSpacing: '0.14em' }}>
            Cada uno, entre {partes}
          </p>
          <p className="cifras mt-1 text-3xl font-bold text-piedra-900">{dinero(igual, moneda)}</p>
        </div>
      ) : (
        <>
          {/* Lo tuyo primero y en grande: es la cifra que has venido a ver. */}
          {mio && (
            <div className="mb-2 rounded-xl border-2 border-marca-600 bg-marca-50 p-4">
              <p className="text-micro font-semibold uppercase text-marca-800" style={{ letterSpacing: '0.14em' }}>
                Lo tuyo
              </p>
              <p className="cifras mt-0.5 text-3xl font-bold text-marca-900">
                {dinero(mio.total, moneda)}
              </p>
            </div>
          )}

          <ul className="overflow-hidden rounded-xl border border-piedra-200 bg-white">
            {comensales.map((c, i) => (
              <li
                key={c.diner_name ?? `sin-nombre-${i}`}
                className="flex items-baseline justify-between gap-3 border-b border-piedra-100 px-4 py-3 last:border-b-0"
              >
                <span className="min-w-0 truncate text-piedra-800">
                  {c.diner_name ?? 'Sin nombre'}
                  {c.diner_name && mio?.diner_name === c.diner_name && (
                    <span className="ml-2 text-xs font-semibold text-marca-700">tú</span>
                  )}
                </span>
                <span className="cifras shrink-0 font-bold text-piedra-900">
                  {dinero(c.total, moneda)}
                </span>
              </li>
            ))}
          </ul>

          {!mio && yo.trim() === '' && (
            <p className="mt-2 text-xs text-piedra-500">
              Pon tu nombre al pedir y aquí verás lo tuyo aparte.
            </p>
          )}
        </>
      )}
    </section>
  )
}

function HojaComanda({
  rondas,
  comensales,
  yo,
  total,
  moneda,
  mesa,
  reciente,
  onCerrar,
}: {
  rondas: RondaMesa[]
  comensales: ComensalPub[]
  yo: string
  total: number
  moneda: string
  mesa: string
  reciente: number | null
  onCerrar: () => void
}) {
  const [reparto, setReparto] = useState<'suyo' | 'iguales'>('suyo')

  return (
    <Hoja titulo={mesa ? `Mesa ${mesa}` : 'La cuenta'} onCerrar={onCerrar} cerrar="Seguir viendo">
      <div className="flex-1 overflow-y-auto px-5 py-4">
        {comensales.length > 1 && (
          <Reparto
            comensales={comensales}
            yo={yo}
            total={total}
            moneda={moneda}
            modo={reparto}
            onModo={setReparto}
          />
        )}

        {rondas.length === 0 ? (
          <p className="py-10 text-center text-piedra-500">
            Todavía no habéis pedido nada. Lo que pida cualquiera de la mesa aparece aquí.
          </p>
        ) : (
          <ol className="flex flex-col gap-3">
            {rondas.map((ronda) => {
              const estado = ESTADO_COMENSAL[ronda.status]
              const esTuyo = ronda.id === reciente

              return (
                <li
                  key={ronda.id}
                  className={`rounded-xl border p-4 ${
                    esTuyo ? 'border-marca-500 bg-marca-50' : 'border-piedra-200 bg-white'
                  }`}
                >
                  <div className="mb-3 flex flex-wrap items-center gap-2">
                    <span className="text-micro font-bold uppercase tracking-[0.14em] text-piedra-500">
                      Pedido {ronda.round}
                    </span>
                    {ronda.diner_name && (
                      <span className="text-sm font-semibold text-piedra-800">
                        {ronda.diner_name}
                      </span>
                    )}
                    {ronda.type === 'takeaway' && (
                      <span className="rounded-full bg-piedra-200 px-2 py-0.5 text-micro font-semibold text-piedra-700">
                        Para llevar
                      </span>
                    )}
                    {estado && (
                      <span
                        className={`ml-auto rounded-full px-2.5 py-0.5 text-micro font-semibold ${estado.clase}`}
                      >
                        {estado.texto}
                      </span>
                    )}
                  </div>

                  <ul className="flex flex-col gap-1.5">
                    {ronda.items.map((item, i) => (
                      <li key={i} className="flex items-baseline gap-3 text-sm">
                        <span className="cifras shrink-0 text-piedra-500">{item.quantity}×</span>
                        <span className="min-w-0 flex-1 text-piedra-800">
                          {item.name}
                          {item.additionals.length > 0 && (
                            <span className="text-piedra-500"> · {item.additionals.join(', ')}</span>
                          )}
                        </span>
                        <span className="cifras shrink-0 text-piedra-600">
                          {dinero(item.subtotal, moneda)}
                        </span>
                      </li>
                    ))}
                  </ul>
                </li>
              )
            })}
          </ol>
        )}
      </div>

      <footer className="border-t border-piedra-200 p-5">
        <div className="mb-3 flex items-baseline justify-between">
          <span className="font-semibold text-piedra-700">Total de la mesa</span>
          <span className="cifras text-2xl font-bold text-piedra-900">{dinero(total, moneda)}</span>
        </div>

        <button
          onClick={onCerrar}
          className="min-h-13 w-full rounded-xl font-semibold"
          style={{
            background: 'var(--marca-pura, var(--color-marca-700))',
            color: 'var(--tinta-marca, #ffffff)',
          }}
        >
          Pedir algo más
        </button>

        <p className="mt-3 text-center text-xs text-piedra-500">
          Se paga al final, todo junto, con el personal.
        </p>
      </footer>
    </Hoja>
  )
}

function HojaProducto({
  producto,
  moneda,
  onCancelar,
  onAgregar,
}: {
  producto: ProductoPub
  moneda: string
  onCancelar: () => void
  onAgregar: (adicionales: AdicionalPub[], notas: string, cantidad: number) => void
}) {
  const [elegidos, setElegidos] = useState<Record<number, number[]>>({})
  const [notas, setNotas] = useState('')
  const [cantidad, setCantidad] = useState(1)

  const grupos = producto.additional_groups.filter((g) => g.additionals.length > 0)

  const seleccionados = grupos.flatMap((g) =>
    g.additionals.filter((a) => (elegidos[g.id] ?? []).includes(a.id)),
  )
  const faltan = grupos.filter((g) => g.is_required && (elegidos[g.id] ?? []).length === 0)

  const extras = seleccionados.reduce((s, a) => s + Number(a.extra_price), 0)
  const total = (Number(producto.price) + extras) * cantidad

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center">
      <div className="flotante flex max-h-[92dvh] w-full max-w-lg flex-col rounded-t-2xl bg-white sm:rounded-2xl">
        <header className="border-b border-piedra-200 px-5 py-4">
          <h2 className="text-lg font-bold text-piedra-900">{producto.name}</h2>
          {producto.description && (
            <p className="mt-0.5 text-sm text-piedra-500">{producto.description}</p>
          )}
        </header>

        <div className="flex-1 overflow-y-auto px-5 py-4">
          {grupos.map((grupo) => {
            const unico = grupo.selection_type === 'single'

            return (
              <fieldset key={grupo.id} className="mb-5">
                <legend className="mb-2 flex items-center gap-2 font-semibold text-piedra-800">
                  {grupo.name}
                  {grupo.is_required && (
                    <span className="rounded-full bg-pendiente-suave px-2 py-0.5 text-[11px] font-semibold text-pendiente">
                      Elige una
                    </span>
                  )}
                </legend>

                <div className="flex flex-col gap-1.5">
                  {grupo.additionals.map((a) => {
                    const marcado = (elegidos[grupo.id] ?? []).includes(a.id)

                    return (
                      <label
                        key={a.id}
                        className={`flex min-h-13 cursor-pointer items-center gap-3 rounded-xl border px-4 ${
                          marcado ? 'border-marca-500 bg-marca-50' : 'border-piedra-200'
                        }`}
                      >
                        <input
                          type={unico ? 'radio' : 'checkbox'}
                          name={`g-${grupo.id}`}
                          checked={marcado}
                          onChange={() =>
                            setElegidos((p) => {
                              const act = p[grupo.id] ?? []
                              if (unico) {
                                return { ...p, [grupo.id]: act.includes(a.id) ? [] : [a.id] }
                              }
                              return {
                                ...p,
                                [grupo.id]: act.includes(a.id)
                                  ? act.filter((x) => x !== a.id)
                                  : [...act, a.id],
                              }
                            })
                          }
                          className="h-5 w-5 accent-marca-600"
                        />
                        <span className="flex-1 text-piedra-800">{a.name}</span>
                        {Number(a.extra_price) > 0 && (
                          <span className="cifras text-sm text-piedra-500">
                            +{dinero(a.extra_price, moneda)}
                          </span>
                        )}
                      </label>
                    )
                  })}
                </div>
              </fieldset>
            )
          })}

          <label className="flex flex-col gap-1.5">
            <span className="font-semibold text-piedra-800">
              ¿Algo especial? <span className="font-normal text-piedra-500">(opcional)</span>
            </span>
            <textarea
              value={notas}
              onChange={(e) => setNotas(e.target.value)}
              rows={2}
              placeholder="Sin cebolla, poco picante…"
              className="rounded-xl border border-piedra-300 px-3.5 py-2.5 focus:border-marca-500 focus:outline-none"
            />
          </label>
        </div>

        <footer className="border-t border-piedra-200 p-4">
          <div className="mb-3 flex items-center justify-between">
            <ContadorPub valor={cantidad} onCambio={setCantidad} />
            <span className="cifras text-xl font-bold text-piedra-900">{dinero(total, moneda)}</span>
          </div>

          <div className="flex gap-2">
            <button
              onClick={onCancelar}
              className="min-h-13 flex-1 rounded-xl border border-piedra-300 font-semibold text-piedra-700"
            >
              Cancelar
            </button>
            <button
              onClick={() => onAgregar(seleccionados, notas, cantidad)}
              disabled={faltan.length > 0}
              className="min-h-13 flex-[2] rounded-xl font-semibold disabled:opacity-40"
              style={{
                background: 'var(--marca-pura, var(--color-marca-700))',
                color: 'var(--tinta-marca, #ffffff)',
              }}
            >
              {faltan.length > 0 ? `Elige ${faltan[0].name}` : 'Añadir'}
            </button>
          </div>
        </footer>
      </div>
    </div>
  )
}

function HojaCarrito({
  carrito,
  moneda,
  total,
  nota,
  onNota,
  nombre,
  onNombre,
  tipo,
  onTipo,
  mesa,
  enviando,
  error,
  onCerrar,
  onCambiarCantidad,
  onEnviar,
}: {
  carrito: Linea[]
  moneda: string
  total: number
  nota: string
  onNota: (v: string) => void
  nombre: string
  onNombre: (v: string) => void
  tipo: TipoPedidoQr
  onTipo: (v: TipoPedidoQr) => void
  mesa: string
  enviando: boolean
  error: string | null
  onCerrar: () => void
  onCambiarCantidad: (clave: string, cantidad: number) => void
  onEnviar: () => void
}) {
  return (
    <Hoja titulo="Mi pedido" onCerrar={onCerrar} cerrar="Seguir viendo">
      <div className="flex-1 overflow-y-auto px-5 py-4">
        <ul className="flex flex-col gap-3">
          {carrito.map((l) => {
            const extras = l.adicionales.reduce((s, a) => s + Number(a.extra_price), 0)

            return (
              <li key={l.clave} className="flex items-start gap-3 border-b border-piedra-100 pb-3">
                <div className="min-w-0 flex-1">
                  <p className="font-semibold text-piedra-900">{l.producto.name}</p>
                  {l.adicionales.length > 0 && (
                    <p className="text-sm text-piedra-500">
                      {l.adicionales.map((a) => a.name).join(', ')}
                    </p>
                  )}
                  {l.notas && <p className="text-sm italic text-pendiente">{l.notas}</p>}
                  <p className="cifras mt-1 text-sm text-piedra-600">
                    {dinero((Number(l.producto.price) + extras) * l.cantidad, moneda)}
                  </p>
                </div>

                <ContadorPub
                  valor={l.cantidad}
                  minimo={0}
                  onCambio={(v) => onCambiarCantidad(l.clave, v)}
                />
              </li>
            )
          })}
        </ul>

        {/* Dónde se come. Va aquí y no por plato: cocina y empaquetado se
            deciden por comanda, y partir una comanda en dos destinos es lo que
            hace que llegue medio pedido frío. */}
        <fieldset className="mt-6">
          <legend className="mb-2 font-semibold text-piedra-800">¿Cómo lo servimos?</legend>
          <div className="grid grid-cols-2 gap-2">
            {(
              [
                ['dine_in', mesa ? `Aquí, mesa ${mesa}` : 'Aquí'],
                ['takeaway', 'Para llevar'],
              ] as [TipoPedidoQr, string][]
            ).map(([valor, etiqueta]) => (
              <button
                key={valor}
                type="button"
                aria-pressed={tipo === valor}
                onClick={() => onTipo(valor)}
                className={`min-h-13 rounded-xl border px-3 font-semibold ${
                  tipo === valor
                    ? 'border-marca-600 bg-marca-50 text-marca-800'
                    : 'border-piedra-300 text-piedra-600'
                }`}
              >
                {etiqueta}
              </button>
            ))}
          </div>
        </fieldset>

        <label className="mt-5 flex flex-col gap-1.5">
          <span className="font-semibold text-piedra-800">
            ¿Cómo te llamamos? <span className="font-normal text-piedra-500">(opcional)</span>
          </span>
          <input
            value={nombre}
            onChange={(e) => onNombre(e.target.value)}
            maxLength={60}
            placeholder="Ana"
            className="rounded-xl border border-piedra-300 px-3.5 py-2.5 focus:border-marca-500 focus:outline-none"
          />
          <span className="text-xs text-piedra-500">
            Si en la mesa pedís varios, así sabemos de quién es cada plato.
          </span>
        </label>

        <label className="mt-5 flex flex-col gap-1.5">
          <span className="font-semibold text-piedra-800">
            Nota para la cocina <span className="font-normal text-piedra-500">(opcional)</span>
          </span>
          <textarea
            value={nota}
            onChange={(e) => onNota(e.target.value)}
            rows={2}
            placeholder="Todo junto, por favor…"
            className="rounded-xl border border-piedra-300 px-3.5 py-2.5 focus:border-marca-500 focus:outline-none"
          />
        </label>
      </div>

      <footer className="border-t border-piedra-200 p-5">
        {error && (
          <p
            role="alert"
            className="mb-3 rounded-lg bg-cancelado-suave px-3 py-2 text-sm text-cancelado"
          >
            {error}
          </p>
        )}

        <div className="mb-3 flex items-center justify-between">
          <span className="font-semibold text-piedra-700">Total</span>
          <span className="cifras text-2xl font-bold text-piedra-900">{dinero(total, moneda)}</span>
        </div>

        <button
          onClick={onEnviar}
          disabled={enviando || carrito.length === 0}
          className="min-h-14 w-full rounded-xl text-base font-semibold disabled:opacity-40"
          style={{
            background: 'var(--marca-pura, var(--color-marca-700))',
            color: 'var(--tinta-marca, #ffffff)',
          }}
        >
          {enviando ? 'Enviando…' : 'Enviar el pedido'}
        </button>

        <p className="mt-2 text-center text-xs text-piedra-500">
          Se paga al final, con el personal.
        </p>
      </footer>
    </Hoja>
  )
}

/** Pantalla completa con cabecera y salida, que es como se usan las dos hojas. */
function Hoja({
  titulo,
  cerrar,
  onCerrar,
  children,
}: {
  titulo: string
  cerrar: string
  onCerrar: () => void
  children: React.ReactNode
}) {
  useEffect(() => {
    const alPulsar = (e: KeyboardEvent) => e.key === 'Escape' && onCerrar()
    window.addEventListener('keydown', alPulsar)
    return () => window.removeEventListener('keydown', alPulsar)
  }, [onCerrar])

  return (
    <div role="dialog" aria-modal="true" aria-label={titulo} className="fixed inset-0 z-50 flex flex-col bg-white">
      <header className="flex items-center justify-between border-b border-piedra-200 px-5 py-4">
        <h2 className="text-lg font-bold text-piedra-900">{titulo}</h2>
        {/* El área táctil llega a 44 px sin crecer de tamaño visual: el margen
            negativo se come el relleno, así que la cabecera no se mueve. */}
        <button
          onClick={onCerrar}
          className="-my-2 -mr-2 flex min-h-11 items-center px-2 font-medium text-piedra-500"
        >
          {cerrar}
        </button>
      </header>
      {children}
    </div>
  )
}

function ContadorPub({
  valor,
  onCambio,
  minimo = 1,
}: {
  valor: number
  onCambio: (v: number) => void
  minimo?: number
}) {
  return (
    <div className="flex shrink-0 items-center gap-1 rounded-xl border border-piedra-300">
      <button
        onClick={() => onCambio(Math.max(minimo, valor - 1))}
        aria-label="Quitar uno"
        className="flex h-11 w-11 items-center justify-center text-xl font-bold text-piedra-600"
      >
        −
      </button>
      <span className="cifras w-7 text-center font-bold text-piedra-900">{valor}</span>
      <button
        onClick={() => onCambio(valor + 1)}
        aria-label="Añadir uno"
        className="flex h-11 w-11 items-center justify-center text-xl font-bold text-piedra-600"
      >
        +
      </button>
    </div>
  )
}

function Cargando() {
  return (
    <div className="min-h-dvh bg-piedra-50">
      <div className="h-44 animate-pulse bg-piedra-200" />
      <div className="mx-auto max-w-2xl px-5 pt-8">
        {Array.from({ length: 5 }).map((_, i) => (
          <div key={i} className="mb-4 h-20 animate-pulse rounded-lg bg-piedra-200" />
        ))}
      </div>
    </div>
  )
}

function Centrado({ titulo, texto }: { titulo: string; texto: string }) {
  return (
    <div className="flex min-h-dvh flex-col items-center justify-center bg-piedra-50 p-6 text-center">
      <h1 className="text-xl font-bold text-piedra-900">{titulo}</h1>
      <p className="mt-2 max-w-sm text-piedra-500">{texto}</p>
    </div>
  )
}
