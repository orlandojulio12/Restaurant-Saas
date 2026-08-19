import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { interpretarError } from '../../api/client'
import {
  claveDeLinea,
  menuApi,
  pedidosApi,
  precioLinea,
  totalCarrito,
  type LineaCarrito,
} from '../../api/pedidos'
import type { Adicional, Producto, TipoPedido } from '../../api/tipos'
import { dinero, TIPOS } from '../../lib/formato'
import { useSesion } from '../auth/SesionContext'
import HojaAdicionales, { Contador } from './HojaAdicionales'

/**
 * Alta de pedido.
 *
 * La medida de si esto está bien hecho es cuántos toques cuesta un pedido
 * típico. Por eso un producto sin adicionales entra de un solo toque, y la
 * hoja de opciones solo aparece cuando de verdad hay algo que elegir.
 */
export default function NuevoPedido() {
  const { sesion } = useSesion()
  const moneda = sesion?.restaurante.currency ?? 'COP'
  const navegar = useNavigate()
  const clienteQuery = useQueryClient()
  const [params] = useSearchParams()

  const mesaInicial = params.get('mesa')

  const [tipo, setTipo] = useState<TipoPedido>(mesaInicial ? 'dine_in' : 'dine_in')
  const [mesaId, setMesaId] = useState<number | null>(mesaInicial ? Number(mesaInicial) : null)
  const [direccion, setDireccion] = useState('')
  const [categoriaId, setCategoriaId] = useState<number | 'todas'>('todas')
  const [busqueda, setBusqueda] = useState('')
  const [carrito, setCarrito] = useState<LineaCarrito[]>([])
  const [configurando, setConfigurando] = useState<Producto | null>(null)
  const [carritoAbierto, setCarritoAbierto] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { data: categorias = [] } = useQuery({
    queryKey: ['categorias'],
    queryFn: menuApi.categorias,
  })
  const { data: productos = [], isLoading } = useQuery({
    queryKey: ['productos'],
    queryFn: menuApi.productos,
  })
  const { data: mesas = [] } = useQuery({ queryKey: ['mesas'], queryFn: menuApi.mesas })

  const visibles = useMemo(() => {
    const texto = busqueda.trim().toLowerCase()

    return productos.filter(
      (p) =>
        (categoriaId === 'todas' || p.category_id === categoriaId) &&
        (!texto || p.name.toLowerCase().includes(texto)),
    )
  }, [productos, categoriaId, busqueda])

  const crear = useMutation({
    mutationFn: () =>
      pedidosApi.crear({
        type: tipo,
        table_id: tipo === 'dine_in' ? mesaId : null,
        delivery_address: tipo === 'delivery' ? direccion : null,
        items: carrito.map((l) => ({
          product_id: l.producto.id,
          quantity: l.cantidad,
          notes: l.notas || null,
          additionals: l.adicionales.map((a) => a.id),
        })),
      }),
    onSuccess: (pedido) => {
      clienteQuery.invalidateQueries({ queryKey: ['mesas'] })
      clienteQuery.invalidateQueries({ queryKey: ['pedidos'] })
      navegar(`/pedidos/${pedido.id}`, { replace: true })
    },
    onError: (e) => setError(interpretarError(e).mensaje),
  })

  function agregar(producto: Producto, adicionales: Adicional[], notas: string, cantidad: number) {
    const clave = claveDeLinea(
      producto.id,
      adicionales.map((a) => a.id),
      notas,
    )

    setCarrito((previo) => {
      const existente = previo.find((l) => l.clave === clave)

      // La misma combinación exacta suma cantidad en vez de repetir línea.
      if (existente) {
        return previo.map((l) =>
          l.clave === clave ? { ...l, cantidad: l.cantidad + cantidad } : l,
        )
      }

      return [
        ...previo,
        {
          clave,
          producto,
          cantidad,
          notas,
          adicionales: adicionales.map((a) => ({
            id: a.id,
            name: a.name,
            extra_price: Number(a.extra_price),
          })),
        },
      ]
    })

    setConfigurando(null)
  }

  function tocarProducto(producto: Producto) {
    const tieneOpciones = (producto.additional_groups ?? []).some(
      (g) => g.additionals.filter((a) => a.is_available).length > 0,
    )

    // Un toque si no hay nada que elegir; hoja de opciones si lo hay.
    tieneOpciones ? setConfigurando(producto) : agregar(producto, [], '', 1)
  }

  const total = totalCarrito(carrito)
  const unidades = carrito.reduce((s, l) => s + l.cantidad, 0)

  const listo =
    carrito.length > 0 &&
    (tipo !== 'dine_in' || mesaId !== null) &&
    (tipo !== 'delivery' || direccion.trim().length > 4)

  return (
    <div className="lg:flex lg:h-dvh lg:overflow-hidden">
      {/* Menú */}
      <div className="flex-1 overflow-y-auto p-4 pb-40 lg:p-6 lg:pb-6">
        <h1 className="mb-4 text-2xl font-bold text-piedra-900">Nuevo pedido</h1>

        <DestinoPedido
          tipo={tipo}
          onTipo={setTipo}
          mesas={mesas}
          mesaId={mesaId}
          onMesa={setMesaId}
          direccion={direccion}
          onDireccion={setDireccion}
        />

        <input
          type="search"
          value={busqueda}
          onChange={(e) => setBusqueda(e.target.value)}
          placeholder="Buscar en el menú…"
          className="mb-3 min-h-12 w-full rounded-xl border border-piedra-300 px-4
                     focus:border-marca-500 focus:outline-none"
        />

        <div className="-mx-4 mb-4 flex gap-2 overflow-x-auto px-4 pb-1 lg:mx-0 lg:px-0">
          <Pestana activa={categoriaId === 'todas'} onClick={() => setCategoriaId('todas')}>
            Todo
          </Pestana>
          {categorias.map((c) => (
            <Pestana
              key={c.id}
              activa={categoriaId === c.id}
              onClick={() => setCategoriaId(c.id)}
            >
              {c.name}
            </Pestana>
          ))}
        </div>

        {isLoading ? (
          <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="h-24 animate-pulse rounded-2xl bg-piedra-200" />
            ))}
          </div>
        ) : visibles.length === 0 ? (
          <p className="rounded-2xl border border-dashed border-piedra-300 p-8 text-center
                        text-sm text-piedra-500">
            No hay productos que coincidan.
          </p>
        ) : (
          <ul className="grid grid-cols-2 gap-3 md:grid-cols-3">
            {visibles.map((p) => (
              <li key={p.id}>
                <BotonProducto producto={p} moneda={moneda} onClick={() => tocarProducto(p)} />
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* Carrito: panel fijo en escritorio, hoja inferior en móvil */}
      <aside
        className={`border-piedra-200 bg-white lg:flex lg:w-96 lg:flex-col lg:border-l
                    ${carritoAbierto ? 'fixed inset-0 z-40 flex flex-col' : 'hidden'}`}
      >
        <header className="flex items-center justify-between border-b border-piedra-200 px-5 py-4">
          <h2 className="font-bold text-piedra-900">
            Pedido {unidades > 0 && <span className="cifras text-piedra-500">({unidades})</span>}
          </h2>
          <button
            onClick={() => setCarritoAbierto(false)}
            className="text-sm font-medium text-piedra-500 lg:hidden"
          >
            Cerrar
          </button>
        </header>

        <div className="flex-1 overflow-y-auto px-4 py-3">
          {carrito.length === 0 ? (
            <p className="mt-8 text-center text-sm text-piedra-500">
              Toca un producto para empezar.
            </p>
          ) : (
            <ul className="flex flex-col gap-2">
              {carrito.map((linea) => (
                <li key={linea.clave} className="rounded-xl border border-piedra-200 p-3">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0 flex-1">
                      <p className="font-semibold text-piedra-900">{linea.producto.name}</p>
                      {linea.adicionales.length > 0 && (
                        <p className="text-xs text-piedra-500">
                          {linea.adicionales.map((a) => a.name).join(', ')}
                        </p>
                      )}
                      {linea.notas && (
                        <p className="mt-0.5 text-xs italic text-pendiente">
                          {linea.notas}
                        </p>
                      )}
                    </div>
                    <span className="cifras shrink-0 font-semibold text-piedra-900">
                      {dinero(precioLinea(linea), moneda)}
                    </span>
                  </div>

                  <div className="mt-2 flex items-center justify-between">
                    <Contador
                      valor={linea.cantidad}
                      onCambio={(v) =>
                        setCarrito((p) =>
                          p.map((l) => (l.clave === linea.clave ? { ...l, cantidad: v } : l)),
                        )
                      }
                    />
                    <button
                      onClick={() =>
                        setCarrito((p) => p.filter((l) => l.clave !== linea.clave))
                      }
                      className="px-2 py-1 text-sm font-medium text-cancelado"
                    >
                      Quitar
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>

        <footer className="border-t border-piedra-200 p-4">
          {error && (
            <p
              role="alert"
              className="mb-3 rounded-lg bg-cancelado-suave px-3 py-2 text-sm
                         text-cancelado"
            >
              {error}
            </p>
          )}

          <div className="mb-3 flex items-center justify-between">
            <span className="font-semibold text-piedra-700">Total</span>
            <span className="cifras text-2xl font-bold text-piedra-900">
              {dinero(total, moneda)}
            </span>
          </div>

          <button
            onClick={() => crear.mutate()}
            disabled={!listo || crear.isPending}
            className="min-h-14 w-full rounded-xl bg-marca-600 text-base font-semibold text-white
                       transition hover:bg-marca-700 disabled:opacity-40"
          >
            {crear.isPending ? 'Enviando…' : 'Enviar a cocina'}
          </button>

          {!listo && carrito.length > 0 && (
            <p className="mt-2 text-center text-xs text-piedra-500">
              {tipo === 'dine_in' ? 'Elige la mesa' : 'Escribe la dirección'} para continuar.
            </p>
          )}
        </footer>
      </aside>

      {/* Barra de resumen en móvil */}
      {!carritoAbierto && (
        <button
          onClick={() => setCarritoAbierto(true)}
          className="fixed inset-x-0 bottom-16 z-30 flex items-center justify-between
                     border-t border-piedra-200 bg-white px-5 py-3 lg:hidden"
        >
          <span className="text-sm font-medium text-piedra-600">
            {unidades === 0 ? 'Sin artículos' : `${unidades} artículo${unidades > 1 ? 's' : ''}`}
          </span>
          <span className="flex items-center gap-3">
            <span className="cifras text-lg font-bold text-piedra-900">
              {dinero(total, moneda)}
            </span>
            <span className="rounded-lg bg-marca-600 px-3 py-1.5 text-sm font-semibold text-white">
              Ver pedido
            </span>
          </span>
        </button>
      )}

      {configurando && (
        <HojaAdicionales
          producto={configurando}
          moneda={moneda}
          onCancelar={() => setConfigurando(null)}
          onAgregar={(adicionales, notas, cantidad) =>
            agregar(configurando, adicionales, notas, cantidad)
          }
        />
      )}
    </div>
  )
}

function DestinoPedido({
  tipo,
  onTipo,
  mesas,
  mesaId,
  onMesa,
  direccion,
  onDireccion,
}: {
  tipo: TipoPedido
  onTipo: (t: TipoPedido) => void
  mesas: { id: number; number: string; status: string }[]
  mesaId: number | null
  onMesa: (id: number | null) => void
  direccion: string
  onDireccion: (v: string) => void
}) {
  return (
    <div className="mb-4 rounded-2xl border border-piedra-200 bg-white p-3">
      <div className="mb-3 flex gap-2">
        {(['dine_in', 'counter', 'delivery'] as TipoPedido[]).map((t) => (
          <button
            key={t}
            onClick={() => onTipo(t)}
            className={`min-h-11 flex-1 rounded-xl text-sm font-semibold transition ${
              tipo === t
                ? 'bg-marca-600 text-white'
                : 'bg-piedra-100 text-piedra-600 hover:bg-piedra-200'
            }`}
          >
            {TIPOS[t]}
          </button>
        ))}
      </div>

      {tipo === 'dine_in' && (
        <select
          value={mesaId ?? ''}
          onChange={(e) => onMesa(e.target.value ? Number(e.target.value) : null)}
          className="min-h-12 w-full rounded-xl border border-piedra-300 px-3
                     focus:border-marca-500 focus:outline-none"
        >
          <option value="">Elige la mesa…</option>
          {mesas.map((m) => (
            <option key={m.id} value={m.id}>
              Mesa {m.number}
              {m.status === 'occupied' ? ' · ocupada' : ''}
            </option>
          ))}
        </select>
      )}

      {tipo === 'delivery' && (
        <input
          value={direccion}
          onChange={(e) => onDireccion(e.target.value)}
          placeholder="Dirección de entrega"
          className="min-h-12 w-full rounded-xl border border-piedra-300 px-3.5
                     focus:border-marca-500 focus:outline-none"
        />
      )}
    </div>
  )
}

function Pestana({
  activa,
  onClick,
  children,
}: {
  activa: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      onClick={onClick}
      className={`min-h-11 shrink-0 rounded-full px-4 text-sm font-semibold transition ${
        activa ? 'bg-piedra-900 text-white' : 'bg-piedra-100 text-piedra-600 hover:bg-piedra-200'
      }`}
    >
      {children}
    </button>
  )
}

function BotonProducto({
  producto,
  moneda,
  onClick,
}: {
  producto: Producto
  moneda: string
  onClick: () => void
}) {
  const agotado = !producto.is_available

  return (
    <button
      onClick={onClick}
      disabled={agotado}
      className={`flex min-h-24 w-full flex-col justify-between rounded-2xl border-2 bg-white
                  p-3 text-left transition ${
                    agotado
                      ? 'cursor-not-allowed border-piedra-200 opacity-50'
                      : 'border-piedra-200 hover:border-marca-400 hover:shadow-sm active:scale-[0.98]'
                  }`}
    >
      <span className="font-semibold leading-snug text-piedra-900">{producto.name}</span>
      <span className="mt-2 flex items-center justify-between">
        <span className="cifras font-semibold text-piedra-700">
          {dinero(producto.price, moneda)}
        </span>
        {agotado ? (
          <span className="text-xs font-semibold text-piedra-500">Agotado</span>
        ) : (
          (producto.additional_groups ?? []).length > 0 && (
            <span className="text-xs text-piedra-500">opciones</span>
          )
        )}
      </span>
    </button>
  )
}
