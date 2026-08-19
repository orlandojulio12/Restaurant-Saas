import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { interpretarError } from '../../api/client'
import {
  gestionInventario,
  gestionMenu,
  type Ingrediente,
  type Movimiento,
} from '../../api/gestion'
import {
  AvisoError,
  BotonPrimario,
  Pestanas,
  Vacio,
} from '../../components/ui'
import { dinero } from '../../lib/formato'
import { useSesion } from '../auth/SesionContext'
import FormIngrediente from './FormIngrediente'
import FormMovimiento from './FormMovimiento'

type Seccion = 'ingredientes' | 'movimientos'

const TIPO_MOVIMIENTO: Record<Movimiento['type'], { texto: string; clase: string }> = {
  in: { texto: 'Entrada', clase: 'bg-listo-suave text-listo' },
  out: { texto: 'Salida', clase: 'bg-preparando-suave text-preparando' },
  waste: { texto: 'Merma', clase: 'bg-cancelado-suave text-cancelado' },
  adjustment: { texto: 'Conteo', clase: 'bg-pendiente-suave text-pendiente' },
}

/**
 * Panel de inventario.
 *
 * El stock nunca se edita a mano: solo cambia por movimientos, y cada
 * movimiento queda con su motivo y su autor. Esa disciplina es lo que hace que
 * el historial sirva para cuadrar la bodega en vez de ser decorativo.
 */
