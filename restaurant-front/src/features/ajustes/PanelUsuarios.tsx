import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { interpretarError } from '../../api/client'
import { gestionAjustes, type Usuario } from '../../api/gestion'
import type { Rol } from '../../api/tipos'
import {
  AvisoError,
  BotonPrimario,
  BotonSecundario,
  CampoTexto,
  Hoja,
} from '../../components/ui'
import { useSesion } from '../auth/SesionContext'

const ROLES: { valor: Rol; texto: string; ayuda: string }[] = [
  { valor: 'admin', texto: 'Administrador', ayuda: 'Todo, incluidos reportes y ajustes' },
  { valor: 'waiter', texto: 'Mesero', ayuda: 'Mesas y pedidos' },
  { valor: 'kitchen', texto: 'Cocina', ayuda: 'Solo el tablero de cocina' },
  { valor: 'cashier', texto: 'Caja', ayuda: 'Pedidos, cobros y clientes' },
]

const NOMBRE_ROL: Record<Rol, string> = {
  admin: 'Administrador',
  waiter: 'Mesero',
  kitchen: 'Cocina',
  cashier: 'Caja',
}

export default function PanelUsuarios() {
  const { sesion } = useSesion()
  const clienteQuery = useQueryClient()
  const [editando, setEditando] = useState<Usuario | 'nuevo' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const usuarios = useQuery({ queryKey: ['usuarios'], queryFn: gestionAjustes.usuarios })

  const refrescar = () => clienteQuery.invalidateQueries({ queryKey: ['usuarios'] })

  const alternarActivo = useMutation({
    mutationFn: (u: Usuario) => gestionAjustes.guardarUsuario({ is_active: !u.is_active }, u.id),
    onSuccess: refrescar,
    onError: (e) => setError(interpretarError(e).mensaje),
  })

  const borrar = useMutation({
    mutationFn: gestionAjustes.borrarUsuario,
    onSuccess: refrescar,
    onError: (e) => setError(interpretarError(e).mensaje),
  })

  const lista = usuarios.data ?? []

  return (
    <div>
      <header className="mb-3 flex items-center justify-between gap-3">
        <div>
          <h2 className="font-semibold text-piedra-900">Personal</h2>
          <p className="text-sm text-piedra-500">
            Cada quien entra con su propia cuenta y ve solo lo que le toca.
          </p>
        </div>
        <BotonPrimario onClick={() => setEditando('nuevo')}>+ Persona</BotonPrimario>
      </header>

      {error && (
        <div className="mb-4">
          <AvisoError mensaje={error} />
        </div>
      )}

      {usuarios.isLoading ? (
        <div className="h-40 animate-pulse rounded-2xl bg-piedra-200" />
      ) : (
        <ul className="flex flex-col gap-2">
          {lista.map((u) => {
            const esUnoMismo = u.id === sesion?.usuario.id

            return (
              <li
                key={u.id}
                className={`flex flex-wrap items-center gap-3 rounded-xl border bg-white p-3.5
                            ${u.is_active ? 'border-piedra-200' : 'border-piedra-200 opacity-60'}`}
              >
                <div className="min-w-0 flex-1">
                  <p className="flex items-center gap-2 font-semibold text-piedra-900">
                    {u.name}
                    {esUnoMismo && (
                      <span className="rounded-full bg-marca-100 px-2 py-0.5 text-[11px]
                                       font-semibold text-marca-800">
                        Tú
                      </span>
                    )}
                    {!u.is_active && (
                      <span className="rounded-full bg-piedra-200 px-2 py-0.5 text-[11px]
                                       font-semibold text-piedra-600">
                        Desactivado
                      </span>
                    )}
                  </p>
                  <p className="truncate text-sm text-piedra-500">
                    {u.email} · {NOMBRE_ROL[u.role]}
                  </p>
                </div>

                <div className="flex shrink-0 gap-1">
                  <button
                    onClick={() => setEditando(u)}
                    className="rounded-lg px-3 py-2 text-sm font-medium text-piedra-600
                               hover:bg-piedra-100"
                  >
                    Editar
                  </button>

                  {/* Uno no puede desactivarse ni borrarse a sí mismo: se
                      quedaría fuera del panel. El backend también lo impide. */}
                  {!esUnoMismo && (
                    <>
                      <button
                        onClick={() => alternarActivo.mutate(u)}
                        className="rounded-lg px-3 py-2 text-sm font-medium text-piedra-600
                                   hover:bg-piedra-100"
                      >
                        {u.is_active ? 'Desactivar' : 'Activar'}
                      </button>
                      <button
                        onClick={() => {
                          if (confirm(`¿Eliminar a ${u.name}?`)) borrar.mutate(u.id)
                        }}
                        aria-label={`Eliminar a ${u.name}`}
                        className="rounded-lg px-3 py-2 text-sm font-medium text-piedra-400
                                   hover:bg-cancelado-suave hover:text-cancelado"
                      >
                        Eliminar
                      </button>
                    </>
                  )}
                </div>
              </li>
            )
          })}
        </ul>
      )}

      <p className="mt-3 text-xs text-piedra-400">
        Al desactivar a alguien se cierran sus sesiones abiertas al instante.
      </p>

      {editando && (
        <FormUsuario
          usuario={editando === 'nuevo' ? null : editando}
          onCerrar={() => setEditando(null)}
          onGuardado={() => {
            setEditando(null)
            refrescar()
          }}
        />
      )}
    </div>
  )
}

