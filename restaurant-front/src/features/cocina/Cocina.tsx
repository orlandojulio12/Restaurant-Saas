import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { pedidosApi } from '../../api/pedidos'
import type { EstadoPedido, Pedido } from '../../api/tipos'
import { minutosDesde, TIPOS, transcurrido } from '../../lib/formato'
import { useSesion } from '../auth/SesionContext'
import { useAvisoSonoro, useCanalCocina } from './useCanalCocina'

/**
 * Tablero de cocina.
 *
 * Es una pantalla colgada que se mira a dos metros, con las manos ocupadas.
 * Todo aquí está pensado para eso: sin barra de navegación que robe sitio,
 * tipografía grande, fondo oscuro para que no deslumbre en una cocina, tres
 * columnas fijas que no obligan a decidir dónde mirar, y un solo botón enorme
 * por tarjeta. La antigüedad tiñe la tarjeta entera antes de que nadie lea.
 */

const COLUMNAS: { estado: EstadoPedido; titulo: string; siguiente: EstadoPedido; accion: string }[] = [
  { estado: 'pending', titulo: 'Por preparar', siguiente: 'preparing', accion: 'Empezar' },
  { estado: 'preparing', titulo: 'En preparación', siguiente: 'ready', accion: 'Listo' },
  { estado: 'ready', titulo: 'Listos para servir', siguiente: 'delivered', accion: 'Entregado' },
]

export default function Cocina() {
  const { sesion } = useSesion()
  const navegar = useNavigate()
  const clienteQuery = useQueryClient()
  const sonar = useAvisoSonoro()
  const [silencio, setSilencio] = useState(false)

  const conexion = useCanalCocina(sesion?.restaurante.id, () => {
    if (!silencio) sonar()
  })

  const { data, isLoading } = useQuery({
    queryKey: ['pedidos', 'cocina'],
    queryFn: () => pedidosApi.listar({ per_page: 100 }),
    // Red de seguridad: si el worker de la cola está caído no llegan eventos,
    // y sin esto la cocina se quedaría mirando una pantalla congelada.
    refetchInterval: 15_000,
  })

  const avanzar = useMutation({
    mutationFn: ({ id, estado }: { id: number; estado: EstadoPedido }) =>
      pedidosApi.cambiarEstado(id, estado),
    onSuccess: () => clienteQuery.invalidateQueries({ queryKey: ['pedidos', 'cocina'] }),
  })

  // Un tic por minuto para que los contadores avancen sin recargar nada.
  const [, setTic] = useState(0)
  useEffect(() => {
    const id = setInterval(() => setTic((t) => t + 1), 30_000)
    return () => clearInterval(id)
  }, [])

  const pedidos = data?.data ?? []

  return (
    <div className="min-h-dvh bg-piedra-950 text-white">
      <header className="flex items-center justify-between gap-4 border-b border-white/10 px-5 py-3">
        <div className="flex items-center gap-3">
          <h1 className="text-xl font-bold">Cocina</h1>
          <Conexion estado={conexion} />
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={() => setSilencio((s) => !s)}
            className="rounded-lg px-3 py-2 text-sm font-medium text-white/60 hover:bg-white/10"
            aria-pressed={silencio}
          >
            {silencio ? '🔇 Silencio' : '🔔 Aviso'}
          </button>
          <button
            onClick={() => navegar('/mesas')}
            className="rounded-lg px-3 py-2 text-sm font-medium text-white/60 hover:bg-white/10"
          >
            Salir
          </button>
        </div>
      </header>

      {isLoading ? (
        <p className="p-8 text-white/40">Cargando pedidos…</p>
      ) : (
        <div className="grid grid-cols-1 gap-px bg-white/10 md:grid-cols-3">
          {COLUMNAS.map((columna) => {
            const suyos = pedidos
              .filter((p) => p.status === columna.estado)
              .sort(
                (a, b) =>
                  new Date(a.created_at).getTime() - new Date(b.created_at).getTime(),
              )

            return (
              <section
                key={columna.estado}
                className="min-h-[calc(100dvh-57px)] bg-piedra-950 p-3"
              >
                <h2 className="mb-3 flex items-center justify-between px-1 text-sm font-bold
                               tracking-wide text-white/50 uppercase">
                  {columna.titulo}
                  <span className="cifras rounded-full bg-white/10 px-2 py-0.5 text-white/70">
                    {suyos.length}
                  </span>
                </h2>

                <ul className="flex flex-col gap-3">
                  {suyos.map((pedido) => (
                    <li key={pedido.id}>
                      <Comanda
                        pedido={pedido}
                        accion={columna.accion}
                        onAvanzar={() =>
                          avanzar.mutate({ id: pedido.id, estado: columna.siguiente })
                        }
                        ocupado={avanzar.isPending}
                      />
                    </li>
                  ))}
                </ul>

                {suyos.length === 0 && (
                  <p className="mt-6 text-center text-sm text-white/25">Nada por aquí.</p>
                )}
              </section>
            )
          })}
        </div>
      )}
    </div>
  )
}

