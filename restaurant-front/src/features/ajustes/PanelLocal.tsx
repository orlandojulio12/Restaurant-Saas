import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { interpretarError } from '../../api/client'
import { gestionAjustes, type Ajustes } from '../../api/gestion'
import { AvisoError, BotonPrimario, CampoTexto, claseEntrada } from '../../components/ui'
import { escalaDeMarca, textoSobre } from '../../lib/paleta'

/** Datos del local y preferencias de operación. */
export default function PanelLocal() {
  const clienteQuery = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['ajustes'], queryFn: gestionAjustes.leer })

  const [form, setForm] = useState<Record<string, string>>({})
  const [ajustes, setAjustes] = useState<Ajustes['settings'] | null>(null)
  const [logo, setLogo] = useState<File | null>(null)
  const [portada, setPortada] = useState<File | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [guardado, setGuardado] = useState(false)

  // El formulario se rellena cuando llegan los datos, no antes.
  useEffect(() => {
    if (!data) return

    setForm({
      name: data.restaurant.name ?? '',
      phone: data.restaurant.phone ?? '',
      whatsapp_number: data.restaurant.whatsapp_number ?? '',
      address: data.restaurant.address ?? '',
      city: data.restaurant.city ?? '',
      brand_color: data.restaurant.brand_color ?? '',
    })
    setAjustes(data.settings)
  }, [data])

  const guardar = useMutation({
    mutationFn: () =>
      gestionAjustes.guardar({ ...form, settings: ajustes ?? {} }, { logo, cover: portada }),
    onSuccess: () => {
      clienteQuery.invalidateQueries({ queryKey: ['ajustes'] })
      setLogo(null)
      setPortada(null)
      setGuardado(true)
      setTimeout(() => setGuardado(false), 2500)
    },
    onError: (e) => setError(interpretarError(e).mensaje),
  })

  if (isLoading || !ajustes) {
    return <div className="h-64 animate-pulse rounded-2xl bg-piedra-200" />
  }

  const campo = (k: string) => ({
    valor: form[k] ?? '',
    onCambio: (v: string) => setForm((f) => ({ ...f, [k]: v })),
  })

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        setError(null)
        guardar.mutate()
      }}
      className="flex max-w-2xl flex-col gap-4"
    >
      <section className="rounded-2xl border border-piedra-200 bg-white p-5">
        <h2 className="mb-4 font-semibold text-piedra-900">Datos del local</h2>

        <div className="flex flex-col gap-4">
          <CampoTexto etiqueta="Nombre" {...campo('name')} />

          <div className="grid gap-4 sm:grid-cols-2">
            <CampoTexto etiqueta="Teléfono" {...campo('phone')} />
            <CampoTexto
              etiqueta="WhatsApp"
              ayuda="Con indicativo, p. ej. 573001234567"
              {...campo('whatsapp_number')}
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <CampoTexto etiqueta="Dirección" {...campo('address')} />
            <CampoTexto etiqueta="Ciudad" {...campo('city')} />
          </div>

          <label className="flex flex-col gap-1.5">
            <span className="text-sm font-medium text-piedra-700">Logo</span>
            <div className="flex items-center gap-3">
              {data?.restaurant.logo_url && !logo && (
                <img
                  src={data.restaurant.logo_url}
                  alt=""
                  className="h-14 w-14 rounded-xl object-cover"
                />
              )}
              <input
                type="file"
                accept="image/*"
                onChange={(e) => setLogo(e.target.files?.[0] ?? null)}
                className="text-sm text-piedra-600 file:mr-3 file:rounded-lg file:border-0
                           file:bg-piedra-100 file:px-3 file:py-2 file:text-sm file:font-semibold"
              />
            </div>
          </label>
        </div>
      </section>

      <PanelMarca
        color={form.brand_color ?? ''}
        onColor={(v) => setForm((f) => ({ ...f, brand_color: v }))}
        nombre={form.name || data?.restaurant.name || 'Tu restaurante'}
        logoUrl={data?.restaurant.logo_url ?? null}
        portadaUrl={data?.restaurant.cover_url ?? null}
        portada={portada}
        onPortada={setPortada}
      />

      <section className="rounded-2xl border border-piedra-200 bg-white p-5">
        <h2 className="mb-1 font-semibold text-piedra-900">Cómo trabaja el local</h2>
        <p className="mb-4 text-sm text-piedra-500">
          Estas opciones cambian lo que ve el personal en su día a día.
        </p>

        <div className="flex flex-col gap-4">
          <label className="flex flex-col gap-1.5">
            <span className="text-sm font-medium text-piedra-700">Modo principal</span>
            <select
              value={ajustes.mode}
              onChange={(e) => setAjustes({ ...ajustes, mode: e.target.value })}
              className={claseEntrada}
            >
              <option value="tables">Mesas — se atiende en el local</option>
              <option value="counter">Mostrador — se pide y se lleva</option>
              <option value="delivery">Domicilios — se reparte</option>
            </select>
          </label>

          <CampoTexto
            etiqueta="Impuesto (%)"
            tipo="number"
            ayuda="0 si los precios ya lo incluyen"
            valor={String(ajustes.tax_percent)}
            onCambio={(v) => setAjustes({ ...ajustes, tax_percent: Number(v) })}
          />

          <Interruptor
            etiqueta="El mesero confirma los pedidos del QR"
            ayuda="Si lo apagas, lo que pida el cliente baja directo a cocina"
            activo={ajustes.qr_confirm}
            onCambio={(v) => setAjustes({ ...ajustes, qr_confirm: v })}
          />

          <Interruptor
            etiqueta="Imprimir comanda en cocina"
            activo={ajustes.print_kitchen}
            onCambio={(v) => setAjustes({ ...ajustes, print_kitchen: v })}
          />

          <Interruptor
            etiqueta="Sonido al entrar un pedido"
            ayuda="En el tablero de cocina"
            activo={ajustes.notify_sound}
            onCambio={(v) => setAjustes({ ...ajustes, notify_sound: v })}
          />
        </div>
      </section>

      {/* El slug no se toca desde aquí: es la URL del menú y está impresa en
          los QR de las mesas. */}
      <section className="rounded-2xl border border-piedra-200 bg-white p-5">
        <h2 className="mb-1 font-semibold text-piedra-900">Menú público</h2>
        <p className="mb-3 text-sm text-piedra-500">
          Esta es la dirección que abren tus clientes al escanear el QR de la mesa.
        </p>
        <code className="block rounded-lg bg-piedra-100 px-3 py-2 text-sm break-all text-piedra-700">
          /menu/{data?.restaurant.slug}
        </code>
        <p className="mt-2 text-xs text-piedra-500">
          No se puede cambiar: los QR ya impresos dejarían de funcionar.
        </p>
      </section>

      {error && <AvisoError mensaje={error} />}

      <div className="flex items-center gap-3">
        <BotonPrimario type="submit" disabled={guardar.isPending}>
          {guardar.isPending ? 'Guardando…' : 'Guardar cambios'}
        </BotonPrimario>

        {guardado && <span className="text-sm font-semibold text-listo">Guardado</span>}
      </div>
    </form>
  )
}

