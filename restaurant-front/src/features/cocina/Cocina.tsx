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

const COLUMNAS: {
  estado: EstadoPedido
  titulo: string
  /** En las pestañas de móvil no cabe el título largo. */
  corto: string
  siguiente: EstadoPedido
  accion: string
}[] = [
  { estado: 'pending', titulo: 'Por preparar', corto: 'Por preparar', siguiente: 'preparing', accion: 'Empezar' },
  { estado: 'preparing', titulo: 'En preparación', corto: 'Preparando', siguiente: 'ready', accion: 'Listo' },
  { estado: 'ready', titulo: 'Listos para servir', corto: 'Listos', siguiente: 'delivered', accion: 'Entregado' },
]

export default function Cocina() {
  const { sesion } = useSesion()
  const navegar = useNavigate()
  const clienteQuery = useQueryClient()
  const sonar = useAvisoSonoro()
  const [silencio, setSilencio] = useState(false)
  const [columnaMovil, setColumnaMovil] = useState<EstadoPedido>('pending')

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

  const porColumna = (estado: EstadoPedido) =>
    pedidos
      .filter((p) => p.status === estado)
      .sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime())

  return (
    /*
      Altura real con flex en vez de calcular 100dvh menos una cabecera que se
      daba por fija: en pantallas estrechas la cabecera envuelve y crecía, así
      que las columnas se pasaban de largo.
    */
    <div className="flex h-dvh flex-col overflow-hidden bg-piedra-950 text-white">
      <header
        className="flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-2
                   border-b border-white/10 px-4 py-3 sm:px-5"
      >
        <div className="flex items-center gap-3">
          <h1 className="text-xl font-bold">Cocina</h1>
          <Conexion estado={conexion} />
        </div>

        <div className="flex items-center gap-1">
          <button
            onClick={() => setSilencio((s) => !s)}
            className="min-h-11 rounded-lg px-3 text-sm font-medium text-white/60 hover:bg-white/10"
            aria-pressed={silencio}
          >
            {silencio ? '🔇 Silencio' : '🔔 Aviso'}
          </button>
          <button
            onClick={() => navegar('/mesas')}
            className="min-h-11 rounded-lg px-3 text-sm font-medium text-white/60 hover:bg-white/10"
          >
            Salir
          </button>
        </div>
      </header>

      {/* En pantalla estrecha las tres columnas apiladas obligaban a recorrer
          tres pantallas completas para ver el tablero. Se muestra una a la vez
          y se cambia con estas pestañas, que además llevan el conteo. */}
      <div className="flex shrink-0 gap-px border-b border-white/10 bg-white/10 md:hidden">
        {COLUMNAS.map((columna) => {
          const cuantos = porColumna(columna.estado).length

          return (
            <button
              key={columna.estado}
              onClick={() => setColumnaMovil(columna.estado)}
              className={`flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5
                          px-1 text-xs font-bold ${
                            columnaMovil === columna.estado
                              ? 'bg-piedra-900 text-white'
                              : 'bg-piedra-950 text-white/55'
                          }`}
            >
              <span className="cifras text-lg leading-none">{cuantos}</span>
              {columna.corto}
            </button>
          )
        })}
      </div>

      {isLoading ? (
        <p className="p-8 text-white/55">Cargando pedidos…</p>
      ) : (
        <div className="flex min-h-0 flex-1 gap-px bg-white/10">
          {COLUMNAS.map((columna) => {
            const suyos = porColumna(columna.estado)

            return (
              <section
                key={columna.estado}
                /* Cada columna con su propio scroll: en cocina se llena una
                   sola y no debe arrastrar a las demás. */
                className={`min-h-0 flex-1 overflow-y-auto bg-piedra-950 p-3 ${
                  columnaMovil === columna.estado ? 'block' : 'hidden md:block'
                }`}
              >
                <h2 className="mb-3 hidden items-center justify-between px-1 text-sm font-bold
                               tracking-wide text-white/50 uppercase md:flex">
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
                  <p className="mt-6 text-center text-sm text-white/50">Nada por aquí.</p>
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
    ? 'border-urgente bg-urgente/12'
    : lenta
      ? 'border-atencion bg-atencion/10'
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
              ? 'late-urgente text-urgente'
              : lenta
                ? 'text-atencion'
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
              <p className="mt-1 ml-1 rounded-md bg-pendiente/25 px-2 py-1
                            text-sm font-semibold text-pendiente-suave">
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
    conectando: { color: 'bg-atencion', texto: 'Conectando' },
    conectado: { color: 'bg-listo', texto: 'En vivo' },
    caido: { color: 'bg-urgente', texto: 'Sin conexión' },
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
