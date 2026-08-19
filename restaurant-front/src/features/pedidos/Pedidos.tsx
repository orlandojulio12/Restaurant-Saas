import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { pedidosApi } from '../../api/pedidos'
import type { EstadoPedido, Pedido } from '../../api/tipos'
import {
  diaDe,
  dinero,
  ESTADOS,
  etiquetaDia,
  hora,
  minutosDesde,
  TIPOS,
  transcurrido,
} from '../../lib/formato'
import { useSesion } from '../auth/SesionContext'

/**
 * Lista de pedidos.
 *
 * A diferencia del plano de mesas —que responde "¿qué mesa atiendo?"— esta
 * pantalla responde "¿qué pasó con el pedido tal?". Por eso arranca filtrada
 * por los que siguen abiertos, que es lo que casi siempre se busca, y solo
 * entonces deja mirar hacia atrás.
 */

type Filtro = 'abiertos' | EstadoPedido | 'todos'

const FILTROS: { valor: Filtro; texto: string }[] = [
  { valor: 'abiertos', texto: 'Abiertos' },
  { valor: 'pending', texto: 'Pendientes' },
  { valor: 'preparing', texto: 'En preparación' },
  { valor: 'ready', texto: 'Listos' },
  { valor: 'delivered', texto: 'Entregados' },
  { valor: 'closed', texto: 'Cerrados' },
  { valor: 'todos', texto: 'Todos' },
]

/** Los que aún ocupan a alguien. */
const ABIERTOS: EstadoPedido[] = ['pending', 'preparing', 'ready', 'on_the_way', 'delivered']

export default function Pedidos() {
  const { sesion } = useSesion()
  const moneda = sesion?.restaurante.currency ?? 'COP'
  const [filtro, setFiltro] = useState<Filtro>('abiertos')
  const [pagina, setPagina] = useState(1)

  // "Abiertos" no existe como estado en el backend: agrupa varios. Se filtra
  // en el cliente, así que hay que traerlos todos de una vez — paginar y
  // filtrar después escondería los pedidos abiertos de la segunda página.
  // Son pocos por naturaleza: los que están abiertos ahora mismo.
  const enCliente = filtro === 'abiertos'
  const estadoServidor = enCliente || filtro === 'todos' ? undefined : filtro

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['pedidos', 'lista', estadoServidor, enCliente ? 'todo' : pagina],
    queryFn: () =>
      pedidosApi.listar({
        status: estadoServidor,
        page: enCliente ? 1 : pagina,
        per_page: enCliente ? 200 : 25,
      }),
    refetchInterval: 30_000,
  })

  const recibidos = data?.data ?? []
  const pedidos = enCliente ? recibidos.filter((p) => ABIERTOS.includes(p.status)) : recibidos

  // Sin paginación cuando el filtro es del cliente: los contadores del servidor
  // hablan de otro conjunto y solo confundirían.
  const meta = enCliente ? undefined : data?.meta

  return (
    <div className="p-4 lg:p-6">
      <header className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-piedra-900">Pedidos</h1>
          <p className="cifras text-sm text-piedra-500">
            {isLoading
              ? 'Cargando…'
              : enCliente
                ? `${pedidos.length} abierto${pedidos.length === 1 ? '' : 's'}`
                : `${pedidos.length} en pantalla${meta && meta.total > 0 ? ` · ${meta.total} en total` : ''}`}
          </p>
        </div>

        <Link
          to="/pedidos/nuevo"
          className="min-h-11 rounded-xl bg-marca-600 px-4 py-2.5 text-sm font-semibold
                     text-white transition hover:bg-marca-700"
        >
          + Nuevo pedido
        </Link>
      </header>

      <div className="-mx-4 mb-4 flex gap-2 overflow-x-auto px-4 pb-1 lg:mx-0 lg:px-0">
        {FILTROS.map((f) => (
          <button
            key={f.valor}
            onClick={() => {
              setFiltro(f.valor)
              setPagina(1)
            }}
            className={`min-h-10 shrink-0 rounded-full px-4 text-sm font-semibold transition ${
              filtro === f.valor
                ? 'bg-piedra-900 text-white'
                : 'bg-white text-piedra-600 ring-1 ring-piedra-200 hover:ring-piedra-300'
            }`}
          >
            {f.texto}
          </button>
        ))}
      </div>

      {isError && (
        <div className="rounded-2xl border border-piedra-200 bg-white p-8 text-center">
          <p className="mb-3 text-piedra-700">No se pudieron cargar los pedidos.</p>
          <button
            onClick={() => refetch()}
            className="min-h-11 rounded-xl bg-piedra-900 px-4 font-semibold text-white"
          >
            Reintentar
          </button>
        </div>
      )}

      {isLoading && (
        <ul className="flex flex-col gap-2">
          {Array.from({ length: 5 }).map((_, i) => (
            <li key={i} className="h-24 animate-pulse rounded-2xl bg-piedra-200" />
          ))}
        </ul>
      )}

      {!isLoading && !isError && pedidos.length === 0 && (
        <div className="rounded-2xl border border-dashed border-piedra-300 p-10 text-center">
          <p className="font-semibold text-piedra-800">
            {filtro === 'abiertos' ? 'No hay pedidos abiertos' : 'Nada por aquí'}
          </p>
          <p className="mt-1 text-sm text-piedra-500">
            {filtro === 'abiertos'
              ? 'Todo lo que había está cerrado. Buen momento.'
              : 'Prueba con otro filtro.'}
          </p>
        </div>
      )}

      {/* Agrupado por día: un pedido de ayer y otro de hoy no pueden
          distinguirse solo por el tiempo transcurrido. */}
      {agruparPorDia(pedidos).map(([dia, delDia]) => (
        <section key={dia} className="mb-5">
          <h2 className="mb-2 px-1 text-sm font-semibold text-piedra-500">
            {etiquetaDia(delDia[0].created_at)}
            <span className="cifras ml-2 font-normal text-piedra-400">
              {delDia.length} pedido{delDia.length === 1 ? '' : 's'}
            </span>
          </h2>

          <ul className="flex flex-col gap-2">
            {delDia.map((pedido) => (
              <li key={pedido.id}>
                <FilaPedido pedido={pedido} moneda={moneda} />
              </li>
            ))}
          </ul>
        </section>
      ))}

      {meta && meta.last_page > 1 && (
        <nav className="mt-5 flex items-center justify-center gap-3">
          <button
            onClick={() => setPagina((p) => Math.max(1, p - 1))}
            disabled={pagina <= 1}
            className="min-h-11 rounded-xl border border-piedra-300 px-4 text-sm font-semibold
                       text-piedra-700 disabled:opacity-40"
          >
            Anterior
          </button>
          <span className="cifras text-sm text-piedra-500">
            {meta.current_page} de {meta.last_page}
          </span>
          <button
            onClick={() => setPagina((p) => Math.min(meta.last_page, p + 1))}
            disabled={pagina >= meta.last_page}
            className="min-h-11 rounded-xl border border-piedra-300 px-4 text-sm font-semibold
                       text-piedra-700 disabled:opacity-40"
          >
            Siguiente
          </button>
        </nav>
      )}
    </div>
  )
}

