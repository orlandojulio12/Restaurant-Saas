import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { rangoPreset, reportesApi, type DiaVentas } from '../../api/analitica'
import { dinero, METODOS_PAGO, TIPOS } from '../../lib/formato'
import type { MetodoPago, TipoPedido } from '../../api/tipos'
import { useSesion } from '../auth/SesionContext'

/**
 * Reportes de venta.
 *
 * Un dueño de restaurante no viene aquí a explorar datos: viene con una
 * pregunta concreta —¿cómo va la semana?, ¿qué se vende?— y quiere la
 * respuesta antes de tener que configurar nada. Por eso arranca en los últimos
 * 7 días y las cifras grandes van arriba, con el detalle debajo.
 */

type Preset = 'hoy' | '7d' | '30d' | 'mes'

const PRESETS: { valor: Preset; texto: string }[] = [
  { valor: 'hoy', texto: 'Hoy' },
  { valor: '7d', texto: '7 días' },
  { valor: '30d', texto: '30 días' },
  { valor: 'mes', texto: 'Este mes' },
]

export default function Reportes() {
  const { sesion } = useSesion()
  const moneda = sesion?.restaurante.currency ?? 'COP'
  const [preset, setPreset] = useState<Preset>('7d')
  const rango = rangoPreset(preset)

  const diario = useQuery({
    queryKey: ['reportes', 'diario', rango.from, rango.to],
    queryFn: () => reportesApi.diario(rango),
  })
  const resumen = useQuery({
    queryKey: ['reportes', 'resumen', rango.from, rango.to],
    queryFn: () => reportesApi.resumen(rango),
  })
  const productos = useQuery({
    queryKey: ['reportes', 'productos', rango.from, rango.to],
    queryFn: () => reportesApi.productos({ ...rango, limit: 8 }),
  })

  const totales = resumen.data?.totals
  const sinVentas = !resumen.isLoading && (totales?.orders ?? 0) === 0

  return (
    <div className="p-4 lg:p-6">
      <header className="mb-4">
        <h1 className="text-2xl font-bold text-piedra-900">Reportes</h1>
        <p className="cifras text-sm text-piedra-500">
          {rango.from} → {rango.to}
        </p>
      </header>

      <div className="mb-5 flex gap-2 overflow-x-auto pb-1">
        {PRESETS.map((p) => (
          <button
            key={p.valor}
            onClick={() => setPreset(p.valor)}
            className={`min-h-11 shrink-0 rounded-full px-4 text-sm font-semibold transition ${
              preset === p.valor
                ? 'bg-piedra-900 text-white'
                : 'bg-white text-piedra-600 ring-1 ring-piedra-200 hover:ring-piedra-300'
            }`}
          >
            {p.texto}
          </button>
        ))}
      </div>

      <section className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Cifra titulo="Ventas" valor={dinero(totales?.sales ?? 0, moneda)} cargando={resumen.isLoading} destacada />
        <Cifra titulo="Pedidos" valor={String(totales?.orders ?? 0)} cargando={resumen.isLoading} />
        <Cifra titulo="Ticket promedio" valor={dinero(totales?.avg_ticket ?? 0, moneda)} cargando={resumen.isLoading} />
        <Cifra
          titulo="Utilidad bruta"
          valor={dinero(totales?.gross_profit ?? 0, moneda)}
          pie={totales?.margin_percent !== undefined ? `${totales.margin_percent}% de margen` : undefined}
          cargando={resumen.isLoading}
        />
      </section>

      {sinVentas ? (
        <div className="rounded-2xl border border-dashed border-piedra-300 p-10 text-center">
          <p className="font-semibold text-piedra-800">Sin ventas en este periodo</p>
          <p className="mt-1 text-sm text-piedra-500">
            Solo cuentan los pedidos cerrados. Los que siguen abiertos aparecerán al cobrarlos.
          </p>
        </div>
      ) : (
        <>
          <Panel titulo="Ventas por día">
            {diario.isLoading ? (
              <Esqueleto alto="h-40" />
            ) : (
              <Barras dias={diario.data?.data ?? []} moneda={moneda} />
            )}
          </Panel>

          <div className="grid gap-4 lg:grid-cols-2">
            <Panel titulo="Lo que más se vende">
              {productos.isLoading ? (
                <Esqueleto alto="h-32" />
              ) : (
                <ul className="flex flex-col gap-2">
                  {(productos.data?.data ?? []).map((p, i) => (
                    <li key={p.product_id ?? p.product_name} className="flex items-center gap-3">
                      <span className="cifras w-5 text-sm font-bold text-piedra-500">{i + 1}</span>
                      <span className="min-w-0 flex-1 truncate text-piedra-800">{p.product_name}</span>
                      <span className="cifras text-sm text-piedra-500">{p.quantity} und.</span>
                      <span className="cifras w-24 text-right font-semibold text-piedra-900">
                        {dinero(p.revenue, moneda)}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>

            <Panel titulo="De dónde vienen las ventas">
              <ul className="mb-4 flex flex-col gap-2">
                {(resumen.data?.by_type ?? []).map((t) => (
                  <FilaProporcion
                    key={t.type}
                    etiqueta={TIPOS[t.type as TipoPedido]}
                    valor={t.sales}
                    total={totales?.sales ?? 0}
                    detalle={`${t.count} pedido${t.count === 1 ? '' : 's'}`}
                    moneda={moneda}
                  />
                ))}
              </ul>

              {(resumen.data?.by_payment_method ?? []).length > 0 && (
                <>
                  <h3 className="mb-2 text-xs font-semibold tracking-wide text-piedra-500 uppercase">
                    Cómo pagaron
                  </h3>
                  <ul className="flex flex-col gap-2">
                    {resumen.data!.by_payment_method.map((m) => (
                      <FilaProporcion
                        key={m.method}
                        etiqueta={METODOS_PAGO[m.method as MetodoPago] ?? m.method}
                        valor={m.amount}
                        total={totales?.sales ?? 0}
                        detalle={`${m.count} cobro${m.count === 1 ? '' : 's'}`}
                        moneda={moneda}
                      />
                    ))}
                  </ul>
                </>
              )}
            </Panel>
          </div>
        </>
      )}
    </div>
  )
}

/**
 * Barras en CSS puro: para "¿qué día vendí más?" basta con comparar alturas,
 * y así no se arrastra una librería de gráficos entera.
 */
function Barras({ dias, moneda }: { dias: DiaVentas[]; moneda: string }) {
  const maximo = Math.max(...dias.map((d) => d.sales), 1)
  const mejor = dias.reduce((a, b) => (b.sales > a.sales ? b : a), dias[0])

  return (
    <div>
      <div className="flex h-40 items-end gap-1" role="img" aria-label="Ventas por día">
        {dias.map((dia) => {
          const alto = (dia.sales / maximo) * 100
          const esMejor = dia.date === mejor?.date && dia.sales > 0

          return (
            <div key={dia.date} className="group relative flex flex-1 flex-col justify-end">
              <div
                className={`rounded-t transition ${esMejor ? 'bg-marca-600' : 'bg-marca-300'}`}
                style={{ height: `${Math.max(alto, dia.sales > 0 ? 3 : 0)}%` }}
              />
              {/* El detalle solo cuando se pregunta por él, para no saturar. */}
              <div
                className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden
                           -translate-x-1/2 rounded-lg bg-piedra-900 px-2 py-1 text-xs
                           whitespace-nowrap text-white group-hover:block"
              >
                <span className="cifras">{dia.date.slice(5)} · {dinero(dia.sales, moneda)}</span>
              </div>
            </div>
          )
        })}
      </div>

      <div className="mt-2 flex justify-between text-xs text-piedra-500">
        <span className="cifras">{dias[0]?.date.slice(5)}</span>
        <span className="cifras">{dias[dias.length - 1]?.date.slice(5)}</span>
      </div>
    </div>
  )
}

function FilaProporcion({
  etiqueta,
  valor,
  total,
  detalle,
  moneda,
}: {
  etiqueta: string
  valor: number
  total: number
  detalle: string
  moneda: string
}) {
  const porcentaje = total > 0 ? Math.round((valor / total) * 100) : 0

  return (
    <li>
      <div className="mb-1 flex items-baseline justify-between gap-2 text-sm">
        <span className="text-piedra-800">{etiqueta}</span>
        <span className="cifras font-semibold text-piedra-900">{dinero(valor, moneda)}</span>
      </div>
      <div className="flex items-center gap-2">
        <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-piedra-100">
          <div className="h-full rounded-full bg-marca-500" style={{ width: `${porcentaje}%` }} />
        </div>
        <span className="cifras w-16 text-right text-xs text-piedra-500">{detalle}</span>
      </div>
    </li>
  )
}

export function Cifra({
  titulo,
  valor,
  pie,
  cargando,
  destacada,
}: {
  titulo: string
  valor: string
  pie?: string
  cargando?: boolean
  destacada?: boolean
}) {
  return (
    <div className="rounded-2xl border border-piedra-200 bg-white p-4">
      <p className="text-xs font-semibold tracking-wide text-piedra-500 uppercase">{titulo}</p>
      {cargando ? (
        <div className="mt-2 h-8 w-24 animate-pulse rounded bg-piedra-200" />
      ) : (
        <p
          className={`cifras mt-1 font-bold text-piedra-900 ${
            destacada ? 'text-3xl' : 'text-2xl'
          }`}
        >
          {valor}
        </p>
      )}
      {pie && !cargando && <p className="mt-0.5 text-xs text-piedra-500">{pie}</p>}
    </div>
  )
}

export function Panel({ titulo, children }: { titulo: string; children: React.ReactNode }) {
  return (
    <section className="mb-4 rounded-2xl border border-piedra-200 bg-white p-5">
      <h2 className="mb-3 font-semibold text-piedra-900">{titulo}</h2>
      {children}
    </section>
  )
}

function Esqueleto({ alto }: { alto: string }) {
  return <div className={`${alto} animate-pulse rounded-xl bg-piedra-200`} />
}
