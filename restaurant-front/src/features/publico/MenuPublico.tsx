import { useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useParams, useSearchParams } from 'react-router-dom'
import axios from 'axios'
import { dinero } from '../../lib/formato'

/**
 * Menú público del QR.
 *
 * Es lo único que ve el cliente final, y no se parece al panel: quien lo abre
 * no ha iniciado sesión, no ha instalado nada, está sentado en una mesa con su
 * propio móvil y probablemente con mala señal. Así que aquí no hay barra de
 * navegación, ni roles, ni nada que aprender — solo la carta, un carrito y un
 * botón. La mesa la trae el QR; el cliente no la elige ni la escribe.
 */

// Cliente propio, sin el interceptor que redirige al login: aquí no hay sesión
// que expirar y un 401 no debe sacar a nadie a ninguna parte.
const publico = axios.create({ baseURL: '/api', headers: { Accept: 'application/json' } })

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
  restaurant: { id: number; name: string; logo_url: string | null; currency: string }
  table: { id: number; number: string } | null
  categories: { id: number; name: string; image_url: string | null; products: ProductoPub[] }[]
}

type Linea = {
  clave: string
  producto: ProductoPub
  cantidad: number
  notas: string
  adicionales: AdicionalPub[]
}

export default function MenuPublico() {
  const { slug } = useParams()
  const [params] = useSearchParams()
  const codigoMesa = params.get('mesa') ?? ''

  const [carrito, setCarrito] = useState<Linea[]>([])
  const [configurando, setConfigurando] = useState<ProductoPub | null>(null)
  const [carritoAbierto, setCarritoAbierto] = useState(false)
  const [notaGeneral, setNotaGeneral] = useState('')
  const [enviado, setEnviado] = useState<{ id: number; propuesta: boolean } | null>(null)

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

  const moneda = data?.restaurant.currency ?? 'COP'

  const enviar = useMutation({
    mutationFn: async () => {
      const { data: pedido } = await publico.post('/orders/qr', {
        restaurant_slug: slug,
        qr_code: codigoMesa,
        notes: notaGeneral || null,
        items: carrito.map((l) => ({
          product_id: l.producto.id,
          quantity: l.cantidad,
          notes: l.notas || null,
          additionals: l.adicionales.map((a) => a.id),
        })),
      })
      return pedido as { id: number; status: string }
    },
    onSuccess: (pedido) => {
      setEnviado({ id: pedido.id, propuesta: pedido.status === 'proposed' })
      setCarrito([])
      setCarritoAbierto(false)
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

    setCarrito((previo) => {
      const existente = previo.find((l) => l.clave === clave)

      if (existente) {
        return previo.map((l) =>
          l.clave === clave ? { ...l, cantidad: l.cantidad + cantidad } : l,
        )
      }

      return [...previo, { clave, producto, cantidad, notas, adicionales }]
    })

    setConfigurando(null)
  }

  if (isLoading) return <Cargando />

  if (isError || !data) {
    return (
      <Centrado
        emoji="😕"
        titulo="No encontramos este menú"
        texto="Puede que el enlace esté mal o que el restaurante no esté disponible ahora mismo."
      />
    )
  }

  if (enviado) {
    // Lo que ve el cliente tiene que corresponder con lo que de verdad pasó: si
    // el restaurante pide confirmación, decirle "ya está en cocina" sería
    // mentirle y se quedaría esperando comida que nadie ha empezado.
    return (
      <Centrado
        emoji={enviado.propuesta ? '🙋' : '✅'}
        titulo={enviado.propuesta ? '¡Pedido enviado al mesero!' : '¡Pedido enviado!'}
        texto={
          enviado.propuesta
            ? `Pasará por la mesa ${data.table?.number ?? ''} a confirmarlo contigo y de ahí va a cocina. Puedes seguir añadiendo cosas mientras tanto.`
            : data.table
              ? `Ya lo está viendo la cocina. Se lo llevamos a la mesa ${data.table.number}.`
              : 'Ya lo está viendo la cocina.'
        }
      >
        <p className="cifras mt-4 text-sm text-piedra-400">Pedido #{enviado.id}</p>
        <button
          onClick={() => setEnviado(null)}
          className="mt-6 min-h-12 rounded-xl bg-marca-600 px-5 font-semibold text-white"
        >
          Pedir algo más
        </button>
      </Centrado>
    )
  }

  // Sin mesa reconocida no se puede pedir: el pedido no sabría a dónde ir.
  // Se deja ver la carta igualmente, que para eso también sirve.
  const sinMesa = !data.table

  return (
    <div className="min-h-dvh bg-piedra-50 pb-28">
      <header className="border-b border-piedra-200 bg-white px-4 py-4">
        <div className="mx-auto flex max-w-2xl items-center gap-3">
          {data.restaurant.logo_url && (
            <img src={data.restaurant.logo_url} alt="" className="h-11 w-11 rounded-xl object-cover" />
          )}
          <div className="min-w-0">
            <h1 className="truncate text-lg font-bold text-piedra-900">{data.restaurant.name}</h1>
            {data.table ? (
              <p className="text-sm text-piedra-500">Mesa {data.table.number}</p>
            ) : (
              <p className="text-sm text-atencion">Carta — sin mesa asignada</p>
            )}
          </div>
        </div>
      </header>

      {sinMesa && (
        <div className="mx-auto max-w-2xl px-4 pt-4">
          <p className="rounded-xl border border-atencion bg-white p-4 text-sm text-piedra-700">
            Para pedir desde aquí escanea el código QR de tu mesa. Si ya lo hiciste, pide ayuda al
            personal: puede que el código esté dañado.
          </p>
        </div>
      )}

      <main className="mx-auto max-w-2xl px-4 py-4">
        {data.categories.map((categoria) => {
          if (categoria.products.length === 0) return null

          return (
            <section key={categoria.id} className="mb-7">
              <h2 className="mb-3 text-lg font-bold text-piedra-900">{categoria.name}</h2>

              <ul className="flex flex-col gap-2">
                {categoria.products.map((producto) => (
                  <li key={producto.id}>
                    <button
                      onClick={() => {
                        const tieneOpciones = producto.additional_groups.some(
                          (g) => g.additionals.length > 0,
                        )
                        tieneOpciones
                          ? setConfigurando(producto)
                          : agregar(producto, [], '', 1)
                      }}
                      disabled={sinMesa}
                      className="flex w-full items-center gap-3 rounded-2xl border border-piedra-200
                                 bg-white p-3 text-left transition hover:border-marca-300
                                 disabled:opacity-60"
                    >
                      {producto.image_url && (
                        <img
                          src={producto.image_url}
                          alt=""
                          className="h-20 w-20 shrink-0 rounded-xl object-cover"
                        />
                      )}

                      <div className="min-w-0 flex-1">
                        <p className="font-semibold text-piedra-900">{producto.name}</p>
                        {producto.description && (
                          <p className="mt-0.5 line-clamp-2 text-sm text-piedra-500">
                            {producto.description}
                          </p>
                        )}
                        <p className="cifras mt-1 font-bold text-marca-700">
                          {dinero(producto.price, moneda)}
                        </p>
                      </div>

                      {!sinMesa && (
                        <span
                          aria-hidden
                          className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full
                                     bg-marca-600 text-xl font-bold text-white"
                        >
                          +
                        </span>
                      )}
                    </button>
                  </li>
                ))}
              </ul>
            </section>
          )
        })}

        {data.categories.every((c) => c.products.length === 0) && (
          <p className="rounded-2xl border border-dashed border-piedra-300 p-10 text-center
                        text-piedra-500">
            Este menú todavía no tiene productos.
          </p>
        )}
      </main>

      {/* Barra del carrito: siempre visible en cuanto hay algo, porque en un
          móvil el carrito arriba se olvida. */}
      {carrito.length > 0 && !carritoAbierto && (
        <button
          onClick={() => setCarritoAbierto(true)}
          className="fixed inset-x-0 bottom-0 z-30 flex items-center justify-between gap-3
                     bg-marca-600 px-5 py-4 text-white shadow-lg"
        >
          <span className="cifras flex h-8 w-8 items-center justify-center rounded-full
                           bg-white/20 font-bold">
            {unidades}
          </span>
          <span className="font-semibold">Ver mi pedido</span>
          <span className="cifras font-bold">{dinero(total, moneda)}</span>
        </button>
      )}

      {configurando && (
        <HojaProducto
          producto={configurando}
          moneda={moneda}
          onCancelar={() => setConfigurando(null)}
          onAgregar={(ad, notas, cantidad) => agregar(configurando, ad, notas, cantidad)}
        />
      )}

      {carritoAbierto && (
        <HojaCarrito
          carrito={carrito}
          moneda={moneda}
          total={total}
          nota={notaGeneral}
          onNota={setNotaGeneral}
          enviando={enviar.isPending}
          error={enviar.isError ? 'No pudimos enviar el pedido. Inténtalo otra vez.' : null}
          onCerrar={() => setCarritoAbierto(false)}
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
    </div>
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
      <div className="flex max-h-[92dvh] w-full max-w-lg flex-col rounded-t-2xl bg-white sm:rounded-2xl">
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
                    <span className="rounded-full bg-pendiente-suave px-2 py-0.5 text-[11px]
                                     font-semibold text-pendiente">
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
                        className={`flex min-h-13 cursor-pointer items-center gap-3 rounded-xl
                                    border px-4 ${
                                      marcado
                                        ? 'border-marca-500 bg-marca-50'
                                        : 'border-piedra-200'
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
              ¿Algo especial? <span className="font-normal text-piedra-400">(opcional)</span>
            </span>
            <textarea
              value={notas}
              onChange={(e) => setNotas(e.target.value)}
              rows={2}
              placeholder="Sin cebolla, poco picante…"
              className="rounded-xl border border-piedra-300 px-3.5 py-2.5
                         focus:border-marca-500 focus:outline-none"
            />
          </label>
        </div>

        <footer className="border-t border-piedra-200 p-4">
          <div className="mb-3 flex items-center justify-between">
            <ContadorPub valor={cantidad} onCambio={setCantidad} />
            <span className="cifras text-xl font-bold text-piedra-900">
              {dinero(total, moneda)}
            </span>
          </div>

          <div className="flex gap-2">
            <button
              onClick={onCancelar}
              className="min-h-13 flex-1 rounded-xl border border-piedra-300 font-semibold
                         text-piedra-700"
            >
              Cancelar
            </button>
            <button
              onClick={() => onAgregar(seleccionados, notas, cantidad)}
              disabled={faltan.length > 0}
              className="min-h-13 flex-[2] rounded-xl bg-marca-600 font-semibold text-white
                         disabled:opacity-40"
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
  enviando: boolean
  error: string | null
  onCerrar: () => void
  onCambiarCantidad: (clave: string, cantidad: number) => void
  onEnviar: () => void
}) {
  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-white">
      <header className="flex items-center justify-between border-b border-piedra-200 px-5 py-4">
        <h2 className="text-lg font-bold text-piedra-900">Mi pedido</h2>
        <button onClick={onCerrar} className="font-medium text-piedra-500">
          Seguir viendo
        </button>
      </header>

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
                  {l.notas && <p className="text-sm text-pendiente italic">{l.notas}</p>}
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

        <label className="mt-5 flex flex-col gap-1.5">
          <span className="font-semibold text-piedra-800">
            Nota para la cocina <span className="font-normal text-piedra-400">(opcional)</span>
          </span>
          <textarea
            value={nota}
            onChange={(e) => onNota(e.target.value)}
            rows={2}
            placeholder="Todo junto, por favor…"
            className="rounded-xl border border-piedra-300 px-3.5 py-2.5
                       focus:border-marca-500 focus:outline-none"
          />
        </label>
      </div>

      <footer className="border-t border-piedra-200 p-5">
        {error && (
          <p role="alert" className="mb-3 rounded-lg bg-cancelado-suave px-3 py-2 text-sm text-cancelado">
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
          className="min-h-14 w-full rounded-xl bg-marca-600 text-base font-semibold text-white
                     disabled:opacity-40"
        >
          {enviando ? 'Enviando…' : 'Enviar a cocina'}
        </button>

        <p className="mt-2 text-center text-xs text-piedra-400">
          Se paga al final, con el personal.
        </p>
      </footer>
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
    <div className="min-h-dvh bg-piedra-50 p-4">
      <div className="mx-auto max-w-2xl">
        <div className="mb-6 h-12 animate-pulse rounded-xl bg-piedra-200" />
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="mb-2 h-24 animate-pulse rounded-2xl bg-piedra-200" />
        ))}
      </div>
    </div>
  )
}

function Centrado({
  emoji,
  titulo,
  texto,
  children,
}: {
  emoji: string
  titulo: string
  texto: string
  children?: React.ReactNode
}) {
  return (
    <div className="flex min-h-dvh flex-col items-center justify-center bg-piedra-50 p-6 text-center">
      <p className="text-5xl" aria-hidden>
        {emoji}
      </p>
      <h1 className="mt-3 text-xl font-bold text-piedra-900">{titulo}</h1>
      <p className="mt-1 max-w-sm text-piedra-500">{texto}</p>
      {children}
    </div>
  )
}
