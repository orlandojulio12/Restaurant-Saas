import { useEffect, type ReactNode } from 'react'

/**
 * Piezas compartidas de los paneles de gestión.
 *
 * No es una librería de componentes: son las cuatro cosas que se repiten en
 * menú e inventario, extraídas para que ambos paneles se comporten igual.
 */

/** Hoja modal: centrada en escritorio, pegada abajo en móvil. */
export function Hoja({
  titulo,
  bajada,
  onCerrar,
  children,
  pie,
}: {
  titulo: string
  bajada?: string
  onCerrar: () => void
  children: ReactNode
  pie?: ReactNode
}) {
  // Escape cierra: en un panel de gestión se abren y cierran formularios todo
  // el rato y alcanzar el ratón hasta "Cancelar" cansa.
  useEffect(() => {
    const alPulsar = (e: KeyboardEvent) => e.key === 'Escape' && onCerrar()
    window.addEventListener('keydown', alPulsar)
    return () => window.removeEventListener('keydown', alPulsar)
  }, [onCerrar])

  return (
    <div
      className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center"
      onClick={(e) => e.target === e.currentTarget && onCerrar()}
    >
      <div
        role="dialog"
        aria-label={titulo}
        className="flex max-h-[92dvh] w-full max-w-lg flex-col rounded-t-2xl bg-white sm:rounded-2xl"
      >
        <header className="border-b border-piedra-200 px-5 py-4">
          <h2 className="text-lg font-bold text-piedra-900">{titulo}</h2>
          {bajada && <p className="text-sm text-piedra-500">{bajada}</p>}
        </header>

        <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>

        {pie && <footer className="border-t border-piedra-200 p-4">{pie}</footer>}
      </div>
    </div>
  )
}

export function Campo({
  etiqueta,
  ayuda,
  error,
  children,
}: {
  etiqueta: string
  ayuda?: string
  error?: string
  children: ReactNode
}) {
  return (
    <label className="mb-4 flex flex-col gap-1.5">
      <span className="text-sm font-semibold text-piedra-800">
        {etiqueta}
        {ayuda && <span className="ml-1 font-normal text-piedra-400">{ayuda}</span>}
      </span>
      {children}
      {error && <span className="text-sm text-cancelado">{error}</span>}
    </label>
  )
}

export const claseEntrada =
  'min-h-12 w-full rounded-xl border border-piedra-300 px-3.5 text-piedra-900 ' +
  'focus:border-marca-500 focus:outline-none'

export function Pestanas<T extends string>({
  actual,
  opciones,
  onCambio,
}: {
  actual: T
  opciones: { valor: T; texto: string; contador?: number }[]
  onCambio: (v: T) => void
}) {
  return (
    <div className="mb-5 flex gap-2 overflow-x-auto pb-1" role="tablist">
      {opciones.map((o) => (
        <button
          key={o.valor}
          role="tab"
          aria-selected={actual === o.valor}
          onClick={() => onCambio(o.valor)}
          className={`min-h-11 shrink-0 rounded-xl px-4 text-sm font-semibold transition ${
            actual === o.valor
              ? 'bg-piedra-900 text-white'
              : 'bg-white text-piedra-600 ring-1 ring-piedra-200 hover:ring-piedra-300'
          }`}
        >
          {o.texto}
          {o.contador !== undefined && (
            <span
              className={`cifras ml-2 rounded-full px-1.5 py-0.5 text-xs ${
                actual === o.valor ? 'bg-white/20' : 'bg-piedra-100'
              }`}
            >
              {o.contador}
            </span>
          )}
        </button>
      ))}
    </div>
  )
}

export function Vacio({
  titulo,
  texto,
  accion,
}: {
  titulo: string
  texto: string
  accion?: { texto: string; onClick: () => void }
}) {
  return (
    <div className="rounded-2xl border border-dashed border-piedra-300 p-10 text-center">
      <p className="font-semibold text-piedra-800">{titulo}</p>
      <p className="mx-auto mt-1 max-w-sm text-sm text-piedra-500">{texto}</p>
      {accion && (
        <button
          onClick={accion.onClick}
          className="mt-4 min-h-11 rounded-xl bg-marca-600 px-4 font-semibold text-white
                     transition hover:bg-marca-700"
        >
          {accion.texto}
        </button>
      )}
    </div>
  )
}

/**
 * Aviso de error de la API.
 *
 * Distingue el límite de plan del resto: no es un fallo del usuario sino un
 * tope de su suscripción, y decirlo con la misma cara que un error de
 * validación confunde.
 */
export function AvisoError({
  mensaje,
  esLimite,
}: {
  mensaje: string
  esLimite?: boolean
}) {
  return (
    <p
      role="alert"
      className={`rounded-xl px-3.5 py-2.5 text-sm font-medium ${
        esLimite ? 'bg-pendiente-suave text-pendiente' : 'bg-cancelado-suave text-cancelado'
      }`}
    >
      {mensaje}
    </p>
  )
}

export function BotonPrimario({
  children,
  ...resto
}: React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      {...resto}
      className={`min-h-12 rounded-xl bg-marca-600 px-4 font-semibold text-white transition
                  hover:bg-marca-700 disabled:opacity-40 ${resto.className ?? ''}`}
    >
      {children}
    </button>
  )
}

export function BotonSecundario({
  children,
  ...resto
}: React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      {...resto}
      className={`min-h-12 rounded-xl border border-piedra-300 px-4 font-semibold
                  text-piedra-700 transition hover:bg-piedra-100 disabled:opacity-40
                  ${resto.className ?? ''}`}
    >
      {children}
    </button>
  )
}