/** Agrupa manteniendo el orden que ya trae el servidor (más nuevo primero). */
function agruparPorDia(pedidos: Pedido[]): [string, Pedido[]][] {
  const mapa = new Map<string, Pedido[]>()

  for (const pedido of pedidos) {
    const dia = diaDe(pedido.created_at)
    mapa.set(dia, [...(mapa.get(dia) ?? []), pedido])
  }

  return [...mapa.entries()]
}

function FilaPedido({ pedido, moneda }: { pedido: Pedido; moneda: string }) {
  const estado = ESTADOS[pedido.status]
  const espera = minutosDesde(pedido.created_at)
  const abierto = ABIERTOS.includes(pedido.status)

  // El tiempo solo alarma mientras el pedido siga vivo: en uno cerrado hace
  // semanas, un número en rojo no significa nada.
  const urgente = abierto && (espera ?? 0) >= 35
  const lenta = abierto && (espera ?? 0) >= 20 && !urgente

  return (
    <Link
      to={`/pedidos/${pedido.id}`}
      className={`flex items-center gap-4 rounded-2xl border bg-white p-4 transition
                  hover:shadow-md ${urgente ? 'border-urgente' : 'border-piedra-200'}`}
    >
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-bold text-piedra-900">
            {pedido.table ? `Mesa ${pedido.table.number}` : TIPOS[pedido.type]}
          </span>
          <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]
                        font-semibold ${estado.clase}`}
          >
            <span aria-hidden className={`h-1.5 w-1.5 rounded-full ${estado.punto}`} />
            {estado.etiqueta}
          </span>
        </div>

        <p className="cifras mt-1 truncate text-sm text-piedra-500">
          #{pedido.id} · {pedido.items.length} línea{pedido.items.length === 1 ? '' : 's'}
          {pedido.customer?.name ? ` · ${pedido.customer.name}` : ''}
        </p>
      </div>

      <div className="shrink-0 text-right">
        <p className="cifras font-bold text-piedra-900">{dinero(pedido.total, moneda)}</p>
        {/* En uno abierto importa cuánto lleva esperando; en uno cerrado,
            a qué hora fue. */}
        <p
          className={`cifras text-xs ${
            urgente ? 'font-semibold text-urgente' : lenta ? 'font-semibold text-atencion' : 'text-piedra-400'
          }`}
        >
          {abierto ? transcurrido(espera) : hora(pedido.created_at)}
        </p>
      </div>
    </Link>
  )
}