function Interruptor({
  etiqueta,
  ayuda,
  activo,
  onCambio,
}: {
  etiqueta: string
  ayuda?: string
  activo: boolean
  onCambio: (v: boolean) => void
}) {
  // La fila entera es el control, no solo el interruptor: encerrado en un
  // <label>, pulsar el texto no hacía nada y el objetivo real eran 28 px.
  return (
    <button
      type="button"
      role="switch"
      aria-checked={activo}
      onClick={() => onCambio(!activo)}
      className="flex min-h-13 w-full items-center justify-between gap-4 rounded-xl
                 px-1 text-left transition hover:bg-piedra-50"
    >
      <span>
        <span className="text-sm font-medium text-piedra-700">{etiqueta}</span>
        {ayuda && <span className="block text-xs text-piedra-500">{ayuda}</span>}
      </span>

      <span
        aria-hidden
        className={`relative h-7 w-12 shrink-0 rounded-full transition ${
          activo ? 'bg-marca-600' : 'bg-piedra-300'
        }`}
      >
        <span
          className={`absolute top-1 h-5 w-5 rounded-full bg-white transition-all ${
            activo ? 'left-6' : 'left-1'
          }`}
        />
      </span>
    </button>
  )
}

/**
 * La marca de la carta del QR.
 *
 * Es la única parte de los ajustes cuyo efecto no se ve en el panel, sino en el
 * móvil de un cliente que ya está sentado en una mesa. Por eso lleva la vista
 * previa al lado: sin ella, elegir un color aquí es dispararle a ciegas a la
 * única pantalla que ve quien paga.
 */
