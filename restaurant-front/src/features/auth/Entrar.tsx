import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { api, interpretarError } from '../../api/client'
import { useSesion } from './SesionContext'

type RestauranteElegible = { id: number; name: string; slug: string }

export default function Entrar() {
  const { entrar } = useSesion()
  const navegar = useNavigate()
  const [params] = useSearchParams()

  const [correo, setCorreo] = useState('')
  const [clave, setClave] = useState('')
  const [enviando, setEnviando] = useState(false)
  const [error, setError] = useState<string | null>(
    params.get('expirada') ? 'Tu sesión expiró. Vuelve a entrar.' : null,
  )

  // El correo puede administrar varios restaurantes: el backend responde 409
  // con la lista y aquí se pide elegir.
  const [opciones, setOpciones] = useState<RestauranteElegible[] | null>(null)

  async function acceder(slug?: string) {
    setEnviando(true)
    setError(null)

    try {
      const { data } = await api.post('/auth/login', {
        email: correo,
        password: clave,
        ...(slug ? { restaurant_slug: slug } : {}),
      })

      entrar(data.token, { usuario: data.user, restaurante: data.restaurant })
      navegar('/', { replace: true })
    } catch (e) {
      const fallo = interpretarError(e)

      if (fallo.estado === 409) {
        const lista = (e as { response?: { data?: { restaurants?: RestauranteElegible[] } } })
          .response?.data?.restaurants

        setOpciones(lista ?? [])
      } else {
        setError(fallo.mensaje)
      }
    } finally {
      setEnviando(false)
    }
  }

  if (opciones) {
    return (
      <Marco titulo="¿A cuál entras?" bajada="Este correo administra varios restaurantes.">
        <ul className="flex flex-col gap-2">
          {opciones.map((r) => (
            <li key={r.id}>
              <button
                onClick={() => acceder(r.slug)}
                disabled={enviando}
                className="w-full rounded-xl border border-piedra-200 bg-white px-4 py-4 text-left
                           transition hover:border-marca-400 hover:bg-marca-50
                           disabled:opacity-50"
              >
                <span className="block font-semibold text-piedra-900">{r.name}</span>
                <span className="block text-sm text-piedra-500">{r.slug}</span>
              </button>
            </li>
          ))}
        </ul>

        <button
          onClick={() => setOpciones(null)}
          className="mt-4 text-sm font-medium text-piedra-500 underline underline-offset-4"
        >
          Volver
        </button>
      </Marco>
    )
  }

  return (
    <Marco titulo="Entrar" bajada="Gestiona tu restaurante.">
      <form
        onSubmit={(e) => {
          e.preventDefault()
          acceder()
        }}
        className="flex flex-col gap-4"
      >
        <Campo
          etiqueta="Correo"
          tipo="email"
          valor={correo}
          onCambio={setCorreo}
          autoComplete="username"
          autoFocus
        />
        <Campo
          etiqueta="Contraseña"
          tipo="password"
          valor={clave}
          onCambio={setClave}
          autoComplete="current-password"
        />

        {error && (
          <p
            role="alert"
            className="rounded-lg bg-[var(--color-cancelado-suave)] px-3 py-2 text-sm
                       text-[var(--color-cancelado)]"
          >
            {error}
          </p>
        )}

        <button
          type="submit"
          disabled={enviando || !correo || !clave}
          className="mt-2 min-h-12 rounded-xl bg-marca-600 px-4 font-semibold text-white
                     transition hover:bg-marca-700 disabled:opacity-40"
        >
          {enviando ? 'Entrando…' : 'Entrar'}
        </button>
      </form>

      <p className="mt-6 text-center text-sm text-piedra-500">
        ¿Aún no tienes cuenta?{' '}
        <Link to="/registro" className="font-semibold text-marca-700 underline underline-offset-4">
          Registra tu restaurante
        </Link>
      </p>
    </Marco>
  )
}

function Marco({
  titulo,
  bajada,
  children,
}: {
  titulo: string
  bajada: string
  children: React.ReactNode
}) {
  return (
    <div className="flex min-h-dvh items-center justify-center bg-piedra-100 p-4">
      <div className="w-full max-w-sm">
        <div className="mb-8 text-center">
          <div
            className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl
                       bg-marca-600 text-2xl"
            aria-hidden
          >
            🍽️
          </div>
          <h1 className="text-2xl font-bold text-piedra-900">{titulo}</h1>
          <p className="mt-1 text-sm text-piedra-500">{bajada}</p>
        </div>

        <div className="rounded-2xl border border-piedra-200 bg-white p-6 shadow-sm">{children}</div>
      </div>
    </div>
  )
}

function Campo({
  etiqueta,
  tipo,
  valor,
  onCambio,
  ...resto
}: {
  etiqueta: string
  tipo: string
  valor: string
  onCambio: (v: string) => void
} & React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-sm font-medium text-piedra-700">{etiqueta}</span>
      <input
        type={tipo}
        value={valor}
        onChange={(e) => onCambio(e.target.value)}
        className="min-h-12 rounded-xl border border-piedra-300 bg-white px-3.5
                   text-piedra-900 transition
                   focus:border-marca-500 focus:outline-none"
        {...resto}
      />
    </label>
  )
}
