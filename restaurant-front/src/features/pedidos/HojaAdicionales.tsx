import { useState } from 'react'
import type { Adicional, Producto } from '../../api/tipos'
import { dinero } from '../../lib/formato'

/**
 * Elección de adicionales antes de añadir al carrito.
 *
 * Solo aparece si el producto los tiene: obligar a pasar por aquí para una
 * gaseosa sin opciones sería un toque de más en cada pedido.
 */
export default function HojaAdicionales({
  producto,
  moneda,
  onCancelar,
  onAgregar,
}: {
  producto: Producto
  moneda: string
  onCancelar: () => void
  onAgregar: (adicionales: Adicional[], notas: string, cantidad: number) => void
}) {
  const grupos = producto.additional_groups ?? []
  const [elegidos, setElegidos] = useState<Record<number, number[]>>({})
  const [notas, setNotas] = useState('')
  const [cantidad, setCantidad] = useState(1)

  const disponibles = (g: (typeof grupos)[number]) => g.additionals.filter((a) => a.is_available)

  function alternar(grupoId: number, adicionalId: number, unico: boolean) {
    setElegidos((previo) => {
      const actuales = previo[grupoId] ?? []

      if (unico) {
        return { ...previo, [grupoId]: actuales.includes(adicionalId) ? [] : [adicionalId] }
      }

      return {
        ...previo,
        [grupoId]: actuales.includes(adicionalId)
          ? actuales.filter((id) => id !== adicionalId)
          : [...actuales, adicionalId],
      }
    })
  }

  const seleccionados: Adicional[] = grupos.flatMap((g) =>
    disponibles(g).filter((a) => (elegidos[g.id] ?? []).includes(a.id)),
  )

  // Los grupos obligatorios bloquean el botón hasta elegir algo.
  const faltan = grupos.filter((g) => g.is_required && (elegidos[g.id] ?? []).length === 0)

  const extras = seleccionados.reduce((s, a) => s + Number(a.extra_price), 0)
  const total = (Number(producto.price) + extras) * cantidad

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center">
      <div
        role="dialog"
        aria-label={`Opciones de ${producto.name}`}
        className="flex max-h-[90dvh] w-full max-w-lg flex-col rounded-t-2xl bg-white
                   sm:rounded-2xl"
      >
        <header className="border-b border-piedra-200 px-5 py-4">
          <h2 className="text-lg font-bold text-piedra-900">{producto.name}</h2>
          <p className="cifras text-sm text-piedra-500">{dinero(producto.price, moneda)}</p>
        </header>

        <div className="flex-1 overflow-y-auto px-5 py-4">
          {grupos.map((grupo) => {
            const opciones = disponibles(grupo)
            if (opciones.length === 0) return null

            const unico = grupo.selection_type === 'single'

            return (
              <fieldset key={grupo.id} className="mb-5">
                <legend className="mb-2 flex items-center gap-2 text-sm font-semibold text-piedra-800">
                  {grupo.name}
                  {grupo.is_required ? (
                    <span className="rounded-full bg-pendiente-suave px-2 py-0.5
                                     text-[11px] font-semibold text-pendiente">
                      Obligatorio
                    </span>
                  ) : (
                    <span className="text-xs font-normal text-piedra-400">
                      {unico ? 'Elige una' : 'Varias'}
                    </span>
                  )}
                </legend>

                <div className="flex flex-col gap-1.5">
                  {opciones.map((a) => {
                    const marcado = (elegidos[grupo.id] ?? []).includes(a.id)

                    return (
                      <label
                        key={a.id}
                        className={`flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border
                                    px-3.5 transition ${
                                      marcado
                                        ? 'border-marca-500 bg-marca-50'
                                        : 'border-piedra-200 hover:border-piedra-300'
                                    }`}
                      >
                        <input
                          type={unico ? 'radio' : 'checkbox'}
                          name={`grupo-${grupo.id}`}
                          checked={marcado}
                          onChange={() => alternar(grupo.id, a.id, unico)}
                          className="h-5 w-5 accent-marca-600"
                        />
                        <span className="flex-1 text-piedra-800">{a.name}</span>
                        {Number(a.extra_price) > 0 && (
                          <span className="cifras text-sm text-piedra-500">
                            +{dinero(a.extra_price, moneda)}
                          </span>
                        )}
                      </label>
                    )
                  })}
                </div>
              </fieldset>
            )
          })}

          <label className="flex flex-col gap-1.5">
            <span className="text-sm font-semibold text-piedra-800">
              Nota para cocina{' '}
              <span className="font-normal text-piedra-400">(opcional)</span>
            </span>
            <textarea
              value={notas}
              onChange={(e) => setNotas(e.target.value)}
              rows={2}
              placeholder="Sin cebolla, término medio…"
              className="rounded-xl border border-piedra-300 px-3.5 py-2.5 text-piedra-900
                         focus:border-marca-500 focus:outline-none"
            />
          </label>
        </div>

        <footer className="border-t border-piedra-200 p-4">
          <div className="mb-3 flex items-center justify-between">
            <Contador valor={cantidad} onCambio={setCantidad} />
            <span className="cifras text-lg font-bold text-piedra-900">
              {dinero(total, moneda)}
            </span>
          </div>

          <div className="flex gap-2">
            <button
              onClick={onCancelar}
              className="min-h-12 flex-1 rounded-xl border border-piedra-300 font-semibold
                         text-piedra-700"
            >
              Cancelar
            </button>
            <button
              onClick={() => onAgregar(seleccionados, notas, cantidad)}
              disabled={faltan.length > 0}
              className="min-h-12 flex-[2] rounded-xl bg-marca-600 font-semibold text-white
                         transition hover:bg-marca-700 disabled:opacity-40"
            >
              {faltan.length > 0 ? `Elige ${faltan[0].name}` : 'Añadir'}
            </button>
          </div>
        </footer>
      </div>
    </div>
  )
}

export function Contador({
  valor,
  onCambio,
  minimo = 1,
}: {
  valor: number
  onCambio: (v: number) => void
  minimo?: number
}) {
  return (
    <div className="flex items-center gap-1 rounded-xl border border-piedra-300">
      <button
        onClick={() => onCambio(Math.max(minimo, valor - 1))}
        aria-label="Quitar uno"
        className="flex h-11 w-11 items-center justify-center text-xl font-bold
                   text-piedra-600 disabled:opacity-30"
        disabled={valor <= minimo}
      >
        −
      </button>
      <span className="cifras w-8 text-center text-lg font-bold text-piedra-900">{valor}</span>
      <button
        onClick={() => onCambio(valor + 1)}
        aria-label="Añadir uno"
        className="flex h-11 w-11 items-center justify-center text-xl font-bold text-piedra-600"
      >
        +
      </button>
    </div>
  )
}
