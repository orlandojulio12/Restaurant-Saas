import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { finanzasApi, reportesApi } from '../../api/analitica'
import { gestionAjustes, gestionInventario } from '../../api/gestion'
import { pedidosApi } from '../../api/pedidos'
import { Barras, Cifra, Panel } from '../reportes/Reportes'
import { dinero } from '../../lib/formato'
import { useSesion } from '../auth/SesionContext'

/**
 * Pantalla de inicio del administrador.
 *
 * Un dueño que abre esto a las ocho de la noche, en plena cena, no viene a
 * explorar datos: viene a saber cómo va el turno y si hay algo que atender ya.
 * Por eso lo operativo va arriba —ventas de hoy, pedidos abiertos, mesas
 * ocupadas— y la tendencia debajo, que es la pregunta del día siguiente, no la
 * de ahora.
 *
 * La banda de atención es lo que distingue esta pantalla de un panel de
 * métricas cualquiera: solo existe cuando hay algo que hacer, y cada aviso
 * lleva a la pantalla donde se resuelve. Cuando no hay nada, lo dice en una
 * línea y se quita de en medio.
 */

/** Los mismos umbrales que en sala y cocina, para que todos hablen de lo mismo. */
const MINUTOS_URGENTE = 35

export default function Inicio() {
  const { sesion, tienePlan } = useSesion()
  const moneda = sesion?.restaurante.currency ?? 'COP'
  const nombre = sesion?.usuario.name.split(' ')[0] ?? ''

  const panel = useQuery({
    queryKey: ['finanzas', 'dashboard'],
    queryFn: finanzasApi.dashboard,
    refetchInterval: 60_000,
  })

  const mesas = useQuery({
    queryKey: ['mesas'],
    queryFn: gestionAjustes.mesas,
    refetchInterval: 30_000,
  })

  const propuestas = useQuery({
    queryKey: ['pedidos', 'propuestas'],
    queryFn: () => pedidosApi.listar({ status: 'proposed', per_page: 50 }),
    refetchInterval: 30_000,
  })

  const alertas = useQuery({
    queryKey: ['inventario', 'alertas'],
    queryFn: gestionInventario.alertas,
    enabled: tienePlan('inventory'),
  })

  const diario = useQuery({
    queryKey: ['reportes', 'diario', 'inicio'],
    queryFn: () => reportesApi.diario(ultimosDias(14)),
  })

  const listaMesas = mesas.data ?? []
  const ocupadas = listaMesas.filter((m) => m.status === 'occupied').length

  const demoradas = listaMesas.filter(
    (m) => (m.active_order?.elapsed_min ?? 0) >= MINUTOS_URGENTE,
  ).length

  const porConfirmar = propuestas.data?.data.length ?? 0
  const bajoMinimo = alertas.data?.count ?? 0

  const avisos = [
    porConfirmar > 0 && {
      clave: 'propuestas',
      texto: `${porConfirmar} pedido${porConfirmar === 1 ? '' : 's'} del QR sin confirmar`,
      detalle: 'El cliente ya pidió desde la mesa y espera al mesero.',
      a: '/mesas',
      accion: 'Ver mesas',
      tono: 'border-camino bg-camino-suave text-camino',
    },
    demoradas > 0 && {
      clave: 'demoradas',
      texto: `${demoradas} mesa${demoradas === 1 ? '' : 's'} esperando más de ${MINUTOS_URGENTE} min`,
      detalle: 'Puede que la cocina esté saturada o que algo se haya quedado atrás.',
      a: '/cocina',
      accion: 'Ver cocina',
      tono: 'border-urgente bg-cancelado-suave text-urgente',
    },
    bajoMinimo > 0 && {
      clave: 'stock',
      texto: `${bajoMinimo} ingrediente${bajoMinimo === 1 ? '' : 's'} bajo mínimo`,
      detalle: 'Conviene reponer antes de que falte en plena cena.',
      a: '/inventario',
      accion: 'Ver inventario',
      tono: 'border-atencion bg-pendiente-suave text-atencion',
    },
  ].filter(Boolean) as {
    clave: string
    texto: string
    detalle: string
    a: string
    accion: string
    tono: string
  }[]

  const hoy = panel.data?.today
  const mes = panel.data?.month

  return (
    <div className="p-4 lg:p-6">
      <header className="mb-5">
        <h1 className="text-2xl font-bold text-piedra-900">
          {saludo()}, {nombre}
        </h1>
        <p className="text-sm text-piedra-500">{fechaLarga()}</p>
      </header>

      {/* Atención: lo único de esta pantalla que pide acción. */}
      <section className="mb-6" aria-label="Avisos">
        {avisos.length === 0 ? (
          <p className="flex items-center gap-2 rounded-xl bg-listo-suave px-4 py-3 text-sm
                        font-semibold text-listo">
            <span aria-hidden>✓</span>
            Todo en orden por ahora
          </p>
        ) : (
          <ul className="flex flex-col gap-2">
            {avisos.map((aviso) => (
              <li key={aviso.clave}>
                <Link
                  to={aviso.a}
                  className={`alzado flex flex-wrap items-center justify-between gap-3 rounded-xl
                              border-l-4 bg-white py-3 pr-3 pl-4 ${aviso.tono}`}
                >
                  <span className="min-w-0">
                    <span className="block font-semibold">{aviso.texto}</span>
                    <span className="block text-sm text-piedra-600">{aviso.detalle}</span>
                  </span>
                  <span className="shrink-0 text-sm font-semibold whitespace-nowrap">
                    {aviso.accion} →
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Cifra
          titulo="Ventas de hoy"
          valor={dinero(hoy?.sales ?? 0, moneda)}
          pie={comparativa(hoy?.vs_yesterday.change_percent)}
          cargando={panel.isLoading}
          destacada
        />
        <Cifra
          titulo="Pedidos abiertos"
          valor={String(hoy?.open_orders ?? 0)}
          pie={`${hoy?.orders ?? 0} cerrados hoy`}
          cargando={panel.isLoading}
        />
        <Cifra
          titulo="Mesas ocupadas"
          valor={`${ocupadas} de ${listaMesas.length}`}
          cargando={mesas.isLoading}
        />
        <Cifra
          titulo="Ticket promedio"
          valor={dinero(hoy?.avg_ticket ?? 0, moneda)}
          cargando={panel.isLoading}
        />
      </section>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <Panel titulo="Ventas de los últimos 14 días">
            {diario.isLoading ? (
              <div className="h-40 animate-pulse rounded-xl bg-piedra-200" />
            ) : (
              <Barras dias={diario.data?.data ?? []} moneda={moneda} />
            )}
          </Panel>
        </div>

        <div className="flex flex-col gap-4">
          <Panel titulo="Lo que más se vende este mes">
            {panel.isLoading ? (
              <div className="h-32 animate-pulse rounded-xl bg-piedra-200" />
            ) : (panel.data?.top_products ?? []).length === 0 ? (
              <p className="text-sm text-piedra-500">
                Todavía no hay ventas cerradas este mes.
              </p>
            ) : (
              <ul className="flex flex-col gap-2">
                {panel.data!.top_products.map((p, i) => (
                  <li key={p.product_id ?? p.product_name} className="flex items-center gap-3">
                    <span className="cifras w-5 text-sm font-bold text-piedra-500">{i + 1}</span>
                    <span className="min-w-0 flex-1 truncate text-piedra-800">
                      {p.product_name}
                    </span>
                    <span className="cifras text-sm font-semibold text-piedra-900">
                      {dinero(p.total_revenue, moneda)}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          {tienePlan('financials') && mes && (
            <Panel titulo="El mes">
              <p className="cifras text-2xl font-bold text-piedra-900">
                {dinero(mes.sales, moneda)}
              </p>

              {mes.breakeven > 0 && (
                <>
                  <div className="mt-3 h-2 overflow-hidden rounded-full bg-piedra-200">
                    <div
                      className={`h-full rounded-full ${
                        mes.above_breakeven ? 'bg-listo' : 'bg-marca-500'
                      }`}
                      style={{
                        width: `${Math.min(100, (mes.sales / mes.breakeven) * 100)}%`,
                      }}
                    />
                  </div>

                  <p className="mt-2 text-sm text-piedra-600">
                    {mes.above_breakeven
                      ? `Por encima del punto de equilibrio, que está en ${dinero(mes.breakeven, moneda)}.`
                      : `Faltan ${dinero(Math.abs(mes.gap_to_breakeven), moneda)} para cubrir los costos fijos del mes.`}
                  </p>
                </>
              )}
            </Panel>
          )}
        </div>
      </div>
    </div>
  )
}

function saludo(): string {
  const h = new Date().getHours()

  if (h < 12) return 'Buenos días'
  if (h < 19) return 'Buenas tardes'

  return 'Buenas noches'
}

function fechaLarga(): string {
  return new Intl.DateTimeFormat('es-CO', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  }).format(new Date())
}

function comparativa(cambio: number | null | undefined): string | undefined {
  if (cambio === null || cambio === undefined) return 'Sin ventas ayer para comparar'

  const signo = cambio >= 0 ? '+' : ''

  return `${signo}${cambio}% frente a ayer`
}

/** Rango de los últimos N días, en fechas locales. */
function ultimosDias(n: number): { from: string; to: string } {
  const hoy = new Date()
  const desde = new Date()
  desde.setDate(hoy.getDate() - (n - 1))

  const iso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

  return { from: iso(desde), to: iso(hoy) }
}
