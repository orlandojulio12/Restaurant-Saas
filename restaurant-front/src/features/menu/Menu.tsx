import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { interpretarError } from '../../api/client'
import { gestionMenu } from '../../api/gestion'
import type { Categoria, GrupoAdicionales, Producto } from '../../api/tipos'
import {
  AvisoError,
  BotonPrimario,
  Pestanas,
  Vacio,
} from '../../components/ui'
import FotoProducto from '../../components/FotoProducto'
import { dinero } from '../../lib/formato'
import { useSesion } from '../auth/SesionContext'
import FormCategoria from './FormCategoria'
import FormGrupo from './FormGrupo'
import FormProducto from './FormProducto'

type Seccion = 'productos' | 'categorias' | 'adicionales'

/**
 * Panel del menú.
 *
 * Tres cosas que se editan juntas y se estorban si viven en pantallas
 * separadas: no se puede crear un producto sin categoría, ni asignarle
 * adicionales sin haberlos definido. Van en pestañas para que cambiar de una a
 * otra no cueste una navegación.
 */
export default function Menu() {
  const { sesion } = useSesion()
  const moneda = sesion?.restaurante.currency ?? 'COP'
  const clienteQuery = useQueryClient()

  const [seccion, setSeccion] = useState<Seccion>('productos')
  const [error, setError] = useState<{ mensaje: string; esLimite: boolean } | null>(null)

  const [editandoProducto, setEditandoProducto] = useState<Producto | null | 'nuevo'>(null)
  const [editandoCategoria, setEditandoCategoria] = useState<Categoria | null | 'nueva'>(null)
  const [editandoGrupo, setEditandoGrupo] = useState<GrupoAdicionales | null | 'nuevo'>(null)

  const categorias = useQuery({ queryKey: ['categorias'], queryFn: gestionMenu.categorias })
  const productos = useQuery({ queryKey: ['productos'], queryFn: gestionMenu.productos })
  const grupos = useQuery({ queryKey: ['grupos'], queryFn: gestionMenu.grupos })

  const refrescar = () => {
    clienteQuery.invalidateQueries({ queryKey: ['categorias'] })
    clienteQuery.invalidateQueries({ queryKey: ['productos'] })
    clienteQuery.invalidateQueries({ queryKey: ['grupos'] })
  }

  const borrar = useMutation({
    mutationFn: async (accion: () => Promise<unknown>) => accion(),
    onSuccess: () => {
      setError(null)
      refrescar()
    },
    onError: (e) => {
      const fallo = interpretarError(e)
      setError({ mensaje: fallo.mensaje, esLimite: fallo.esLimite })
    },
  })

  function confirmarBorrado(texto: string, accion: () => Promise<unknown>) {
    if (confirm(texto)) borrar.mutate(accion)
  }

  const nombreCategoria = (id: number) =>
    categorias.data?.find((c) => c.id === id)?.name ?? '—'

  return (
    <div className="p-4 lg:p-6">
      <header className="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-piedra-900">Menú</h1>
          <p className="text-sm text-piedra-500">Lo que ven tus clientes y tu personal.</p>
        </div>

        <BotonPrimario
          onClick={() => {
            setError(null)
            if (seccion === 'productos') setEditandoProducto('nuevo')
            if (seccion === 'categorias') setEditandoCategoria('nueva')
            if (seccion === 'adicionales') setEditandoGrupo('nuevo')
          }}
        >
          {seccion === 'productos' && '+ Producto'}
          {seccion === 'categorias' && '+ Categoría'}
          {seccion === 'adicionales' && '+ Grupo'}
        </BotonPrimario>
      </header>

      <Pestanas
        actual={seccion}
        onCambio={(v) => {
          setSeccion(v)
          setError(null)
        }}
        opciones={[
          { valor: 'productos', texto: 'Productos', contador: productos.data?.length },
          { valor: 'categorias', texto: 'Categorías', contador: categorias.data?.length },
          { valor: 'adicionales', texto: 'Adicionales', contador: grupos.data?.length },
        ]}
      />

      {error && (
        <div className="mb-4">
          <AvisoError mensaje={error.mensaje} esLimite={error.esLimite} />
        </div>
      )}

      {seccion === 'productos' && (
        <ListaProductos
          productos={productos.data ?? []}
          cargando={productos.isLoading}
          moneda={moneda}
          nombreCategoria={nombreCategoria}
          sinCategorias={(categorias.data?.length ?? 0) === 0}
          onNuevo={() => setEditandoProducto('nuevo')}
          onEditar={setEditandoProducto}
          onBorrar={(p) =>
            confirmarBorrado(`¿Eliminar "${p.name}"?`, () => gestionMenu.borrarProducto(p.id))
          }
        />
      )}

      {seccion === 'categorias' && (
        <ListaCategorias
          categorias={categorias.data ?? []}
          cargando={categorias.isLoading}
          onNueva={() => setEditandoCategoria('nueva')}
          onEditar={setEditandoCategoria}
          onBorrar={(c) =>
            confirmarBorrado(`¿Eliminar la categoría "${c.name}"?`, () =>
              gestionMenu.borrarCategoria(c.id),
            )
          }
        />
      )}

      {seccion === 'adicionales' && (
        <ListaGrupos
          grupos={grupos.data ?? []}
          cargando={grupos.isLoading}
          moneda={moneda}
          onNuevo={() => setEditandoGrupo('nuevo')}
          onEditar={setEditandoGrupo}
          onBorrar={(g) =>
            confirmarBorrado(`¿Eliminar el grupo "${g.name}"?`, () =>
              gestionMenu.borrarGrupo(g.id),
            )
          }
        />
      )}

      {editandoProducto && (
        <FormProducto
          producto={editandoProducto === 'nuevo' ? null : editandoProducto}
          categorias={categorias.data ?? []}
          grupos={grupos.data ?? []}
          moneda={moneda}
          onCerrar={() => setEditandoProducto(null)}
          onGuardado={() => {
            setEditandoProducto(null)
            refrescar()
          }}
        />
      )}

      {editandoCategoria && (
        <FormCategoria
          categoria={editandoCategoria === 'nueva' ? null : editandoCategoria}
          onCerrar={() => setEditandoCategoria(null)}
          onGuardado={() => {
            setEditandoCategoria(null)
            refrescar()
          }}
        />
      )}

      {editandoGrupo && (
        <FormGrupo
          grupo={editandoGrupo === 'nuevo' ? null : editandoGrupo}
          onCerrar={() => setEditandoGrupo(null)}
          onGuardado={() => {
            setEditandoGrupo(null)
            refrescar()
          }}
        />
      )}
    </div>
  )
}

