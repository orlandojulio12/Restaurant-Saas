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
  const [alcance, setAlcance] = useState<'pedido' | 'mesa'>('pedido')
  // Guarda también qué se cobró. Al cerrar la mesa desaparecen sus pedidos
  // abiertos, así que `porMesa` vuelve a false justo antes de pintar el
  // resultado y la pantalla final decía "Pedido cobrado" tras cobrar la mesa.
  const [cobrado, setCobrado] = useState<{
    vuelto: number
    pedido: Pedido
    deLaMesa: boolean
    cuantos: number
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
  const porMesa = alcance === 'mesa' && hayVariosEnLaMesa

  // Una propuesta sin confirmar no se puede cobrar: el mesero todavía no la ha
  // mirado. El backend lo rechaza, y avisarlo aquí evita el viaje.
  const sinConfirmar = cobrables.filter((p) => p.status === 'proposed')

  const totalMesa = cobrables.reduce((s, p) => s + Number(p.total), 0)
  const total = porMesa ? totalMesa : Number(pedido?.total ?? 0)
  const esEfectivo = metodo === 'cash'

  // En efectivo se teclea lo que entrega el cliente; en los demás métodos el
  // importe es exactamente el total y no hay nada que decidir.
  const importe = esEfectivo ? Number(recibido || 0) : total
  const vuelto = Math.max(0, importe - total)
  const alcanza = importe + 0.01 >= total

  const sugerencias = useMemo(() => sugerirImportes(total), [total])

  const cobrar = useMutation({
    mutationFn: async () => {
      if (porMesa) {
        const { data } = await api.post(`/payments/table/${mesaId}`, {
          method: metodo,
          amount: importe,
          reference: referencia || null,
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
      setCobrado({
        vuelto: data.vuelto,
        pedido: pedido as Pedido,
        deLaMesa: porMesa,
        cuantos: cobrables.length,
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
          <h1 className="mt-2 text-xl font-bold text-piedra-900">
            {cobrado.deLaMesa ? 'Mesa cobrada' : 'Pedido cobrado'}
          </h1>
          <p className="text-sm text-piedra-500">
            {cobrado.deLaMesa
              ? `${cobrado.cuantos} pedidos · mesa ${pedido.table?.number} liberada`
              : pedido.table
                ? `Mesa ${pedido.table.number} liberada`
                : TIPOS[pedido.type]}
          </p>

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
      {hayVariosEnLaMesa && (
        <div className="mb-3 grid grid-cols-2 gap-2">
          {(
            [
              ['pedido', `Solo el #${pedido.id}`, Number(pedido.total)],
              ['mesa', `Toda la mesa ${pedido.table?.number ?? ''}`, totalMesa],
            ] as ['pedido' | 'mesa', string, number][]
          ).map(([valor, etiqueta, importeAlcance]) => (
            <button
              key={valor}
              onClick={() => {
                setAlcance(valor)
                setRecibido('')
              }}
              aria-pressed={alcance === valor}
              className={`min-h-16 rounded-xl border-2 px-3 py-2 text-left ${
                alcance === valor
                  ? 'border-marca-600 bg-marca-50'
                  : 'border-piedra-200 bg-white'
              }`}
            >
              <span className="block text-xs font-semibold text-piedra-600">{etiqueta}</span>
              <span className="cifras block font-bold text-piedra-900">
                {dinero(importeAlcance, moneda)}
              </span>
            </button>
          ))}
        </div>
      )}

      <header className="mb-4 rounded-2xl border border-piedra-200 bg-white p-5 text-center">
        <p className="text-sm text-piedra-500">
          {porMesa
            ? `Mesa ${pedido.table?.number} · ${cobrables.length} pedidos`
            : `${pedido.table ? `Mesa ${pedido.table.number}` : TIPOS[pedido.type]} · Pedido #${pedido.id}`}
        </p>
        <p className="cifras mt-1 text-4xl font-bold text-piedra-900">
          {dinero(total, moneda)}
        </p>
        <p className="cifras text-xs text-piedra-500">
          {porMesa
            ? cobrables.map((p) => p.diner_name).filter(Boolean).join(' · ') || 'Cuenta de la mesa'
            : `${pedido.items.length} línea${pedido.items.length === 1 ? '' : 's'}`}
        </p>
      </header>

      {porMesa && sinConfirmar.length > 0 && (
        <p
          role="alert"
          className="mb-3 rounded-lg bg-pendiente-suave px-3 py-2 text-sm text-pendiente"
        >
          La mesa tiene {sinConfirmar.length} pedido{sinConfirmar.length === 1 ? '' : 's'} sin
          confirmar (#{sinConfirmar.map((p) => p.id).join(', #')}). Confírmalos o descártalos antes
          de cobrar.
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
        disabled={!alcanza || cobrar.isPending || (porMesa && sinConfirmar.length > 0)}
        className="min-h-14 w-full rounded-xl bg-marca-600 text-base font-semibold text-white
                   transition hover:bg-marca-700 disabled:opacity-40"
      >
        {cobrar.isPending
          ? 'Cobrando…'
          : `Cobrar ${dinero(total, moneda)} y cerrar ${porMesa ? 'la mesa' : 'el pedido'}`}
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
