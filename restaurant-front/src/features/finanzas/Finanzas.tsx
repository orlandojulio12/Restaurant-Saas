import { useQuery } from '@tanstack/react-query'
import { finanzasApi } from '../../api/analitica'
import { dinero, METODOS_PAGO } from '../../lib/formato'
import type { MetodoPago } from '../../api/tipos'
import { Cifra, Panel } from '../reportes/Reportes'
import { useSesion } from '../auth/SesionContext'

/**
 * Módulo financiero.
 *
 * La pregunta que trae aquí al dueño es una sola: ¿el negocio da o no da? Todo
 * lo demás —ticket promedio, margen, costos fijos— existe para explicar esa
 * respuesta, así que va después y en letra más pequeña.
 */
export default function Finanzas() {
  const { sesion } = useSesion()
  const moneda = sesion?.restaurante.currency ?? 'COP'

  const panel = useQuery({ queryKey: ['finanzas', 'dashboard'], queryFn: finanzasApi.dashboard })
  const equilibrio = useQuery({ queryKey: ['finanzas', 'breakeven'], queryFn: finanzasApi.puntoEquilibrio })
  const proyeccion = useQuery({ queryKey: ['finanzas', 'proyeccion'], queryFn: finanzasApi.proyeccion })
  const costos = useQuery({ queryKey: ['finanzas', 'costos'], queryFn: finanzasApi.costosFijos })

  const hoy = panel.data?.today
  const mes = panel.data?.month
  const be = equilibrio.data

  const sinCostosFijos = !costos.isLoading && (costos.data?.data.length ?? 0) === 0

  return (
    <div className="p-4 lg:p-6">
      <h1 className="mb-4 text-2xl font-bold text-piedra-900">Finanzas</h1>

      {/* La respuesta, antes que cualquier tabla. */}
      <EstadoDelMes
        cargando={panel.isLoading}
        ventas={mes?.sales ?? 0}
        equilibrio={mes?.breakeven ?? 0}
        porEncima={mes?.above_breakeven ?? false}
        faltante={mes?.gap_to_breakeven ?? 0}
        moneda={moneda}
        sinCostosFijos={sinCostosFijos}
      />

      <section className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Cifra
          titulo="Ventas de hoy"
          valor={dinero(hoy?.sales ?? 0, moneda)}
          pie={
            hoy?.vs_yesterday.change_percent !== null && hoy?.vs_yesterday.change_percent !== undefined
              ? `${hoy.vs_yesterday.change_percent > 0 ? '+' : ''}${hoy.vs_yesterday.change_percent}% vs ayer`
              : 'Sin comparación con ayer'
          }
          cargando={panel.isLoading}
          destacada
        />
        <Cifra
          titulo="Pedidos hoy"
          valor={String(hoy?.orders ?? 0)}
          pie={hoy?.open_orders ? `${hoy.open_orders} sin cerrar` : undefined}
          cargando={panel.isLoading}
        />
        <Cifra
          titulo="Ticket promedio"
          valor={dinero(hoy?.avg_ticket ?? 0, moneda)}
          cargando={panel.isLoading}
        />
        <Cifra
          titulo="Ventas del mes"
          valor={dinero(mes?.sales ?? 0, moneda)}
          pie={
            mes?.vs_last_month.change_percent !== null && mes?.vs_last_month.change_percent !== undefined
              ? `${mes.vs_last_month.change_percent > 0 ? '+' : ''}${mes.vs_last_month.change_percent}% vs mes pasado`
              : undefined
          }
          cargando={panel.isLoading}
        />
      </section>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel titulo="Punto de equilibrio">
          {equilibrio.isLoading ? (
            <div className="h-32 animate-pulse rounded-xl bg-piedra-200" />
          ) : (
            <dl className="flex flex-col gap-2.5 text-sm">
              <Dato etiqueta="Costos fijos al mes" valor={dinero(be?.monthly_fixed_costs ?? 0, moneda)} />
              <Dato etiqueta="Margen promedio" valor={`${be?.avg_margin_percent ?? 0}%`} />
              <Dato
                etiqueta="Hay que vender al mes"
                valor={dinero(be?.breakeven_revenue ?? 0, moneda)}
                fuerte
              />
              <Dato
                etiqueta="Clientes al día"
                valor={String(be?.breakeven_customers_per_day ?? 0)}
                ayuda="Con el ticket promedio actual"
              />
            </dl>
          )}

          {(be?.avg_margin_percent ?? 0) === 0 && !equilibrio.isLoading && (
            <p className="mt-3 rounded-lg bg-pendiente-suave px-3 py-2 text-xs text-pendiente">
              Sin margen calculable todavía: hace falta registrar el costo de los productos y
              tener ventas cerradas.
            </p>
          )}
        </Panel>

        <Panel titulo="Cómo va el mes">
          {proyeccion.isLoading ? (
            <div className="h-32 animate-pulse rounded-xl bg-piedra-200" />
          ) : (
            <dl className="flex flex-col gap-2.5 text-sm">
              <Dato
                etiqueta="Vendido este mes"
                valor={dinero(proyeccion.data?.current_month_sales ?? 0, moneda)}
              />
              <Dato
                etiqueta="Proyección a fin de mes"
                valor={dinero(proyeccion.data?.projected_month_sales ?? 0, moneda)}
                fuerte
              />
              {(proyeccion.data?.target_monthly_revenue ?? 0) > 0 && (
                <>
                  <Dato
                    etiqueta="Meta del mes"
                    valor={dinero(proyeccion.data!.target_monthly_revenue, moneda)}
                  />
                  <Dato
                    etiqueta="Hay que vender al día"
                    valor={dinero(proyeccion.data!.daily_needed_to_reach_target, moneda)}
                    ayuda="Para alcanzar la meta"
                  />
                </>
              )}
            </dl>
          )}
        </Panel>
      </div>

      <Panel titulo="Costos fijos">
        {costos.isLoading ? (
          <div className="h-24 animate-pulse rounded-xl bg-piedra-200" />
        ) : sinCostosFijos ? (
          <p className="text-sm text-piedra-500">
            Todavía no hay costos fijos registrados. Sin ellos, el punto de equilibrio sale en cero
            y no dice nada.
          </p>
        ) : (
          <>
            <ul className="flex flex-col gap-1.5">
              {costos.data!.data.map((c) => (
                <li key={c.id} className="flex items-center justify-between gap-3 text-sm">
                  <span className={c.is_active ? 'text-piedra-800' : 'text-piedra-500 line-through'}>
                    {c.name}
                  </span>
                  <span className="cifras shrink-0 text-piedra-600">
                    {dinero(c.amount, moneda)}
                    <span className="ml-1 text-xs text-piedra-500">/{FRECUENCIA[c.frequency] ?? c.frequency}</span>
                  </span>
                </li>
              ))}
            </ul>

            <div className="mt-3 flex items-center justify-between border-t border-piedra-200 pt-3">
              <span className="text-sm font-semibold text-piedra-700">Equivalente mensual</span>
              <span className="cifras font-bold text-piedra-900">
                {dinero(costos.data!.monthly_total, moneda)}
              </span>
            </div>
          </>
        )}
      </Panel>

      {panel.data?.top_payment_method && (
        <p className="text-center text-sm text-piedra-500">
          Método de pago más usado:{' '}
          <span className="font-semibold text-piedra-700">
            {METODOS_PAGO[panel.data.top_payment_method as MetodoPago] ?? panel.data.top_payment_method}
          </span>
        </p>
      )}
    </div>
  )
}