function ListaProductos({
  productos,
  cargando,
  moneda,
  nombreCategoria,
  sinCategorias,
  onNuevo,
  onEditar,
  onBorrar,
}: {
  productos: Producto[]
  cargando: boolean
  moneda: string
  nombreCategoria: (id: number) => string
  sinCategorias: boolean
  onNuevo: () => void
  onEditar: (p: Producto) => void
  onBorrar: (p: Producto) => void
}) {
  if (cargando) return <Esqueleto />

  // Sin categorías no se puede crear nada: se dice antes de que lo intente.
  if (sinCategorias) {
    return (
      <Vacio
        titulo="Primero crea una categoría"
        texto="Todo producto pertenece a una categoría. Crea al menos una para empezar tu carta."
      />
    )
  }

  if (productos.length === 0) {
    return (
      <Vacio
        titulo="Tu carta está vacía"
        texto="Añade tu primer producto para poder tomar pedidos."
        accion={{ texto: '+ Producto', onClick: onNuevo }}
      />
    )
  }

  return (
    <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
      {productos.map((p) => (
        <li
          key={p.id}
          className={`flex gap-3 rounded-2xl border bg-white p-3 ${
            p.is_available ? 'border-piedra-200' : 'border-piedra-200 opacity-60'
          }`}
        >
          <FotoProducto
            nombre={p.name}
            url={p.image_url}
            className="h-16 w-16 shrink-0 text-2xl"
          />

          <div className="min-w-0 flex-1">
            <p className="truncate font-semibold text-piedra-900">{p.name}</p>
            <p className="truncate text-xs text-piedra-500">{nombreCategoria(p.category_id)}</p>
            <p className="cifras mt-0.5 text-sm font-semibold text-piedra-800">
              {dinero(p.price, moneda)}
              {!p.is_available && (
                <span className="ml-2 rounded-full bg-piedra-200 px-2 py-0.5 text-[11px]
                                 font-semibold text-piedra-600">
                  No disponible
                </span>
              )}
            </p>
          </div>

          <div className="flex shrink-0 flex-col gap-1">
            <button
              onClick={() => onEditar(p)}
              className="min-h-11 rounded-lg px-3 text-sm font-medium text-marca-700 hover:bg-marca-50"
            >
              Editar
            </button>
            <button
              onClick={() => onBorrar(p)}
              className="min-h-11 rounded-lg px-3 text-sm font-medium text-piedra-500
                         hover:bg-cancelado-suave hover:text-cancelado"
            >
              Eliminar
            </button>
          </div>
        </li>
      ))}
    </ul>
  )
}

