import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, interpretarError } from '../../api/client'
import type { Paginado } from '../../api/tipos'
import {
  AvisoError,
  BotonPrimario,
  BotonSecundario,
  CampoTexto,
  Hoja,
  Vacio,
} from '../../components/ui'
import { dinero } from '../../lib/formato'
import { useSesion } from '../auth/SesionContext'

type Cliente = {
  id: number
  name: string | null
  phone: string | null
  address: string | null
  notes: string | null
  total_orders: number
  total_spent: string
  last_order_at: string | null
}

const clientesApi = {
  listar: async (params: { search?: string; sort?: string; page?: number }) =>
    (await api.get<Paginado<Cliente>>('/customers', { params })).data,

  guardar: async (datos: Record<string, unknown>, id?: number) =>
    id
      ? (await api.put<Cliente>(`/customers/${id}`, datos)).data
      : (await api.post<Cliente>('/customers', datos)).data,

  borrar: async (id: number, forzar = false) =>
    api.delete(`/customers/${id}`, { params: forzar ? { force: true } : {} }),
}

/**
 * Clientes.
 *
 * Se llenan solos al cobrar un domicilio, así que esta pantalla se usa sobre
 * todo para buscar a alguien por teléfono cuando vuelve a pedir. Por eso la
 * búsqueda va arriba y el orden por defecto es quién pidió más recientemente.
 */