export default function Inventario() {
  const { sesion } = useSesion()
  const moneda = sesion?.restaurante.currency ?? 'COP'
  const clienteQuery = useQueryClient()

  const [seccion, setSeccion] = useState<Seccion>('ingredientes')

  // Un producto sin receta no descuenta nada al venderse: el inventario deja
  // de reflejar la realidad sin avisar. No se obliga a tener receta —hay
  // productos que se venden por unidad, y el plan gratis ni siquiera tiene
  // inventario— pero sí se dice cuáles quedan fuera del control.
  const productos = useQuery({ queryKey: ['productos'], queryFn: gestionMenu.productos })
  const recetas = useQuery({
    queryKey: ['recetas', 'cobertura'],
    queryFn: async () => {
      const lista = await gestionMenu.productos()
      const detalles = await Promise.all(
        lista.map((p) => gestionInventario.receta(p.id).catch(() => null)),
      )

      return lista.filter((_, i) => (detalles[i]?.ingredients.length ?? 0) === 0)
    },
    enabled: (productos.data?.length ?? 0) > 0,
  })
  const [soloAlerta, setSoloAlerta] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [editando, setEditando] = useState<Ingrediente | null | 'nuevo'>(null)
  const [moviendo, setMoviendo] = useState<Ingrediente | null>(null)

  const ingredientes = useQuery({
    queryKey: ['ingredientes'],
    queryFn: gestionInventario.ingredientes,
  })

  const movimientos = useQuery({
    queryKey: ['movimientos'],
    queryFn: () => gestionInventario.movimientos(),
    enabled: seccion === 'movimientos',
  })

  const refrescar = () => {
    clienteQuery.invalidateQueries({ queryKey: ['ingredientes'] })
    clienteQuery.invalidateQueries({ queryKey: ['movimientos'] })
  }

  const borrar = useMutation({
    mutationFn: (id: number) => gestionInventario.borrarIngrediente(id),
    onSuccess: () => {
      setError(null)
      refrescar()
    },
    onError: (e) => setError(interpretarError(e).mensaje),
  })

  // El módulo entero depende del plan; el backend responde 403 y aquí se dice
  // con claridad en vez de mostrar listas vacías.
  const sinPlan =
    ingredientes.isError && interpretarError(ingredientes.error).esPlan

  if (sinPlan) {
    return (
      <div className="p-6">
        <h1 className="mb-4 text-2xl font-bold text-piedra-900">Inventario</h1>
        <Vacio
          titulo="Tu plan no incluye inventario"
          texto="Con el módulo de inventario puedes controlar ingredientes, recetas y mermas, y que el stock se descuente solo al cerrar cada pedido."
        />
      </div>
    )
  }

  const lista = (ingredientes.data ?? []).filter((i) => !soloAlerta || i.low_stock)
  const enAlerta = (ingredientes.data ?? []).filter((i) => i.low_stock).length

  return (
    <div className="p-4 lg:p-6">
      <header className="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-piedra-900">Inventario</h1>
          <p className="text-sm text-piedra-500">
            {enAlerta > 0
              ? `${enAlerta} ingrediente${enAlerta === 1 ? '' : 's'} bajo mínimo`
              : 'Todo por encima del mínimo.'}
          </p>
        </div>

        {seccion === 'ingredientes' && (
          <BotonPrimario onClick={() => setEditando('nuevo')}>+ Ingrediente</BotonPrimario>
        )}
      </header>

      <Pestanas
        actual={seccion}
        onCambio={setSeccion}
        opciones={[
          { valor: 'ingredientes', texto: 'Ingredientes', contador: ingredientes.data?.length },
          { valor: 'movimientos', texto: 'Movimientos' },
        ]}
      />

      {error && (
        <div className="mb-4">
          <AvisoError mensaje={error} />
        </div>
      )}

      {seccion === 'ingredientes' && (
        <>
          <SinReceta productos={recetas.data ?? []} cargando={recetas.isLoading} />

          {enAlerta > 0 && (
            <button
              onClick={() => setSoloAlerta((v) => !v)}
              className={`mb-4 flex w-full items-center gap-3 rounded-xl px-4 py-3 text-left
                          transition ${
                            soloAlerta
                              ? 'bg-pendiente text-white'
                              : 'bg-pendiente-suave text-pendiente hover:brightness-95'
                          }`}
            >
              <span className="text-xl" aria-hidden>
                ⚠
              </span>
              <span className="flex-1 text-sm font-semibold">
                {enAlerta} bajo mínimo
                <span className="block text-xs font-normal opacity-80">
                  {soloAlerta ? 'Mostrando solo estos · toca para ver todos' : 'Toca para filtrar'}
                </span>
              </span>
            </button>
          )}

          {ingredientes.isLoading ? (
            <Esqueleto />
          ) : lista.length === 0 ? (
            <Vacio
              titulo={soloAlerta ? 'Nada bajo mínimo' : 'Sin ingredientes'}
              texto={
                soloAlerta
                  ? 'Todo tiene stock suficiente.'
                  : 'Registra tus insumos para controlar el stock y que se descuente solo al cerrar pedidos.'
              }
              accion={
                soloAlerta
                  ? { texto: 'Ver todos', onClick: () => setSoloAlerta(false) }
                  : { texto: '+ Ingrediente', onClick: () => setEditando('nuevo') }
              }
            />
          ) : (
            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {lista.map((i) => (
                <li
                  key={i.id}
                  className={`rounded-2xl border-2 bg-white p-4 ${
                    i.low_stock ? 'border-pendiente' : 'border-piedra-200'
                  } ${i.is_active ? '' : 'opacity-60'}`}
                >
                  <div className="mb-2 flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="truncate font-semibold text-piedra-900">{i.name}</p>
                      <p className="cifras text-xs text-piedra-500">
                        mín. {Number(i.min_stock)} {i.unit} ·{' '}
                        {dinero(i.cost_per_unit, moneda)}/{i.unit}
                      </p>
                    </div>
                    {i.low_stock && (
                      <span className="shrink-0 rounded-full bg-pendiente-suave px-2 py-0.5
                                       text-[11px] font-semibold text-pendiente">
                        Bajo
                      </span>
                    )}
                  </div>

                  <p
                    className={`cifras text-2xl font-bold ${
                      i.low_stock ? 'text-pendiente' : 'text-piedra-900'
                    }`}
                  >
                    {Number(i.stock)}{' '}
                    <span className="text-base font-semibold text-piedra-500">{i.unit}</span>
                  </p>

                  <div className="mt-3 flex gap-2">
                    <button
                      onClick={() => setMoviendo(i)}
                      className="min-h-11 flex-1 rounded-xl bg-marca-600 text-sm font-semibold
                                 text-white transition hover:bg-marca-700"
                    >
                      Movimiento
                    </button>
                    <button
                      onClick={() => setEditando(i)}
                      className="min-h-11 rounded-xl px-3 text-sm font-medium text-marca-700
                                 hover:bg-marca-50"
                    >
                      Editar
                    </button>
                    <button
                      onClick={() => {
                        if (confirm(`¿Eliminar "${i.name}"?`)) borrar.mutate(i.id)
                      }}
                      className="min-h-11 rounded-xl px-3 text-sm font-medium text-piedra-400
                                 hover:bg-cancelado-suave hover:text-cancelado"
                    >
                      Eliminar
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </>
      )}

      {seccion === 'movimientos' && (
        <ListaMovimientos
          movimientos={movimientos.data?.data ?? []}
          cargando={movimientos.isLoading}
        />
      )}

      {editando && (
        <FormIngrediente
          ingrediente={editando === 'nuevo' ? null : editando}
          onCerrar={() => setEditando(null)}
          onGuardado={() => {
            setEditando(null)
            refrescar()
          }}
        />
      )}

      {moviendo && (
        <FormMovimiento
          ingrediente={moviendo}
          onCerrar={() => setMoviendo(null)}
          onGuardado={() => {
            setMoviendo(null)
            refrescar()
          }}
        />
      )}
    </div>
  )
}

function ListaMovimientos({
  movimientos,
  cargando,
}: {
  movimientos: Movimiento[]
  cargando: boolean
}) {
  if (cargando) return <Esqueleto />

  if (movimientos.length === 0) {
    return (
      <Vacio
        titulo="Sin movimientos"
        texto="Aquí queda el rastro de cada entrada, salida y merma, incluidas las que genera el cierre de un pedido."
      />
    )
  }

  return (
    <ul className="flex flex-col gap-2">
      {movimientos.map((m) => {
        const pinta = TIPO_MOVIMIENTO[m.type]
        const delta = Number(m.stock_after) - Number(m.stock_before)

        return (
          <li
            key={m.id}
            className="flex items-center gap-3 rounded-xl border border-piedra-200 bg-white p-3.5"
          >
            <span
              className={`shrink-0 min-h-11 rounded-lg px-3 text-xs font-semibold ${pinta.clase}`}
            >
              {pinta.texto}
            </span>

            <div className="min-w-0 flex-1">
              <p className="truncate font-semibold text-piedra-900">
                {m.ingredient?.name ?? 'Ingrediente'}
              </p>
              <p className="truncate text-xs text-piedra-500">
                {m.reason ?? (m.order_id ? `Pedido #${m.order_id}` : 'Sin motivo')}
                {m.user && ` · ${m.user.name}`}
              </p>
            </div>

            <div className="shrink-0 text-right">
              <p
                className={`cifras font-bold ${
                  delta > 0 ? 'text-listo' : delta < 0 ? 'text-cancelado' : 'text-piedra-600'
                }`}
              >
                {delta > 0 ? '+' : ''}
                {delta.toFixed(3).replace(/\.?0+$/, '')} {m.ingredient?.unit}
              </p>
              <p className="cifras text-xs text-piedra-400">
                {Number(m.stock_before)} → {Number(m.stock_after)}
              </p>
            </div>
          </li>
        )
      })}
    </ul>
  )
}

function Esqueleto() {
  return (
    <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
      {Array.from({ length: 6 }).map((_, i) => (
        <li key={i} className="h-32 animate-pulse rounded-2xl bg-piedra-200" />
      ))}
    </ul>
  )
}

/**
 * Productos que no descuentan inventario porque no tienen receta.
 *
 * No es un error: hay productos que se venden por unidad y no hay por qué
 * modelarlos. Pero conviene saber qué queda fuera del control, porque el
 * inventario calla esa parte sin avisar.
 */
function SinReceta({ productos, cargando }: { productos: { id: number; name: string }[]; cargando: boolean }) {
  const [abierto, setAbierto] = useState(false)

  if (cargando || productos.length === 0) return null

  return (
    <div className="mb-4 rounded-xl border border-pendiente bg-pendiente-suave p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-semibold text-pendiente">
          {productos.length} producto{productos.length === 1 ? '' : 's'} sin receta
        </p>
        <button
          onClick={() => setAbierto((v) => !v)}
          className="py-3 -my-3 text-sm font-semibold text-pendiente underline underline-offset-4"
        >
          {abierto ? 'Ocultar' : 'Ver cuáles'}
        </button>
      </div>

      <p className="mt-1 text-sm text-pendiente">
        Al venderse no descuentan nada del inventario. Si se preparan con ingredientes, defíneles
        la receta; si se venden por unidad —una gaseosa, un agua— puedes dejarlo así.
      </p>

      {abierto && (
        <ul className="mt-3 flex flex-wrap gap-1.5">
          {productos.map((p) => (
            <li key={p.id}>
              <Link
                to={`/menu?producto=${p.id}`}
                className="inline-block rounded-lg bg-white/70 px-2.5 py-1 text-sm
                           font-medium text-pendiente hover:bg-white"
              >
                {p.name}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
