import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { leerToken } from '../api/client'

/**
 * Conexión a Reverb.
 *
 * Los canales son privados y por restaurante (`restaurant.{id}.{ámbito}`), y el
 * backend firma la suscripción en /api/broadcasting/auth comprobando que el
 * token pertenece a ese restaurante.
 *
 * Aviso operativo: los eventos van por cola. Sin `php artisan queue:work` vivo
 * no llega nada y no hay error visible — los jobs se acumulan en la tabla.
 */

declare global {
  interface Window {
    Pusher: typeof Pusher
    Echo?: Echo<'reverb'>
  }
}

let echo: Echo<'reverb'> | null = null

export function conectar(): Echo<'reverb'> {
  if (echo) return echo

  window.Pusher = Pusher

  echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? 'restaurant-key-local',
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/api/broadcasting/auth',
    auth: {
      headers: {
        Authorization: `Bearer ${leerToken() ?? ''}`,
        Accept: 'application/json',
      },
    },
  })

  window.Echo = echo

  return echo
}

export function desconectar() {
  echo?.disconnect()
  echo = null
  window.Echo = undefined
}

/** Nombre del canal privado de un ámbito del restaurante. */
export function canal(restauranteId: number, ambito: 'kitchen' | 'waiters' | 'tables' | 'admin') {
  return `restaurant.${restauranteId}.${ambito}`
}