export default function Clientes() {
  const { sesion } = useSesion()
  const moneda = sesion?.restaurante.currency ?? 'COP'
  const clienteQuery = useQueryClient()

  const [busqueda, setBusqueda] = useState('')
  const [orden, setOrden] = useState<'recent' | 'top' | 'name'>('recent')
  const [pagina, setPagina] = useState(1)
  const [editando, setEditando] = useState<Cliente | 'nuevo' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['clientes', busqueda, orden, pagina],
    queryFn: () => clientesApi.listar({ search: busqueda || undefined, sort: orden, page: pagina }),
  })

  const borrar = useMutation({
    mutationFn: ({ id, forzar }: { id: number; forzar: boolean }) =>
      clientesApi.borrar(id, forzar),
    onSuccess: () => clienteQuery.invalidateQueries({ queryKey: ['clientes'] }),
    onError: (e) => setError(interpretarError(e).mensaje),
  })

  const clientes = data?.data ?? []

  return (
    <div className="p-4 lg:p-6">
      <header className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-piedra-900">Clientes</h1>
          <p className="cifras text-sm text-piedra-500">
            {isLoading ? 'Cargando…' : `${data?.total ?? 0} registrados`}
          </p>
        </div>
        <BotonPrimario onClick={() => setEditando('nuevo')}>+ Cliente</BotonPrimario>
      </header>

      <div className="mb-4 flex flex-wrap gap-2">
        <input
          type="search"
          value={busqueda}
          onChange={(e) => {
            setBusqueda(e.target.value)
            setPagina(1)
          }}
          placeholder="Buscar por nombre o teléfono…"
          className="min-h-12 min-w-52 flex-1 rounded-xl border border-piedra-300 px-4
                     focus:border-marca-500 focus:outline-none"
        />

        <div className="flex gap-2">
          {(
            [
              { valor: 'recent', texto: 'Recientes' },
              { valor: 'top', texto: 'Los que más gastan' },
              { valor: 'name', texto: 'A–Z' },
            ] as const
          ).map((o) => (
            <button
              key={o.valor}
              onClick={() => {
                setOrden(o.valor)
                setPagina(1)
              }}
              className={`min-h-12 shrink-0 rounded-xl px-3.5 text-sm font-semibold transition ${
                orden === o.valor
                  ? 'bg-piedra-900 text-white'
                  : 'bg-white text-piedra-600 ring-1 ring-piedra-200 hover:ring-piedra-300'
              }`}
            >
              {o.texto}
            </button>
          ))}
        </div>
      </div>

      {error && (
        <div className="mb-4">
          <AvisoError mensaje={error} />
        </div>
      )}

      {isLoading ? (
        <ul className="flex flex-col gap-2">
          {Array.from({ length: 5 }).map((_, i) => (
            <li key={i} className="h-20 animate-pulse rounded-2xl bg-piedra-200" />
          ))}
        </ul>
      ) : clientes.length === 0 ? (
        <Vacio
          titulo={busqueda ? 'Nadie coincide' : 'Todavía no hay clientes'}
          texto={
            busqueda
              ? 'Prueba con otro nombre o teléfono.'
              : 'Se van registrando solos al tomar pedidos a domicilio, o puedes añadirlos a mano.'
          }
        />
      ) : (
        <ul className="flex flex-col gap-2">
          {clientes.map((c) => (
            <li
              key={c.id}
              className="flex flex-wrap items-center gap-3 rounded-2xl border border-piedra-200
                         bg-white p-4"
            >
              <div className="min-w-0 flex-1">
                <p className="font-semibold text-piedra-900">{c.name || 'Sin nombre'}</p>
                <p className="cifras truncate text-sm text-piedra-500">
                  {c.phone || 'Sin teléfono'}
                  {c.address ? ` · ${c.address}` : ''}
                </p>
              </div>

              <div className="shrink-0 text-right">
                <p className="cifras font-semibold text-piedra-900">
                  {dinero(c.total_spent, moneda)}
                </p>
                <p className="cifras text-xs text-piedra-500">
                  {c.total_orders} pedido{c.total_orders === 1 ? '' : 's'}
                </p>
              </div>

              <div className="flex shrink-0 gap-1">
                <button
                  onClick={() => setEditando(c)}
                  className="min-h-11 rounded-lg px-3 text-sm font-medium text-piedra-600
                             hover:bg-piedra-100"
                >
                  Editar
                </button>
                <button
                  onClick={() => {
                    const conPedidos = c.total_orders > 0
                    const texto = conPedidos
                      ? `${c.name || 'Este cliente'} tiene ${c.total_orders} pedido(s). Si lo eliminas, quedarán sin cliente asociado. ¿Continuar?`
                      : `¿Eliminar a ${c.name || 'este cliente'}?`

                    if (confirm(texto)) borrar.mutate({ id: c.id, forzar: conPedidos })
                  }}
                  aria-label={`Eliminar a ${c.name || 'cliente'}`}
                  className="min-h-11 rounded-lg px-3 text-sm font-medium text-piedra-500
                             hover:bg-cancelado-suave hover:text-cancelado"
                >
                  Eliminar
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {data && data.last_page > 1 && (
        <nav className="mt-5 flex items-center justify-center gap-3">
          <BotonSecundario onClick={() => setPagina((p) => Math.max(1, p - 1))} disabled={pagina <= 1}>
            Anterior
          </BotonSecundario>
          <span className="cifras text-sm text-piedra-500">
            {data.current_page} de {data.last_page}
          </span>
          <BotonSecundario
            onClick={() => setPagina((p) => Math.min(data.last_page, p + 1))}
            disabled={pagina >= data.last_page}
          >
            Siguiente
          </BotonSecundario>
        </nav>
      )}

      {editando && (
        <FormCliente
          cliente={editando === 'nuevo' ? null : editando}
          onCerrar={() => setEditando(null)}
          onGuardado={() => {
            setEditando(null)
            clienteQuery.invalidateQueries({ queryKey: ['clientes'] })
          }}
        />
      )}
    </div>
  )
}

function FormCliente({
  cliente,
  onCerrar,
  onGuardado,
}: {
  cliente: Cliente | null
  onCerrar: () => void
  onGuardado: () => void
}) {
  const [nombre, setNombre] = useState(cliente?.name ?? '')
  const [telefono, setTelefono] = useState(cliente?.phone ?? '')
  const [direccion, setDireccion] = useState(cliente?.address ?? '')
  const [notas, setNotas] = useState(cliente?.notes ?? '')
  const [error, setError] = useState<string | null>(null)

  const guardar = useMutation({
    mutationFn: () =>
      clientesApi.guardar(
        {
          name: nombre.trim() || null,
          phone: telefono.trim() || null,
          address: direccion.trim() || null,
          notes: notas.trim() || null,
        },
        cliente?.id,
      ),
    onSuccess: onGuardado,
    onError: (e) => {
      const fallo = interpretarError(e)
      setError(fallo.campos?.phone?.[0] ?? fallo.mensaje)
    },
  })

  return (
    <Hoja titulo={cliente ? cliente.name || 'Cliente' : 'Nuevo cliente'} onCerrar={onCerrar}>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          setError(null)
          guardar.mutate()
        }}
        className="flex flex-col gap-4"
      >
        <CampoTexto etiqueta="Nombre" valor={nombre} onCambio={setNombre} autoFocus />
        <CampoTexto
          etiqueta="Teléfono"
          ayuda="Es como se le encuentra cuando vuelve a pedir"
          valor={telefono}
          onCambio={setTelefono}
        />
        <CampoTexto etiqueta="Dirección" valor={direccion} onCambio={setDireccion} />

        <label className="flex flex-col gap-1.5">
          <span className="text-sm font-medium text-piedra-700">
            Notas <span className="font-normal text-piedra-500">(opcional)</span>
          </span>
          <textarea
            value={notas}
            onChange={(e) => setNotas(e.target.value)}
            rows={2}
            placeholder="Apartamento 302, timbre dañado…"
            className="rounded-xl border border-piedra-300 px-3.5 py-2.5 text-piedra-900
                       focus:border-marca-500 focus:outline-none"
          />
        </label>

        {cliente && (
          <p className="rounded-lg bg-piedra-100 px-3 py-2 text-xs text-piedra-500">
            Lo gastado y el número de pedidos se calculan solos al cobrar. No se editan a mano.
          </p>
        )}

        {error && <AvisoError mensaje={error} />}

        <div className="flex gap-2">
          <BotonSecundario type="button" onClick={onCerrar} className="flex-1">
            Cancelar
          </BotonSecundario>
          <BotonPrimario type="submit" disabled={guardar.isPending} className="flex-[2]">
            {guardar.isPending ? 'Guardando…' : 'Guardar'}
          </BotonPrimario>
        </div>
      </form>
    </Hoja>
  )
}
