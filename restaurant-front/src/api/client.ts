import axios, { AxiosError } from 'axios'

/**
 * Cliente HTTP del panel.
 *
 * El backend deduce el restaurante del token, así que aquí nunca se envía un
 * restaurant_id: mandarlo sería, además de inútil, una puerta a confundirse de
 * inquilino.
 */
export const api = axios.create({
  baseURL: '/api',
  headers: { Accept: 'application/json' },
})

const CLAVE_TOKEN = 'rd_token'

export function guardarToken(token: string) {
  localStorage.setItem(CLAVE_TOKEN, token)
}

export function leerToken(): string | null {
  return localStorage.getItem(CLAVE_TOKEN)
}

export function borrarToken() {
  localStorage.removeItem(CLAVE_TOKEN)
}

api.interceptors.request.use((config) => {
  const token = leerToken()

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

/** Al frontend le sirve saber *por qué* falló, no solo que falló. */
export type FalloApi = {
  mensaje: string
  estado: number
  /** Errores por campo, tal como los devuelve la validación de Laravel. */
  campos?: Record<string, string[]>
  /** 403 por función de plan: el módulo no está incluido en el plan. */
  esPlan: boolean
  /** 403 por límite alcanzado (mesas, productos, pedidos del día). */
  esLimite: boolean
  limite?: { recurso?: string; limite?: number; actual?: number }
}

export function interpretarError(error: unknown): FalloApi {
  if (!(error instanceof AxiosError) || !error.response) {
    return {
      mensaje: 'No hay conexión con el servidor.',
      estado: 0,
      esPlan: false,
      esLimite: false,
    }
  }

  const { status, data } = error.response
  const cuerpo = (data ?? {}) as Record<string, unknown>

  return {
    mensaje: (cuerpo.message as string) ?? 'Ocurrió un error inesperado.',
    estado: status,
    campos: cuerpo.errors as Record<string, string[]> | undefined,
    esPlan: status === 403 && typeof cuerpo.feature === 'string',
    esLimite: status === 403 && typeof cuerpo.resource === 'string',
    limite: {
      recurso: cuerpo.resource as string | undefined,
      limite: cuerpo.limit as number | undefined,
      actual: cuerpo.current as number | undefined,
    },
  }
}

/**
 * Rutas que se abren sin sesión.
 *
 * El menú del QR lo abre un comensal en su móvil: mandarlo al login sería
 * mandarlo a una pantalla que no le corresponde. Y un token viejo guardado en
 * ese navegador —de alguien del personal que usó el mismo teléfono— provocaba
 * justo eso al arrancar la app.
 */
const RUTAS_PUBLICAS = ['/entrar', '/registro', '/m/']

function enRutaPublica(): boolean {
  return RUTAS_PUBLICAS.some((ruta) => window.location.pathname.startsWith(ruta))
}

// Sesión caducada o revocada: al panel no le sirve seguir intentándolo.
api.interceptors.response.use(
  (respuesta) => respuesta,
  (error: AxiosError) => {
    if (error.response?.status === 401 && !enRutaPublica()) {
      borrarToken()
      window.location.assign('/entrar?expirada=1')
    }

    return Promise.reject(error)
  },
)