function ListaCategorias({
  categorias,
  cargando,
  onNueva,
  onEditar,
  onBorrar,
}: {
  categorias: Categoria[]
  cargando: boolean
  onNueva: () => void
  onEditar: (c: Categoria) => void
  onBorrar: (c: Categoria) => void
}) {
  if (cargando) return <Esqueleto />

  if (categorias.length === 0) {
    return (
      <Vacio
        titulo="Sin categorías"
        texto="Las categorías agrupan tu carta: entradas, platos fuertes, bebidas…"
        accion={{ texto: '+ Categoría', onClick: onNueva }}
      />
    )
  }

  return (
    <ul className="flex flex-col gap-2">
      {categorias.map((c) => (
        <li
          key={c.id}
          className="flex items-center gap-3 rounded-xl border border-piedra-200 bg-white p-3.5"
        >
          <div className="min-w-0 flex-1">
            <p className="font-semibold text-piedra-900">
              {c.name}
              {!c.is_active && (
                <span className="ml-2 rounded-full bg-piedra-200 px-2 py-0.5 text-[11px]
                                 font-semibold text-piedra-600">
                  Oculta
                </span>
              )}
            </p>
            <p className="cifras text-xs text-piedra-500">
              {c.products_count ?? 0} producto{c.products_count === 1 ? '' : 's'}
            </p>
          </div>

          <button
            onClick={() => onEditar(c)}
            className="min-h-11 rounded-lg px-3 text-sm font-medium text-marca-700 hover:bg-marca-50"
          >
            Editar
          </button>
          <button
            onClick={() => onBorrar(c)}
            className="min-h-11 rounded-lg px-3 text-sm font-medium text-piedra-500
                       hover:bg-cancelado-suave hover:text-cancelado"
          >
            Eliminar
          </button>
        </li>
      ))}
    </ul>
  )
}

function ListaGrupos({
  grupos,
  cargando,
  moneda,
  onNuevo,
  onEditar,
  onBorrar,
}: {
  grupos: GrupoAdicionales[]
  cargando: boolean
  moneda: string
  onNuevo: () => void
  onEditar: (g: GrupoAdicionales) => void
  onBorrar: (g: GrupoAdicionales) => void
}) {
  if (cargando) return <Esqueleto />

  if (grupos.length === 0) {
    return (
      <Vacio
        titulo="Sin grupos de adicionales"
        texto="Sirven para las opciones de un producto: tamaño, salsas, punto de cocción."
        accion={{ texto: '+ Grupo', onClick: onNuevo }}
      />
    )
  }

  return (
    <ul className="grid gap-3 sm:grid-cols-2">
      {grupos.map((g) => (
        <li key={g.id} className="rounded-2xl border border-piedra-200 bg-white p-4">
          <div className="mb-2 flex items-start justify-between gap-2">
            <div>
              <p className="font-semibold text-piedra-900">{g.name}</p>
              <p className="text-xs text-piedra-500">
                {g.selection_type === 'single' ? 'Elige una' : 'Varias'}
                {g.is_required && ' · obligatorio'}
              </p>
            </div>
            <div className="flex shrink-0 gap-1">
              <button
                onClick={() => onEditar(g)}
                className="min-h-11 rounded-lg px-3 text-sm font-medium text-marca-700 hover:bg-marca-50"
              >
                Editar
              </button>
              <button
                onClick={() => onBorrar(g)}
                className="min-h-11 rounded-lg px-3 text-sm font-medium text-piedra-500
                           hover:bg-cancelado-suave hover:text-cancelado"
              >
                Eliminar
              </button>
            </div>
          </div>

          <ul className="flex flex-wrap gap-1.5">
            {g.additionals.map((a) => (
              <li
                key={a.id}
                className={`cifras min-h-11 rounded-lg px-3 text-xs ${
                  a.is_available
                    ? 'bg-piedra-100 text-piedra-700'
                    : 'bg-piedra-100 text-piedra-500 line-through'
                }`}
              >
                {a.name}
                {Number(a.extra_price) > 0 && ` +${dinero(a.extra_price, moneda)}`}
              </li>
            ))}
            {g.additionals.length === 0 && (
              <li className="text-xs text-piedra-500">Sin opciones todavía.</li>
            )}
          </ul>
        </li>
      ))}
    </ul>
  )
}

function Esqueleto() {
  return (
    <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
      {Array.from({ length: 6 }).map((_, i) => (
        <li key={i} className="h-24 animate-pulse rounded-2xl bg-piedra-200" />
      ))}
    </ul>
  )
}
