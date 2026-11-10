import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, interpretarError } from '../../api/client'
import { pedidosApi } from '../../api/pedidos'
import type { MetodoPago, Pedido } from '../../api/tipos'
import { dinero, METODOS_PAGO, TIPOS } from '../../lib/formato'
import { useSesion } from '../auth/SesionContext'

/**
 * Cobro y cierre del pedido.
 *
 * El número que manda aquí es el vuelto: es lo que el cajero lee en voz alta y
 * devuelve, y equivocarse cuesta dinero de la caja. Por eso se calcula en vivo
 * mientras se teclea y se muestra más grande que ningún otro dato de la
 * pantalla.
 */

/** Billetes colombianos, para los atajos de "con cuánto paga". */
const BILLETES = [5000, 10000, 20000, 50000, 100000]

/** Métodos que necesitan guardar una referencia de la transacción. */
const CON_REFERENCIA: MetodoPago[] = ['nequi', 'daviplata', 'bancolombia', 'transfer', 'card']

export default function Cobrar() {
  const { id } = useParams()
  const pedidoId = Number(id)
  const { sesion } = useSesion()
  const moneda = sesion?.restaurante.currency ?? 'COP'
  const navegar = useNavigate()
  const clienteQuery = useQueryClient()

  const [metodo, setMetodo] = useState<MetodoPago>('cash')
  const [recibido, setRecibido] = useState('')
  const [referencia, setReferencia] = useState('')
  // Qué se está cobrando: 'pedido', 'mesa' o 'comensal:<nombre>'.
  const [alcance, setAlcance] = useState<string>('pedido')
  // Guarda también qué se cobró y cómo. Al cerrar, los pedidos de la mesa
  // desaparecen, así que lo derivado vuelve a su valor base justo antes de
  // pintar el resultado: la pantalla final decía "Pedido cobrado" tras cobrar
  // la mesa entera.
  const [cobrado, setCobrado] = useState<{
    vuelto: number
    pedido: Pedido
    titulo: string
    detalle: string
  } | null>(null)

  const { data: pedido, isLoading } = useQuery({
    queryKey: ['pedido', pedidoId],
    queryFn: () => pedidosApi.ver(pedidoId),
    enabled: Number.isFinite(pedidoId),
  })

  const mesaId = pedido?.table?.id ?? null

  // Desde que cada comensal pide por su cuenta con el QR, una mesa puede tener
  // varios pedidos abiertos. Cobrarlos de uno en uno obliga al cajero a sumar
  // el total a mano y a repetir el cobro tantas veces como móviles haya.
  const { data: pedidosMesa } = useQuery({
    queryKey: ['mesa-pedidos', mesaId],
    queryFn: async () => (await api.get<Pedido[]>(`/tables/${mesaId}/orders`)).data,
    enabled: mesaId !== null,
  })

  const cobrables = useMemo(
    () => (pedidosMesa ?? []).filter((p) => !p.payment && p.status !== 'cancelled'),
    [pedidosMesa],
  )
  const hayVariosEnLaMesa = cobrables.length > 1
  const totalMesa = cobrables.reduce((s, p) => s + Number(p.total), 0)

  // Lo que le toca a cada comensal. Misma regla que `Order::repartoPorComensal`
  // en el backend: se agrupa por el nombre normalizado, se muestra el primero
  // que se escribió, y los pedidos sin nombre van juntos al final porque no se
  // puede saber si son la misma persona.
  const comensales = useMemo(() => {
    const grupos = new Map<string, { nombre: string | null; ids: number[]; total: number }>()

    // Del más viejo al más nuevo, como `repartoPorComensal` en el backend. La
    // lista llega en orden `latest()`, y agrupando así "el primero que escribió
    // el nombre" sería el último que pidió: la caja mostraba "luis" y la carta
    // "Luis", y los comensales en orden distinto, siendo los mismos datos.
    for (const p of [...cobrables].sort((a, b) => a.id - b.id)) {
      const nombre = (p.diner_name ?? '').trim()
      const clave = nombre.toLowerCase()
      const grupo = grupos.get(clave) ?? { nombre: nombre || null, ids: [], total: 0 }

      grupo.ids.push(p.id)
      grupo.total += Number(p.total)
      grupos.set(clave, grupo)
    }

    return [...grupos.values()].sort((a, b) => Number(a.nombre === null) - Number(b.nombre === null))
  }, [cobrables])

  /**
   * Lo que el cajero puede elegir cobrar.
   *
   * `ids` null significa la mesa entera; una lista, un cobro parcial. Las filas
   * por comensal solo aparecen si hay más de uno: con uno solo, su fila sería
   * idéntica a "toda la mesa" y preguntar dos veces lo mismo entorpece.
   */
  const opciones = useMemo(() => {
    if (!hayVariosEnLaMesa) return []

    return [
      {
        clave: 'mesa',
        etiqueta: `Toda la mesa ${pedido?.table?.number ?? ''}`.trim(),
        total: totalMesa,
        ids: null as number[] | null,
      },
      ...(comensales.length > 1
        ? comensales.map((c) => ({
            clave: `comensal:${(c.nombre ?? '').toLowerCase()}`,
            etiqueta: c.nombre ?? 'Sin nombre',
            total: c.total,
            ids: c.ids as number[] | null,
          }))
        : []),
      {
        clave: 'pedido',
        etiqueta: `Solo el pedido #${pedidoId}`,
        total: Number(pedido?.total ?? 0),
        ids: [] as number[] | null,
      },
    ]
  }, [hayVariosEnLaMesa, comensales, totalMesa, pedido, pedidoId])

  const elegida = opciones.find((o) => o.clave === alcance)
  const porMesa = elegida?.clave === 'mesa'
  const porComensal = Boolean(elegida?.ids?.length)

  // Los pedidos que se van a cobrar ahora, para avisar de lo que estorba.
  const seleccionados = porMesa
    ? cobrables
    : porComensal
      ? cobrables.filter((p) => elegida?.ids?.includes(p.id))
      : []

  // Una propuesta sin confirmar no se puede cobrar: el mesero todavía no la ha
  // mirado. El backend lo rechaza, y avisarlo aquí evita el viaje.
  const sinConfirmar = seleccionados.filter((p) => p.status === 'proposed')

  const total = elegida ? elegida.total : Number(pedido?.total ?? 0)
  const esEfectivo = metodo === 'cash'

  // En efectivo se teclea lo que entrega el cliente; en los demás métodos el
  // importe es exactamente el total y no hay nada que decidir.
  const importe = esEfectivo ? Number(recibido || 0) : total
  const vuelto = Math.max(0, importe - total)
  const alcanza = importe + 0.01 >= total

  const sugerencias = useMemo(() => sugerirImportes(total), [total])

  const cobrar = useMutation({
    mutationFn: async () => {
      // Con alcance de mesa o de comensal va por el endpoint de mesa, que
      // cierra todos los pedidos elegidos en una sola transacción. El cobro
      // suelto sigue por /payments: es el único camino para un domicilio o un
      // pedido de barra, que no tienen mesa.
      if (porMesa || porComensal) {
        const { data } = await api.post(`/payments/table/${mesaId}`, {
          method: metodo,
          amount: importe,
          reference: referencia || null,
          ...(porComensal ? { order_ids: elegida?.ids } : {}),
        })
        return { vuelto: Number((data as { change: number }).change) }
      }

      const { data } = await api.post('/payments', {
        order_id: pedidoId,
        method: metodo,
        amount: importe,
        reference: referencia || null,
      })
      return { vuelto: Number((data as { payment: { change_amount: string } }).payment.change_amount) }
    },
    onSuccess: (data) => {
      clienteQuery.invalidateQueries({ queryKey: ['mesas'] })
      clienteQuery.invalidateQueries({ queryKey: ['pedidos'] })
      clienteQuery.invalidateQueries({ queryKey: ['pedido', pedidoId] })
      clienteQuery.invalidateQueries({ queryKey: ['mesa-pedidos', mesaId] })
      const quedan = cobrables.filter((p) => !seleccionados.includes(p)).length

      setCobrado({
        vuelto: data.vuelto,
        pedido: pedido as Pedido,
        titulo: porComensal ? `${elegida?.etiqueta} ha pagado` : porMesa ? 'Mesa cobrada' : 'Pedido cobrado',
        // En un cobro parcial lo importante no es lo cobrado, es lo que falta:
        // es lo que el cajero necesita para seguir atendiendo esa mesa.
        detalle: porComensal
          ? quedan === 0
            ? `Mesa ${pedido?.table?.number} liberada`
            : `Faltan ${quedan} pedido${quedan === 1 ? '' : 's'} en la mesa ${pedido?.table?.number}`
          : porMesa
            ? `${seleccionados.length} pedidos · mesa ${pedido?.table?.number} liberada`
            : pedido?.table
              ? `Mesa ${pedido.table.number} liberada`
              : TIPOS[(pedido as Pedido).type],
      })
    },
  })

  if (isLoading) {
    return <div className="p-6 text-sm text-piedra-500">Cargando pedido…</div>
  }

  if (!pedido) {
    return (
      <div className="p-6 text-center">
        <p className="mb-3 text-piedra-700">No se encontró el pedido.</p>
        <Link to="/mesas" className="font-semibold text-marca-700 underline underline-offset-4">
          Volver a mesas
        </Link>
      </div>
    )
  }

  // Ya cobrado: se confirma el vuelto antes de soltar la pantalla, para que el
  // cajero tenga la cifra delante mientras cuenta el dinero.
  if (cobrado) {
    return (
      <div className="mx-auto max-w-md p-4 lg:p-6">
        <div className="rounded-2xl border-2 border-listo bg-white p-6 text-center">
          <p className="text-4xl" aria-hidden>
            ✅
          </p>
          <h1 className="mt-2 text-xl font-bold text-piedra-900">{cobrado.titulo}</h1>
          <p className="text-sm text-piedra-500">{cobrado.detalle}</p>

          {cobrado.vuelto > 0 && (
            <div className="mt-5 rounded-xl bg-listo-suave p-5">
              <p className="text-sm font-semibold text-listo">Entregar de vuelto</p>
              <p className="cifras text-4xl font-bold text-listo">
                {dinero(cobrado.vuelto, moneda)}
              </p>
            </div>
          )}
        </div>

        <button
          onClick={() => navegar('/mesas', { replace: true })}
          className="mt-4 min-h-14 w-full rounded-xl bg-marca-600 text-base font-semibold text-white
                     transition hover:bg-marca-700"
        >
          Listo
        </button>
      </div>
    )
  }

  if (pedido.payment) {
    return (
      <Aviso
        titulo="Este pedido ya está cobrado"
        texto={`Pagado con ${METODOS_PAGO[pedido.payment.method]} · ${dinero(pedido.payment.amount, moneda)}`}
      />
    )
  }

  if (pedido.status === 'cancelled') {
    return <Aviso titulo="Pedido cancelado" texto="Un pedido cancelado no se puede cobrar." />
  }

  return (
    <div className="mx-auto max-w-md p-4 lg:p-6">
      <button
        onClick={() => navegar(-1)}
        className="mb-2 min-h-11 text-sm font-medium text-piedra-500 hover:text-piedra-800"
      >
        ← Volver
      </button>

      {/* Qué se está cobrando. Solo aparece cuando de verdad hay más de un
          pedido abierto en la mesa: en el caso normal no hay nada que elegir y
          una pregunta de más entre el cajero y el dinero sobra. */}
      {opciones.length > 0 && (
        <div className="mb-4 overflow-hidden rounded-xl border border-piedra-200 bg-white">
          <p className="border-b border-piedra-200 px-4 py-2 text-xs font-semibold text-piedra-500">
            ¿Qué se cobra?
          </p>
          {opciones.map((o) => (
            <button
              key={o.clave}
              onClick={() => {
                setAlcance(o.clave)
                setRecibido('')
              }}
              aria-pressed={alcance === o.clave}
              className={`flex min-h-14 w-full items-center justify-between gap-3 border-b
                          border-piedra-100 px-4 text-left last:border-b-0 ${
                            alcance === o.clave ? 'bg-marca-50' : ''
                          }`}
            >
              <span className="flex min-w-0 items-center gap-2.5">
                <span
                  aria-hidden
                  className={`h-4 w-4 shrink-0 rounded-full border-2 ${
                    alcance === o.clave ? 'border-marca-600 bg-marca-600' : 'border-piedra-300'
                  }`}
                />
                <span className="truncate font-medium text-piedra-800">{o.etiqueta}</span>
              </span>
              <span className="cifras shrink-0 font-bold text-piedra-900">
                {dinero(o.total, moneda)}
              </span>
            </button>
          ))}
        </div>
      )}

      <header className="mb-4 rounded-2xl border border-piedra-200 bg-white p-5 text-center">
        <p className="text-sm text-piedra-500">
          {porMesa
            ? `Mesa ${pedido.table?.number} · ${cobrables.length} pedidos`
            : porComensal
              ? `${elegida?.etiqueta} · mesa ${pedido.table?.number}`
              : `${pedido.table ? `Mesa ${pedido.table.number}` : TIPOS[pedido.type]} · Pedido #${pedido.id}`}
        </p>
        <p className="cifras mt-1 text-4xl font-bold text-piedra-900">
          {dinero(total, moneda)}
        </p>
        <p className="cifras text-xs text-piedra-500">
          {porMesa
            ? comensales.map((c) => c.nombre ?? 'Sin nombre').join(' · ')
            : porComensal
              ? `${seleccionados.length} pedido${seleccionados.length === 1 ? '' : 's'}`
              : `${pedido.items.length} línea${pedido.items.length === 1 ? '' : 's'}`}
        </p>
      </header>

      {sinConfirmar.length > 0 && (
        <p
          role="alert"
          className="mb-3 rounded-lg bg-pendiente-suave px-3 py-2 text-sm text-pendiente"
        >
          {porComensal ? elegida?.etiqueta : 'La mesa'} tiene {sinConfirmar.length} pedido
          {sinConfirmar.length === 1 ? '' : 's'} sin confirmar (#
          {sinConfirmar.map((p) => p.id).join(', #')}). Confírmalos o descártalos antes de cobrar.
        </p>
      )}

      <section className="mb-4">
        <h2 className="mb-2 text-sm font-semibold text-piedra-700">¿Cómo paga?</h2>
        <div className="grid grid-cols-3 gap-2">
          {(Object.keys(METODOS_PAGO) as MetodoPago[]).map((m) => (
            <button
              key={m}
              onClick={() => {
                setMetodo(m)
                setReferencia('')
              }}
              className={`min-h-12 rounded-xl text-sm font-semibold transition ${
                metodo === m
                  ? 'bg-marca-600 text-white'
                  : 'bg-white text-piedra-700 ring-1 ring-piedra-200 hover:ring-piedra-300'
              }`}
            >
              {METODOS_PAGO[m]}
            </button>
          ))}
        </div>
      </section>

      {esEfectivo && (
        <section className="mb-4 rounded-2xl border border-piedra-200 bg-white p-4">
          <h2 className="mb-2 text-sm font-semibold text-piedra-700">¿Con cuánto paga?</h2>

          <div className="mb-3 flex flex-wrap gap-2">
            <button
              onClick={() => setRecibido(String(total))}
              className="min-h-11 rounded-xl bg-piedra-100 px-3 text-sm font-semibold
                         text-piedra-700 hover:bg-piedra-200"
            >
              Exacto
            </button>
            {sugerencias.map((v) => (
              <button
                key={v}
                onClick={() => setRecibido(String(v))}
                className="cifras min-h-11 rounded-xl bg-piedra-100 px-3 text-sm font-semibold
                           text-piedra-700 hover:bg-piedra-200"
              >
                {dinero(v, moneda)}
              </button>
            ))}
          </div>

          <input
            type="number"
            inputMode="numeric"
            value={recibido}
            onChange={(e) => setRecibido(e.target.value)}
            placeholder="0"
            autoFocus
            className="cifras min-h-14 w-full rounded-xl border border-piedra-300 px-4 text-right
                       text-2xl font-bold text-piedra-900 focus:border-marca-500 focus:outline-none"
          />

          {/* El dato más grande de la pantalla, porque es el que se entrega. */}
          <div
            className={`mt-3 rounded-xl p-4 text-center transition ${
              !recibido
                ? 'bg-piedra-100'
                : alcanza
                  ? 'bg-listo-suave'
                  : 'bg-cancelado-suave'
            }`}
          >
            {!recibido ? (
              <p className="text-sm text-piedra-500">Escribe con cuánto paga para ver el vuelto.</p>
            ) : alcanza ? (
              <>
                <p className="text-sm font-semibold text-listo">Vuelto</p>
                <p className="cifras text-4xl font-bold text-listo">
                  {dinero(vuelto, moneda)}
                </p>
              </>
            ) : (
              <>
                <p className="text-sm font-semibold text-cancelado">Faltan</p>
                <p className="cifras text-3xl font-bold text-cancelado">
                  {dinero(total - importe, moneda)}
                </p>
              </>
            )}
          </div>
        </section>
      )}

      {CON_REFERENCIA.includes(metodo) && (
        <label className="mb-4 flex flex-col gap-1.5">
          <span className="text-sm font-semibold text-piedra-700">
            Referencia <span className="font-normal text-piedra-500">(opcional)</span>
          </span>
          <input
            value={referencia}
            onChange={(e) => setReferencia(e.target.value)}
            placeholder="Nº de aprobación, últimos 4 dígitos…"
            className="min-h-12 rounded-xl border border-piedra-300 px-3.5
                       focus:border-marca-500 focus:outline-none"
          />
        </label>
      )}

      {cobrar.isError && (
        <p
          role="alert"
          className="mb-3 rounded-lg bg-cancelado-suave px-3 py-2 text-sm
                     text-cancelado"
        >
          {interpretarError(cobrar.error).mensaje}
        </p>
      )}

      <button
        onClick={() => cobrar.mutate()}
        disabled={!alcanza || cobrar.isPending || sinConfirmar.length > 0}
        className="min-h-14 w-full rounded-xl bg-marca-600 text-base font-semibold text-white
                   transition hover:bg-marca-700 disabled:opacity-40"
      >
        {cobrar.isPending
          ? 'Cobrando…'
          : `Cobrar ${dinero(total, moneda)}${porComensal ? '' : porMesa ? ' y cerrar la mesa' : ' y cerrar el pedido'}`}
      </button>

      <p className="mt-2 text-center text-xs text-piedra-500">
        Al cobrar se cierra el pedido
        {pedido.table ? ', se libera la mesa' : ''} y se descuenta el inventario.
      </p>
    </div>
  )
}

/**
 * Con cuánto es probable que pague: el redondeo natural hacia arriba y los
 * billetes que de verdad circulan.
 */
function sugerirImportes(total: number): number[] {
  if (total <= 0) return []

  const candidatos = new Set<number>()

  // Redondeo al siguiente múltiplo de mil y de cinco mil.
  candidatos.add(Math.ceil(total / 1000) * 1000)
  candidatos.add(Math.ceil(total / 5000) * 5000)

  for (const billete of BILLETES) {
    if (billete > total) candidatos.add(billete)
  }

  return [...candidatos]
    .filter((v) => v > total)
    .sort((a, b) => a - b)
    .slice(0, 4)
}

function Aviso({ titulo, texto }: { titulo: string; texto: string }) {
  return (
    <div className="mx-auto max-w-md p-6 text-center">
      <h1 className="text-xl font-bold text-piedra-900">{titulo}</h1>
      <p className="mt-1 text-sm text-piedra-500">{texto}</p>
      <Link
        to="/mesas"
        className="mt-5 inline-block min-h-12 rounded-xl bg-marca-600 px-5 py-3 font-semibold
                   text-white"
      >
        Volver a mesas
      </Link>
    </div>
  )
}
