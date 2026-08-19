import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { interpretarError } from '../../api/client'
import { pedidosApi } from '../../api/pedidos'
import type { EstadoPedido } from '../../api/tipos'
import {
  dinero,
  ESTADOS,
  METODOS_PAGO,
  minutosDesde,
  SIGUIENTES,
  TIPOS,
  transcurrido,
} from '../../lib/formato'
import { useSesion } from '../auth/SesionContext'

/** Qué palabra usar para cada avance, desde el punto de vista de quien pulsa. */
const ACCION: Record<EstadoPedido, string> = {
  pending: 'Empezar a preparar',
  preparing: 'Marcar listo',
  ready: 'Entregar',
  on_the_way: 'Marcar entregado',
  delivered: 'Cerrar',
  closed: '',
  cancelled: 'Cancelar',
}

export default function PedidoDetalle() {
  const { id } = useParams()
  const pedidoId = Number(id)
  const { sesion } = useSesion()
  const moneda = sesion?.restaurante.currency ?? 'COP'
  const clienteQuery = useQueryClient()
  const navegar = useNavigate()

  const { data: pedido, isLoading, isError } = useQuery({
    queryKey: ['pedido', pedidoId],
    queryFn: () => pedidosApi.ver(pedidoId),
    enabled: Number.isFinite(pedidoId),
  })

  const avanzar = useMutation({
    mutationFn: (estado: EstadoPedido) => pedidosApi.cambiarEstado(pedidoId, estado),
    onSuccess: () => {
      clienteQuery.invalidateQueries({ queryKey: ['pedido', pedidoId] })
      clienteQuery.invalidateQueries({ queryKey: ['mesas'] })
      clienteQuery.invalidateQueries({ queryKey: ['pedidos'] })
    },
  })

  if (isLoading) {
    return <div className="p-6 text-sm text-piedra-500">Cargando pedido…</div>
  }

  if (isError || !pedido) {
    return (
      <div className="p-6 text-center">
        <p className="mb-3 text-piedra-700">No se encontró el pedido.</p>
        <Link to="/mesas" className="font-semibold text-marca-700 underline underline-offset-4">
          Volver a mesas
        </Link>
      </div>
    )
  }

  const estado = ESTADOS[pedido.status]
  const espera = minutosDesde(pedido.created_at)
  const siguientes = SIGUIENTES[pedido.status]
  const avance = siguientes.filter((s) => s !== 'cancelled')
  const puedeCancelar = siguientes.includes('cancelled')

  return (
    <div className="mx-auto max-w-2xl p-4 lg:p-6">
      <button
        onClick={() => navegar(-1)}
        className="mb-4 text-sm font-medium text-piedra-500 hover:text-piedra-800"
      >
        ← Volver
      </button>

      <header className="mb-5 rounded-2xl border border-piedra-200 bg-white p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-2xl font-bold text-piedra-900">
              {pedido.table ? `Mesa ${pedido.table.number}` : TIPOS[pedido.type]}
            </h1>
            <p className="cifras mt-0.5 text-sm text-piedra-500">
              Pedido #{pedido.id} · {TIPOS[pedido.type]} · {transcurrido(espera)}
            </p>
          </div>

          <span
            className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm
                        font-semibold ${estado.clase}`}
          >
            <span aria-hidden className={`h-2 w-2 rounded-full ${estado.punto}`} />
            {estado.etiqueta}
          </span>
        </div>

        {pedido.delivery_address && (
          <p className="mt-3 text-sm text-piedra-600">📍 {pedido.delivery_address}</p>
        )}
        {pedido.notes && (
          <p className="mt-2 rounded-lg bg-pendiente-suave px-3 py-2 text-sm
                        text-pendiente">
            {pedido.notes}
          </p>
        )}
      </header>

      <ul className="mb-5 flex flex-col gap-2">
        {pedido.items.map((linea) => (
          <li key={linea.id} className="rounded-xl border border-piedra-200 bg-white p-3.5">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="font-semibold text-piedra-900">
                  <span className="cifras mr-1.5 text-piedra-500">{linea.quantity}×</span>
                  {linea.product_name}
                </p>
                {(linea.additionals ?? []).length > 0 && (
                  <p className="mt-0.5 text-sm text-piedra-500">
                    {linea.additionals!.map((a) => a.additional_name).join(', ')}
                  </p>
                )}
                {linea.notes && (
                  <p className="mt-0.5 text-sm italic text-pendiente">
                    {linea.notes}
                  </p>
                )}
              </div>
              <span className="cifras shrink-0 font-semibold text-piedra-900">
                {dinero(linea.subtotal, moneda)}
              </span>
            </div>
          </li>
        ))}
      </ul>

      <div className="mb-5 flex items-center justify-between rounded-2xl border border-piedra-200
                      bg-white px-5 py-4">
        <span className="font-semibold text-piedra-700">Total</span>
        <span className="cifras text-2xl font-bold text-piedra-900">
          {dinero(pedido.total, moneda)}
        </span>
      </div>

      {avanzar.isError && (
        <p
          role="alert"
          className="mb-3 rounded-lg bg-cancelado-suave px-3 py-2 text-sm
                     text-cancelado"
        >
          {interpretarError(avanzar.error).mensaje}
        </p>
      )}

      {avance.length > 0 && (
        <div className="flex flex-col gap-2">
          {avance.map((siguiente) => (
            <button
              key={siguiente}
              onClick={() => avanzar.mutate(siguiente)}
              disabled={avanzar.isPending}
              className="min-h-14 rounded-xl bg-marca-600 text-base font-semibold text-white
                         transition hover:bg-marca-700 disabled:opacity-40"
            >
              {ACCION[pedido.status] && siguiente === avance[0]
                ? ACCION[pedido.status]
                : `Marcar ${ESTADOS[siguiente].etiqueta.toLowerCase()}`}
            </button>
          ))}
        </div>
      )}

      {/* Cobrar cierra el pedido por su cuenta, así que se ofrece como camino
          principal en cuanto hay algo que cobrar y no se ha cobrado ya. */}
      {!pedido.payment && !['closed', 'cancelled'].includes(pedido.status) && (
        <Link
          to={`/pedidos/${pedido.id}/cobrar`}
          className="mt-3 flex min-h-14 items-center justify-center rounded-xl border-2
                     border-marca-600 text-base font-semibold text-marca-700
                     transition hover:bg-marca-50"
        >
          Cobrar {dinero(pedido.total, moneda)}
        </Link>
      )}

      {pedido.payment && (
        <p className="mt-3 rounded-xl bg-listo-suave px-4 py-3 text-center text-sm
                      font-semibold text-listo">
          Cobrado con {METODOS_PAGO[pedido.payment.method]} · {dinero(pedido.payment.amount, moneda)}
        </p>
      )}

      {puedeCancelar && (
        <button
          onClick={() => {
            if (confirm('¿Cancelar este pedido?')) avanzar.mutate('cancelled')
          }}
          disabled={avanzar.isPending}
          className="mt-3 min-h-12 w-full rounded-xl border border-piedra-300 font-semibold
                     text-cancelado"
        >
          Cancelar pedido
        </button>
      )}
    </div>
  )
}