function FormUsuario({
  usuario,
  onCerrar,
  onGuardado,
}: {
  usuario: Usuario | null
  onCerrar: () => void
  onGuardado: () => void
}) {
  const [nombre, setNombre] = useState(usuario?.name ?? '')
  const [correo, setCorreo] = useState(usuario?.email ?? '')
  const [clave, setClave] = useState('')
  const [rol, setRol] = useState<Rol>(usuario?.role ?? 'waiter')
  const [error, setError] = useState<string | null>(null)

  const guardar = useMutation({
    mutationFn: () => {
      const datos: Record<string, unknown> = { name: nombre.trim(), email: correo.trim(), role: rol }

      // Al editar, sin contraseña en el formulario no se toca la actual.
      if (clave) datos.password = clave

      return gestionAjustes.guardarUsuario(datos, usuario?.id)
    },
    onSuccess: onGuardado,
    onError: (e) => {
      const fallo = interpretarError(e)
      setError(
        fallo.campos?.email?.[0] ?? fallo.campos?.password?.[0] ?? fallo.mensaje,
      )
    },
  })

  return (
    <Hoja titulo={usuario ? usuario.name : 'Nueva persona'} onCerrar={onCerrar}>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          setError(null)
          guardar.mutate()
        }}
        className="flex flex-col gap-4"
      >
        <CampoTexto etiqueta="Nombre" valor={nombre} onCambio={setNombre} autoFocus />
        <CampoTexto etiqueta="Correo" tipo="email" valor={correo} onCambio={setCorreo} />

        <CampoTexto
          etiqueta={usuario ? 'Nueva contraseña' : 'Contraseña'}
          tipo="password"
          ayuda={usuario ? 'Déjalo vacío para no cambiarla' : 'Mínimo 8 caracteres'}
          valor={clave}
          onCambio={setClave}
        />

        <fieldset>
          <legend className="mb-2 text-sm font-medium text-piedra-700">¿Qué hace?</legend>
          <div className="flex flex-col gap-1.5">
            {ROLES.map((r) => (
              <label
                key={r.valor}
                className={`flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border
                            px-3.5 transition ${
                              rol === r.valor
                                ? 'border-marca-500 bg-marca-50'
                                : 'border-piedra-200 hover:border-piedra-300'
                            }`}
              >
                <input
                  type="radio"
                  name="rol"
                  checked={rol === r.valor}
                  onChange={() => setRol(r.valor)}
                  className="h-5 w-5 accent-marca-600"
                />
                <span>
                  <span className="block text-sm font-semibold text-piedra-800">{r.texto}</span>
                  <span className="block text-xs text-piedra-500">{r.ayuda}</span>
                </span>
              </label>
            ))}
          </div>
        </fieldset>

        {error && <AvisoError mensaje={error} />}

        <div className="flex gap-2">
          <BotonSecundario type="button" onClick={onCerrar} className="flex-1">
            Cancelar
          </BotonSecundario>
          <BotonPrimario
            type="submit"
            disabled={!nombre.trim() || !correo.trim() || (!usuario && !clave) || guardar.isPending}
            className="flex-[2]"
          >
            {guardar.isPending ? 'Guardando…' : 'Guardar'}
          </BotonPrimario>
        </div>
      </form>
    </Hoja>
  )
}