const FRECUENCIA: Record<string, string> = {
  daily: 'día',
  weekly: 'semana',
  biweekly: 'quincena',
  monthly: 'mes',
  quarterly: 'trimestre',
  yearly: 'año',
}

function EstadoDelMes({
  cargando,
  ventas,
  equilibrio,
  porEncima,
  faltante,
  moneda,
  sinCostosFijos,
}: {
  cargando: boolean
  ventas: number
  equilibrio: number
  porEncima: boolean
  faltante: number
  moneda: string
  sinCostosFijos: boolean
}) {
  if (cargando) {
    return <div className="mb-5 h-28 animate-pulse rounded-2xl bg-piedra-200" />
  }

  // El punto de equilibrio puede salir cero por dos motivos distintos, y cada
  // uno se arregla de otra forma: decir siempre "faltan costos fijos" mandaría
  // a la mitad de la gente a corregir lo que no era.
  if (sinCostosFijos) {
    return (
      <AvisoConfiguracion
        titulo="Falta registrar los costos fijos"
        texto="El arriendo, la nómina y los servicios. Sin eso no se puede saber cuánto hay que
               vender para no perder dinero."
      />
    )
  }

  if (equilibrio <= 0) {
    return (
      <AvisoConfiguracion
        titulo="Falta el costo de los productos"
        texto="Los costos fijos ya están, pero sin saber cuánto cuesta preparar cada plato no se
               puede calcular el margen, y sin margen no hay punto de equilibrio."
      />
    )
  }

  const progreso = Math.min(100, Math.round((ventas / equilibrio) * 100))

  return (
    <div
      className={`mb-5 rounded-2xl border-2 bg-white p-5 ${
        porEncima ? 'border-listo' : 'border-atencion'
      }`}
    >
      <p className={`text-sm font-semibold ${porEncima ? 'text-listo' : 'text-atencion'}`}>
        {porEncima ? 'El mes ya cubre los costos' : 'Aún no se cubren los costos del mes'}
      </p>

      <p className="cifras mt-1 text-3xl font-bold text-piedra-900">
        {porEncima
          ? `${dinero(Math.abs(faltante), moneda)} por encima`
          : `Faltan ${dinero(Math.abs(faltante), moneda)}`}
      </p>

      <div className="mt-3 h-2 overflow-hidden rounded-full bg-piedra-100">
        <div
          className={`h-full rounded-full ${porEncima ? 'bg-listo' : 'bg-atencion'}`}
          style={{ width: `${progreso}%` }}
        />
      </div>

      <p className="cifras mt-1.5 text-xs text-piedra-500">
        {dinero(ventas, moneda)} de {dinero(equilibrio, moneda)} · {progreso}%
      </p>
    </div>
  )
}

function AvisoConfiguracion({ titulo, texto }: { titulo: string; texto: string }) {
  return (
    <div className="mb-5 rounded-2xl border border-piedra-200 bg-white p-5">
      <p className="font-semibold text-piedra-800">{titulo}</p>
      <p className="mt-1 text-sm text-piedra-500">{texto}</p>
    </div>
  )
}

function Dato({
  etiqueta,
  valor,
  ayuda,
  fuerte,
}: {
  etiqueta: string
  valor: string
  ayuda?: string
  fuerte?: boolean
}) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <dt className="text-piedra-600">
        {etiqueta}
        {ayuda && <span className="block text-xs text-piedra-500">{ayuda}</span>}
      </dt>
      <dd
        className={`cifras shrink-0 ${
          fuerte ? 'text-lg font-bold text-piedra-900' : 'font-semibold text-piedra-800'
        }`}
      >
        {valor}
      </dd>
    </div>
  )
}
