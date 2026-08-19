import { createContext, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { api, borrarToken, guardarToken, leerToken } from '../../api/client'
import type { Restaurante, Rol, Usuario } from '../../api/tipos'
import { configurarZona } from '../../lib/formato'

type Sesion = {
  usuario: Usuario
  restaurante: Restaurante
}

type ContextoSesion = {
  sesion: Sesion | null
  cargando: boolean
  entrar: (token: string, sesion: Sesion) => void
  salir: () => Promise<void>
  /** ¿El rol actual está entre los permitidos? */
  puede: (roles: Rol[]) => boolean
  /** ¿El plan incluye este módulo? */
  tienePlan: (modulo: 'whatsapp' | 'inventory' | 'reports' | 'financials') => boolean
}

const Contexto = createContext<ContextoSesion | null>(null)

export function ProveedorSesion({ children }: { children: ReactNode }) {
  const [sesion, setSesion] = useState<Sesion | null>(null)
  const [cargando, setCargando] = useState(true)

  // Al abrir la app se revalida el token guardado: puede haberse revocado
  // desde el panel (al desactivar al usuario) sin que este navegador lo sepa.
  useEffect(() => {
    const token = leerToken()

    if (!token) {
      setCargando(false)
      return
    }

    api
      .get('/auth/me')
      .then(({ data }) => {
        configurarZona(data.restaurant?.timezone)
        setSesion({ usuario: data.user, restaurante: data.restaurant })
      })
      .catch(() => borrarToken())
      .finally(() => setCargando(false))
  }, [])

  const valor = useMemo<ContextoSesion>(
    () => ({
      sesion,
      cargando,
      entrar: (token, nueva) => {
        guardarToken(token)
        configurarZona(nueva.restaurante.timezone)
        setSesion(nueva)
      },
      salir: async () => {
        try {
          await api.post('/auth/logout')
        } catch {
          // Da igual si falla: lo importante es soltar la sesión local.
        }
        borrarToken()
        setSesion(null)
      },
      puede: (roles) => (sesion ? roles.includes(sesion.usuario.role) : false),
      tienePlan: (modulo) => {
        const plan = sesion?.restaurante.plan
        if (!plan) return false

        return {
          whatsapp: plan.has_whatsapp,
          inventory: plan.has_inventory,
          reports: plan.has_reports,
          financials: plan.has_financials,
        }[modulo]
      },
    }),
    [sesion, cargando],
  )

  return <Contexto.Provider value={valor}>{children}</Contexto.Provider>
}

export function useSesion() {
  const contexto = useContext(Contexto)

  if (!contexto) {
    throw new Error('useSesion debe usarse dentro de <ProveedorSesion>.')
  }

  return contexto
}