function Comanda({
  pedido,
  accion,
  onAvanzar,
  ocupado,
}: {
  pedido: Pedido
  accion: string
  onAvanzar: () => void
  ocupado: boolean
}) {
  const espera = minutosDesde(pedido.created_at) ?? 0

  // Los mismos umbrales que en sala, para que ambos equipos hablen de lo mismo.
  const urgente = espera >= 35
  const lenta = espera >= 20 && !urgente

  const marco = urgente
    ? 'border-[var(--color-urgente)] bg-[var(--color-urgente)]/12'
    : lenta
      ? 'border-[var(--color-atencion)] bg-[var(--color-atencion)]/10'
      : 'border-white/15 bg-white/[0.06]'

  return (
    <article className={`rounded-2xl border-2 p-4 ${marco}`}>
      <div className="mb-3 flex items-baseline justify-between gap-2">
        <span className="text-2xl font-bold">
          {pedido.table ? `Mesa ${pedido.table.number}` : TIPOS[pedido.type]}
        </span>
        <span
          className={`cifras text-lg font-bold ${
            urgente
              ? 'late-urgente text-[var(--color-urgente)]'
              : lenta
                ? 'text-[var(--color-atencion)]'
                : 'text-white/50'
          }`}
        >
          {transcurrido(espera)}
        </span>
      </div>

      <ul className="mb-4 flex flex-col gap-2">
        {pedido.items.map((linea) => (
          <li key={linea.id}>
            <p className="text-lg leading-snug font-semibold">
              <span className="cifras mr-2 rounded-md bg-white/15 px-1.5 py-0.5 text-base">
                {linea.quantity}
              </span>
              {linea.product_name}
            </p>

            {(linea.additionals ?? []).length > 0 && (
              <p className="ml-1 text-sm text-white/60">
                {linea.additionals!.map((a) => a.additional_name).join(' · ')}
              </p>
            )}

            {/* La nota es lo que más se pasa por alto y lo que más devoluciones
                causa, así que va resaltada y no como texto secundario. */}
            {linea.notes && (
              <p className="mt-1 ml-1 rounded-md bg-[var(--color-pendiente)]/25 px-2 py-1
                            text-sm font-semibold text-[var(--color-pendiente-suave)]">
                ⚠ {linea.notes}
              </p>
            )}
          </li>
        ))}
      </ul>

      {pedido.notes && (
        <p className="mb-3 rounded-md bg-white/10 px-2 py-1.5 text-sm text-white/80">
          {pedido.notes}
        </p>
      )}

      <button
        onClick={onAvanzar}
        disabled={ocupado}
        className="min-h-14 w-full rounded-xl bg-white text-lg font-bold text-piedra-950
                   transition hover:bg-white/90 active:scale-[0.98] disabled:opacity-50"
      >
        {accion}
      </button>
    </article>
  )
}

function Conexion({ estado }: { estado: 'conectando' | 'conectado' | 'caido' }) {
  const pinta = {
    conectando: { color: 'bg-[var(--color-atencion)]', texto: 'Conectando' },
    conectado: { color: 'bg-[var(--color-listo)]', texto: 'En vivo' },
    caido: { color: 'bg-[var(--color-urgente)]', texto: 'Sin conexión' },
  }[estado]

  return (
    <span
      className="flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-1 text-xs
                 font-semibold text-white/70"
      title={
        estado === 'caido'
          ? 'No llegan avisos en vivo. La lista se refresca cada 15 segundos.'
          : undefined
      }
    >
      <span
        aria-hidden
        className={`h-2 w-2 rounded-full ${pinta.color} ${
          estado === 'conectando' ? 'late-urgente' : ''
        }`}
      />
      {pinta.texto}
    </span>
  )
}
