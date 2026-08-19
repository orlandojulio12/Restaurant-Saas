import { useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { canal, conectar } from '../../lib/tiempoReal'

export type EstadoConexion = 'conectando' | 'conectado' | 'caido'

/**
 * Escucha el canal de cocina y refresca la lista cuando algo cambia.
 *
 * Se invalida la consulta en vez de aplicar el evento al estado local: el
 * payload del evento es un resumen, y en cocina prefiero una lista que siempre
 * refleje la base a una que se desincronice sin avisar.
 */
export function useCanalCocina(restauranteId: number | undefined, onNuevo?: () => void) {
  const clienteQuery = useQueryClient()
  const [conexion, setConexion] = useState<EstadoConexion>('conectando')
  const alNuevo = useRef(onNuevo)
  alNuevo.current = onNuevo

  useEffect(() => {
    if (!restauranteId) return

    const echo = conectar()
    const nombre = canal(restauranteId, 'kitchen')

    const refrescar = () => clienteQuery.invalidateQueries({ queryKey: ['pedidos', 'cocina'] })

    const suscripcion = echo
      .private(nombre)
      .listen('.order.created', () => {
        refrescar()
        alNuevo.current?.()
      })
      .listen('.order.status.updated', refrescar)

    // El estado del socket se muestra en pantalla: si la cocina deja de recibir
    // pedidos, tiene que enterarse en el momento, no cuando alguien reclame.
    const socket = echo.connector.pusher

    const marcar = (estado: EstadoConexion) => () => setConexion(estado)

    socket.connection.bind('connected', marcar('conectado'))
    socket.connection.bind('connecting', marcar('conectando'))
    socket.connection.bind('unavailable', marcar('caido'))
    socket.connection.bind('failed', marcar('caido'))
    socket.connection.bind('disconnected', marcar('caido'))

    if (socket.connection.state === 'connected') setConexion('conectado')

    return () => {
      suscripcion.stopListening('.order.created')
      suscripcion.stopListening('.order.status.updated')
      echo.leave(nombre)
    }
  }, [restauranteId, clienteQuery])

  return conexion
}

/**
 * Aviso sonoro para pedidos nuevos.
 *
 * Se sintetiza con WebAudio en lugar de cargar un archivo: son dos tonos, y así
 * no depende de que un mp3 esté servido ni de la caché.
 */
export function useAvisoSonoro() {
  const contexto = useRef<AudioContext | null>(null)

  return () => {
    try {
      contexto.current ??= new AudioContext()
      const ctx = contexto.current

      // Los navegadores suspenden el audio hasta que alguien interactúa.
      if (ctx.state === 'suspended') void ctx.resume()

      const ahora = ctx.currentTime

      for (const [i, frecuencia] of [880, 1320].entries()) {
        const osc = ctx.createOscillator()
        const vol = ctx.createGain()

        osc.frequency.value = frecuencia
        osc.type = 'sine'
        vol.gain.setValueAtTime(0.0001, ahora + i * 0.18)
        vol.gain.exponentialRampToValueAtTime(0.25, ahora + i * 0.18 + 0.02)
        vol.gain.exponentialRampToValueAtTime(0.0001, ahora + i * 0.18 + 0.16)

        osc.connect(vol).connect(ctx.destination)
        osc.start(ahora + i * 0.18)
        osc.stop(ahora + i * 0.18 + 0.18)
      }
    } catch {
      // Sin audio disponible el tablero sigue funcionando igual.
    }
  }
}
