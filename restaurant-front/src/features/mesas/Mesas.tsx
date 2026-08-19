import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import type { Mesa } from '../../api/tipos'
import { dinero, ESTADOS, transcurrido } from '../../lib/formato'
import { useSesion } from '../auth/SesionContext'

/**
 * Plano de mesas.
 *
 * Es la primera pantalla del mesero, y la pregunta que trae al abrirla es
 * siempre la misma: ¿qué mesa necesita algo *ahora*? Por eso las tarjetas
 * gritan estado y tiempo de espera antes que número, y las que llevan
 * demasiado rato se marcan solas.
 */
export default function Mesas() {
  const { sesion } = useSesion()
  const moneda = sesion?.restaurante.currency ?? 'COP'

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['mesas'],
    queryFn: async () => (await api.get<Mesa[]>('/tables')).data,
    // Se refresca sola: la mesa la puede ocupar otro mesero desde su móvil.
    refetchInterval: 20_000,
  })

  if (isLoading) return <Cargando />
  if (isError) return <Fallo onReintentar={refetch} />

  const mesas = data ?? []
  const ocupadas = mesas.filter((m) => m.status === 'occupied').length
  const porZona = agruparPorZona(mesas)

  return (
    <div className="p-4 lg:p-6">
      <header className="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-piedra-900">Mesas</h1>
          <p className="text-sm text-piedra-500">
            {ocupadas} de {mesas.length} ocupadas
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

      {mesas.length === 0 && (
        <Vacio
          titulo="Todavía no hay mesas"
          texto="Crea las mesas de tu local para empezar a tomar pedidos."
          accion={{ a: '/ajustes/mesas', texto: 'Crear mesas' }}
        />
      )}

      {porZona.map(([zona, deLaZona]) => (
        <section key={zona} className="mb-7">
          {porZona.length > 1 && (
            <h2 className="mb-2.5 text-sm font-semibold tracking-wide text-piedra-500 uppercase">
              {zona}
            </h2>
          )}

          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            {deLaZona.map((mesa) => (
              <li key={mesa.id}>
                <TarjetaMesa mesa={mesa} moneda={moneda} />
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  )
}

function TarjetaMesa({ mesa, moneda }: { mesa: Mesa; moneda: string }) {
  const pedido = mesa.active_order
  const espera = pedido?.elapsed_min ?? null

  // Umbrales de atención: a los 20 minutos algo va lento, a los 35 hay que ir.
  const urgente = espera !== null && espera >= 35
  const lenta = espera !== null && espera >= 20 && !urgente

  // Una propuesta sin confirmar manda sobre el reloj: es lo único de esta
  // pantalla que espera a que el mesero haga algo.
  const porConfirmar = pedido?.status === 'proposed'

  const borde = porConfirmar
    ? 'border-camino ring-2 ring-camino/25'
    : urgente
      ? 'border-urgente ring-2 ring-urgente/20'
      : lenta
        ? 'border-atencion'
        : mesa.status === 'occupied'
          ? 'border-marca-300'
          : 'border-piedra-200'

  return (
    <Link
      to={pedido ? `/pedidos/${pedido.id}` : `/pedidos/nuevo?mesa=${mesa.id}`}
      className={`alzado flex min-h-32 flex-col justify-between rounded-2xl border-2
                  bg-white p-3.5 ${borde}`}
    >
      <div className="flex items-start justify-between gap-2">
        <span className="text-xl font-bold text-piedra-900">{mesa.number}</span>
        <EstadoMesa mesa={mesa} />
      </div>

      {pedido ? (
        <div className="mt-2">
          <p className="cifras text-sm font-semibold text-piedra-800">
            {dinero(pedido.total, moneda)}
          </p>
          {porConfirmar && (
            <p className="mt-0.5 text-xs font-semibold text-camino">
              El cliente ya pidió · confirma
            </p>
          )}
          <p className="mt-0.5 flex items-center gap-1.5 text-xs text-piedra-500">
            <span className="cifras">{pedido.items_count} art.</span>
            <span aria-hidden>·</span>
            <span
              className={`cifras font-semibold ${
                urgente
                  ? 'text-urgente'
                  : lenta
                    ? 'text-atencion'
                    : ''
              }`}
            >
              {transcurrido(espera)}
            </span>
          </p>
        </div>
      ) : (
        <p className="mt-2 text-xs text-piedra-500">
          {mesa.capacity} puestos · toca para pedir
        </p>
      )}
    </Link>
  )
}

function EstadoMesa({ mesa }: { mesa: Mesa }) {
  // El estado del pedido manda sobre el de la mesa: al mesero le dice más
  // "listo para llevar" que "ocupada".
  if (mesa.active_order) {
    const e = ESTADOS[mesa.active_order.status]

    return (
      <span
        className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]
                    font-semibold ${e.clase}`}
      >
        <span aria-hidden className={`h-1.5 w-1.5 rounded-full ${e.punto}`} />
        {e.etiqueta}
      </span>
    )
  }

  const libre = {
    available: { texto: 'Libre', clase: 'bg-piedra-100 text-piedra-600' },
    occupied: { texto: 'Ocupada', clase: 'bg-marca-100 text-marca-800' },
    reserved: { texto: 'Reservada', clase: 'bg-camino-suave text-camino' },
    disabled: { texto: 'Fuera de uso', clase: 'bg-piedra-200 text-piedra-500' },
  }[mesa.status]

  return (
    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${libre.clase}`}>
      {libre.texto}
    </span>
  )
}

function agruparPorZona(mesas: Mesa[]): [string, Mesa[]][] {
  const mapa = new Map<string, Mesa[]>()

  for (const mesa of mesas) {
    const zona = mesa.zone?.name ?? 'Sin zona'
    mapa.set(zona, [...(mapa.get(zona) ?? []), mesa])
  }

  return [...mapa.entries()]
}

function Cargando() {
  return (
    <div className="p-4 lg:p-6">
      <div className="mb-5 h-8 w-32 animate-pulse rounded-lg bg-piedra-200" />
      <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        {Array.from({ length: 8 }).map((_, i) => (
          <li key={i} className="h-32 animate-pulse rounded-2xl bg-piedra-200" />
        ))}
      </ul>
    </div>
  )
}

function Fallo({ onReintentar }: { onReintentar: () => void }) {
  return (
    <div className="p-6 text-center">
      <p className="mb-3 text-piedra-700">No se pudieron cargar las mesas.</p>
      <button
        onClick={onReintentar}
        className="min-h-11 rounded-xl bg-piedra-900 px-4 font-semibold text-white"
      >
        Reintentar
      </button>
    </div>
  )
}

function Vacio({
  titulo,
  texto,
  accion,
}: {
  titulo: string
  texto: string
  accion: { a: string; texto: string }
}) {
  return (
    <div className="rounded-2xl border border-dashed border-piedra-300 p-10 text-center">
      <p className="font-semibold text-piedra-800">{titulo}</p>
      <p className="mx-auto mt-1 max-w-sm text-sm text-piedra-500">{texto}</p>
      <Link
        to={accion.a}
        className="mt-4 inline-block min-h-11 rounded-xl bg-marca-600 px-4 py-2.5
                   font-semibold text-white"
      >
        {accion.texto}
      </Link>
    </div>
  )
}
