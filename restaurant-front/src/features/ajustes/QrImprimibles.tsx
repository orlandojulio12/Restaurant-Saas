import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import QRCode from 'qrcode'
import { gestionAjustes } from '../../api/gestion'
import type { Mesa } from '../../api/tipos'
import { useSesion } from '../auth/SesionContext'

/**
 * Hoja de códigos QR para imprimir y pegar en las mesas.
 *
 * Es lo último que separa al restaurante de usar el menú por QR: sin esto hay
 * que generar los códigos a mano, uno por uno. Se imprime desde el navegador,
 * que ya sabe hacerlo bien y no obliga a montar generación de PDF en el
 * servidor.
 */

/** Cuántas tarjetas caben por hoja, y de qué tamaño salen. */
const TAMANOS = {
  grande: { texto: 'Grande — 2 por hoja', porHoja: 2, clase: 'h-[128mm]', qr: 260 },
  normal: { texto: 'Normal — 4 por hoja', porHoja: 4, clase: 'h-[128mm]', qr: 190 },
  pequeno: { texto: 'Pequeño — 8 por hoja', porHoja: 8, clase: 'h-[64mm]', qr: 120 },
} as const

type Tamano = keyof typeof TAMANOS

export default function QrImprimibles() {
  const { sesion } = useSesion()
  const navegar = useNavigate()
  const [tamano, setTamano] = useState<Tamano>('normal')
  const [seleccion, setSeleccion] = useState<Set<number> | null>(null)
  const [codigos, setCodigos] = useState<Record<number, string>>({})

  const { data: mesas = [], isLoading } = useQuery({
    queryKey: ['mesas'],
    queryFn: gestionAjustes.mesas,
  })

  const slug = sesion?.restaurante.slug ?? ''

  // El origen sale del propio navegador: en desarrollo será localhost y en
  // producción el dominio real, sin tener que configurarlo en ningún sitio.
  const urlDe = (mesa: Mesa) =>
    `${window.location.origin}/m/${slug}?mesa=${mesa.qr_code}`

  useEffect(() => {
    if (mesas.length === 0) return

    let vigente = true

    Promise.all(
      mesas.map(async (mesa) => {
        const svg = await QRCode.toString(urlDe(mesa), {
          type: 'svg',
          margin: 1,
          // Corrección alta: estos códigos acaban rozados, con vasos encima y
          // fotografiados de lado. Aguantan hasta un 30% de daño.
          errorCorrectionLevel: 'H',
          color: { dark: '#171412', light: '#ffffff' },
        })

        return [mesa.id, svg] as const
      }),
    ).then((pares) => {
      if (vigente) setCodigos(Object.fromEntries(pares))
    })

    return () => {
      vigente = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [mesas, slug])

  const elegidas = seleccion ?? new Set(mesas.map((m) => m.id))
  const aImprimir = mesas.filter((m) => elegidas.has(m.id))
  const config = TAMANOS[tamano]

  function alternar(id: number) {
    const copia = new Set(elegidas)
    copia.has(id) ? copia.delete(id) : copia.add(id)
    setSeleccion(copia)
  }

  if (isLoading) {
    return <div className="p-6 text-sm text-piedra-500">Cargando mesas…</div>
  }

  return (
    <div className="min-h-dvh bg-piedra-100">
      {/* Controles: no se imprimen. */}
      <div className="no-imprimir border-b border-piedra-200 bg-white">
        <div className="mx-auto max-w-4xl p-4 lg:p-6">
          <button
            onClick={() => navegar('/ajustes')}
            className="mb-3 text-sm font-medium text-piedra-500 hover:text-piedra-800"
          >
            ← Volver a ajustes
          </button>

          <h1 className="text-2xl font-bold text-piedra-900">Códigos QR de las mesas</h1>
          <p className="mt-1 text-sm text-piedra-500">
            Imprime, recorta y pega uno en cada mesa. El cliente lo escanea y ve tu carta.
          </p>

          {mesas.length === 0 ? (
            <p className="mt-4 rounded-xl border border-dashed border-piedra-300 p-6 text-center
                          text-sm text-piedra-500">
              Todavía no hay mesas. Créalas primero en Ajustes.
            </p>
          ) : (
            <>
              <div className="mt-5 flex flex-wrap items-end gap-4">
                <label className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium text-piedra-700">Tamaño</span>
                  <select
                    value={tamano}
                    onChange={(e) => setTamano(e.target.value as Tamano)}
                    className="min-h-11 rounded-xl border border-piedra-300 px-3
                               focus:border-marca-500 focus:outline-none"
                  >
                    {Object.entries(TAMANOS).map(([clave, t]) => (
                      <option key={clave} value={clave}>
                        {t.texto}
                      </option>
                    ))}
                  </select>
                </label>

                <button
                  onClick={() => window.print()}
                  disabled={aImprimir.length === 0}
                  className="min-h-11 rounded-xl bg-marca-600 px-5 font-semibold text-white
                             transition hover:bg-marca-700 disabled:opacity-40"
                >
                  Imprimir {aImprimir.length} código{aImprimir.length === 1 ? '' : 's'}
                </button>
              </div>

              <div className="mt-4">
                <div className="mb-2 flex items-center gap-3">
                  <span className="text-sm font-medium text-piedra-700">Mesas</span>
                  <button
                    onClick={() => setSeleccion(new Set(mesas.map((m) => m.id)))}
                    className="text-sm font-semibold text-marca-700 underline underline-offset-4"
                  >
                    Todas
                  </button>
                  <button
                    onClick={() => setSeleccion(new Set())}
                    className="text-sm font-semibold text-piedra-500 underline underline-offset-4"
                  >
                    Ninguna
                  </button>
                </div>

                <div className="flex flex-wrap gap-2">
                  {mesas.map((mesa) => (
                    <button
                      key={mesa.id}
                      onClick={() => alternar(mesa.id)}
                      aria-pressed={elegidas.has(mesa.id)}
                      className={`min-h-10 min-w-12 rounded-lg px-3 text-sm font-semibold transition ${
                        elegidas.has(mesa.id)
                          ? 'bg-piedra-900 text-white'
                          : 'bg-white text-piedra-500 ring-1 ring-piedra-200'
                      }`}
                    >
                      {mesa.number}
                    </button>
                  ))}
                </div>
              </div>

              <p className="mt-4 rounded-lg bg-piedra-100 px-3 py-2 text-xs text-piedra-500">
                En el diálogo de impresión activa <strong>Gráficos de fondo</strong> y pon los
                márgenes al mínimo, o los recuadros de corte saldrán cortados.
              </p>
            </>
          )}
        </div>
      </div>

      {/* Hoja */}
      <div className="hoja mx-auto max-w-4xl p-4 lg:p-6">
        <div className={`grid gap-0 ${tamano === 'pequeno' ? 'grid-cols-2' : 'grid-cols-2'}`}>
          {aImprimir.map((mesa) => (
            <article
              key={mesa.id}
              className={`tarjeta flex ${config.clase} flex-col items-center justify-center
                          gap-2 border border-dashed border-piedra-300 bg-white p-4 text-center`}
            >
              <p className="text-sm font-semibold text-piedra-500">
                {sesion?.restaurante.name}
              </p>

              <p className="cifras text-3xl leading-none font-bold text-piedra-900">
                Mesa {mesa.number}
              </p>

              {codigos[mesa.id] ? (
                <div
                  className="qr"
                  style={{ width: config.qr, height: config.qr }}
                  dangerouslySetInnerHTML={{ __html: codigos[mesa.id] }}
                />
              ) : (
                <div
                  style={{ width: config.qr, height: config.qr }}
                  className="animate-pulse rounded bg-piedra-200"
                />
              )}

              <p className="text-sm font-semibold text-piedra-800">
                Escanea y pide desde tu celular
              </p>
              <p className="text-xs text-piedra-400">
                Apunta la cámara al código
              </p>
            </article>
          ))}
        </div>
      </div>

      <style>{`
        .qr svg { width: 100%; height: 100%; display: block; }

        @media print {
          /* Solo la hoja: fuera controles y cualquier resto del panel. */
          .no-imprimir, aside, header, nav { display: none !important; }

          body, .hoja { background: #fff !important; margin: 0 !important; padding: 0 !important; }

          @page { size: A4; margin: 8mm; }

          /* Que ninguna tarjeta quede partida entre dos hojas. */
          .tarjeta {
            break-inside: avoid;
            page-break-inside: avoid;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
          }
        }
      `}</style>
    </div>
  )
}
