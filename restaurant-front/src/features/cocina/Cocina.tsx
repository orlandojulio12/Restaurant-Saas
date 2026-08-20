import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { pedidosApi } from '../../api/pedidos'
import type { EstadoPedido, Pedido } from '../../api/tipos'
import { hora, minutosDesde, TIPOS, transcurrido } from '../../lib/formato'
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
  /* Una columna vacía en cocina no es una pantalla que haya que rellenar: es
     una buena noticia, y el texto lo dice en vez de disculparse. */
  vacio: string
}[] = [
  { estado: 'pending', titulo: 'Por preparar', corto: 'Por preparar', siguiente: 'preparing', accion: 'Empezar', vacio: 'Sin comandas nuevas' },
  { estado: 'preparing', titulo: 'En preparación', corto: 'Preparando', siguiente: 'ready', accion: 'Listo', vacio: 'Nada en el fuego' },
  { estado: 'ready', titulo: 'Listos para servir', corto: 'Listos', siguiente: 'delivered', accion: 'Entregado', vacio: 'Todo servido' },
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
                  <p className="mt-8 text-center font-mono text-sm text-white/50">
                    {columna.vacio}
                  </p>
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

  // La espera vive en el lomo del papel, no en el fondo: teñir la comanda
  // entera peleaba con el texto justo cuando más urgente era leerlo.
  const lomo = urgente
    ? 'var(--color-urgente)'
    : lenta
      ? 'var(--color-atencion)'
      : 'var(--color-marca-400)'

  return (
    <article className="comanda pt-3.5" style={{ '--lomo': lomo } as React.CSSProperties}>
      {/* Cabecera como la de un ticket impreso: mesa, hora y número, en
          monoespaciada y separada por un punteado. */}
      <div className="px-4 pb-2">
        <div className="flex items-baseline justify-between gap-2">
          <span className="text-2xl leading-none font-bold text-piedra-900">
            {pedido.table ? `Mesa ${pedido.table.number}` : TIPOS[pedido.type]}
          </span>
          <span
            className={`font-mono text-lg font-bold tabular-nums ${
              urgente
                ? 'late-urgente text-urgente'
                : lenta
                  ? 'text-atencion'
                  : 'text-piedra-600'
            }`}
          >
            {transcurrido(espera)}
          </span>
        </div>

        <p className="mt-0.5 font-mono text-xs tracking-tight text-piedra-600">
          #{pedido.id} · {hora(pedido.created_at)}
        </p>
      </div>

      <div className="mx-4 border-t border-dashed border-piedra-300" />

      <ul className="flex flex-col gap-2.5 px-4 py-3">
        {pedido.items.map((linea) => (
          <li key={linea.id}>
            <p className="flex gap-2.5 text-lg leading-snug font-semibold text-piedra-900">
              {/* La cantidad manda: es lo que se cuenta al emplatar. */}
              <span className="font-mono text-xl font-bold text-marca-700 tabular-nums">
                {linea.quantity}
              </span>
              <span className="min-w-0 flex-1">{linea.product_name}</span>
            </p>

            {(linea.additionals ?? []).length > 0 && (
              <p className="ml-8 text-sm text-piedra-600">
                {linea.additionals!.map((a) => a.additional_name).join(' · ')}
              </p>
            )}

            {/* La nota es lo que más se pasa por alto y lo que más devoluciones
                causa, así que va resaltada y no como texto secundario. */}
            {linea.notes && (
              <p className="mt-1 ml-8 rounded-md bg-pendiente-suave px-2 py-1
                            text-sm font-semibold text-pendiente">
                {linea.notes}
              </p>
            )}
          </li>
        ))}
      </ul>

      {pedido.notes && (
        <p className="mx-4 mb-3 rounded-md bg-piedra-100 px-2.5 py-1.5 text-sm text-piedra-700">
          {pedido.notes}
        </p>
      )}

      {/* Se arranca por abajo, como el talón de un ticket. */}
      <button
        onClick={onAvanzar}
        disabled={ocupado}
        className="min-h-14 w-full rounded-b-[9px] border-t border-dashed border-piedra-300
                   bg-piedra-900 text-lg font-bold text-white transition
                   hover:bg-piedra-800 active:bg-piedra-950 disabled:opacity-50"
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