function PanelMarca({
  color,
  onColor,
  nombre,
  logoUrl,
  portadaUrl,
  portada,
  onPortada,
}: {
  color: string
  onColor: (v: string) => void
  nombre: string
  logoUrl: string | null
  portadaUrl: string | null
  portada: File | null
  onPortada: (f: File | null) => void
}) {
  const valido = /^#[0-9a-fA-F]{6}$/.test(color)
  const escala = valido ? escalaDeMarca(color) : null
  const tinta = valido ? textoSobre(color) : '#ffffff'

  // Atado al archivo y liberado al cambiarlo: crearlo en el render dejaba un
  // blob suelto por cada pulsación en el selector de color.
  const previaPortada = useMemo(
    () => (portada ? URL.createObjectURL(portada) : null),
    [portada],
  )

  useEffect(() => {
    return () => {
      if (previaPortada) URL.revokeObjectURL(previaPortada)
    }
  }, [previaPortada])

  return (
    <section className="rounded-2xl border border-piedra-200 bg-white p-5">
      <h2 className="mb-1 font-semibold text-piedra-900">La carta del QR</h2>
      <p className="mb-4 text-sm text-piedra-500">
        Lo que ve el cliente al escanear el código de su mesa. Tu color y tu logo mandan sobre toda
        la carta.
      </p>

      <div className="grid gap-5 sm:grid-cols-[1fr_auto]">
        <div className="flex flex-col gap-4">
          <label className="flex flex-col gap-1.5">
            <span className="text-sm font-medium text-piedra-700">Color de la marca</span>
            <div className="flex items-center gap-3">
              <input
                type="color"
                value={valido ? color : '#0f806b'}
                onChange={(e) => onColor(e.target.value.toUpperCase())}
                aria-label="Elegir color de la marca"
                className="h-11 w-14 shrink-0 cursor-pointer rounded-lg border border-piedra-300 bg-white p-1"
              />
              <input
                value={color}
                onChange={(e) => onColor(e.target.value.toUpperCase())}
                placeholder="#B4472A"
                maxLength={7}
                className={`cifras ${claseEntrada}`}
              />
              {color && (
                <button
                  type="button"
                  onClick={() => onColor('')}
                  className="shrink-0 text-sm font-medium text-piedra-500 underline"
                >
                  Quitar
                </button>
              )}
            </div>
            {color && !valido ? (
              <span className="text-sm text-cancelado">
                Escribe el color en formato #RRGGBB, por ejemplo #B4472A.
              </span>
            ) : (
              <span className="text-xs text-piedra-500">
                Sin color, la carta sale con la paleta por defecto.
              </span>
            )}
          </label>

          {escala && (
            <div>
              <span className="text-xs text-piedra-500">Escala derivada</span>
              <div className="mt-1.5 flex overflow-hidden rounded-lg">
                {Object.values(escala).map((c) => (
                  <span key={c} className="h-7 flex-1" style={{ background: c }} title={c} />
                ))}
              </div>
            </div>
          )}

          <label className="flex flex-col gap-1.5">
            <span className="text-sm font-medium text-piedra-700">
              Portada <span className="font-normal text-piedra-500">(opcional)</span>
            </span>
            <input
              type="file"
              accept="image/*"
              onChange={(e) => onPortada(e.target.files?.[0] ?? null)}
              className="text-sm text-piedra-600 file:mr-3 file:rounded-lg file:border-0
                         file:bg-piedra-100 file:px-3 file:py-2 file:text-sm file:font-semibold"
            />
            <span className="text-xs text-piedra-500">
              Una foto del local o de un plato, ancha. Va bajo la cabecera.
            </span>
          </label>
        </div>

        {/* La carta en miniatura, con lo que hay puesto ahora mismo. */}
        <div className="w-full shrink-0 overflow-hidden rounded-xl border border-piedra-200 sm:w-52">
          <div
            className="px-3 py-4"
            style={{ background: valido ? color : 'var(--color-marca-700)', color: tinta }}
          >
            <div className="flex items-center gap-2">
              {logoUrl && (
                <img src={logoUrl} alt="" className="h-8 w-8 rounded-lg bg-white/95 object-contain p-0.5" />
              )}
              <p className="truncate text-sm font-bold">{nombre}</p>
            </div>
            <p
              className="mt-2.5 inline-block rounded-full border px-2 py-0.5 text-[10px] font-semibold"
              style={{ borderColor: `color-mix(in srgb, ${tinta} 45%, transparent)` }}
            >
              MESA 5
            </p>
          </div>

          {(previaPortada || portadaUrl) && (
            <img
              src={previaPortada ?? (portadaUrl as string)}
              alt=""
              className="h-14 w-full object-cover"
            />
          )}

          <div className="bg-piedra-50 px-3 py-3">
            <div className="flex items-center gap-2">
              <span
                className="text-[9px] font-bold uppercase"
                style={{ letterSpacing: '0.18em', color: escala ? escala[800] : undefined }}
              >
                Entradas
              </span>
              <span className="h-px flex-1 bg-piedra-300" />
            </div>
            <div className="mt-2 flex items-center justify-between gap-2">
              <span className="text-[11px] font-semibold text-piedra-800">Patacón con hogao</span>
              <span className="cifras text-[11px] font-bold text-piedra-900">$ 8.000</span>
            </div>
          </div>

          <div
            className="px-3 py-2 text-center text-[11px] font-semibold"
            style={{ background: valido ? color : 'var(--color-marca-700)', color: tinta }}
          >
            Ver mi pedido
          </div>
        </div>
      </div>
    </section>
  )
}
