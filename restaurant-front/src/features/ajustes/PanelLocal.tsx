import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { interpretarError } from '../../api/client'
import { gestionAjustes, type Ajustes } from '../../api/gestion'
import { AvisoError, BotonPrimario, CampoTexto, claseEntrada } from '../../components/ui'

/** Datos del local y preferencias de operación. */
export default function PanelLocal() {
  const clienteQuery = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['ajustes'], queryFn: gestionAjustes.leer })

  const [form, setForm] = useState<Record<string, string>>({})
  const [ajustes, setAjustes] = useState<Ajustes['settings'] | null>(null)
  const [logo, setLogo] = useState<File | null>(null)
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
    })
    setAjustes(data.settings)
  }, [data])

  const guardar = useMutation({
    mutationFn: () => gestionAjustes.guardar({ ...form, settings: ajustes ?? {} }, logo),
    onSuccess: () => {
      clienteQuery.invalidateQueries({ queryKey: ['ajustes'] })
      setLogo(null)
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
        <p className="mt-2 text-xs text-piedra-400">
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
        {ayuda && <span className="block text-xs text-piedra-400">{ayuda}</span>}
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
