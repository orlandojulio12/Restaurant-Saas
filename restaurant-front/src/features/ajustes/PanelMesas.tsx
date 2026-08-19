import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { interpretarError } from '../../api/client'
import { gestionAjustes } from '../../api/gestion'
import type { Mesa, Zona } from '../../api/tipos'
import {
  AvisoError,
  BotonPrimario,
  BotonSecundario,
  CampoTexto,
  claseEntrada,
  Hoja,
  Vacio,
} from '../../components/ui'

/** Mesas y zonas: el plano físico del local. */
export default function PanelMesas() {
  const clienteQuery = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const [editandoMesa, setEditandoMesa] = useState<Mesa | 'nueva' | null>(null)
  const [editandoZona, setEditandoZona] = useState<Zona | 'nueva' | null>(null)

  const mesas = useQuery({ queryKey: ['mesas'], queryFn: gestionAjustes.mesas })
  const zonas = useQuery({ queryKey: ['zonas'], queryFn: gestionAjustes.zonas })

  const refrescar = () => {
    clienteQuery.invalidateQueries({ queryKey: ['mesas'] })
    clienteQuery.invalidateQueries({ queryKey: ['zonas'] })
  }

  const borrarMesa = useMutation({
    mutationFn: gestionAjustes.borrarMesa,
    onSuccess: refrescar,
    onError: (e) => setError(interpretarError(e).mensaje),
  })

  const borrarZona = useMutation({
    mutationFn: ({ id, forzar }: { id: number; forzar: boolean }) =>
      gestionAjustes.borrarZona(id, forzar),
    onSuccess: refrescar,
    onError: (e) => setError(interpretarError(e).mensaje),
  })

  const listaMesas = mesas.data ?? []
  const listaZonas = zonas.data ?? []

  return (
    <div className="flex flex-col gap-6">
      {error && <AvisoError mensaje={error} />}

      <section>
        <header className="mb-3 flex items-center justify-between gap-3">
          <div>
            <h2 className="font-semibold text-piedra-900">Mesas</h2>
            <p className="text-sm text-piedra-500">
              Cada una lleva su propio QR para que el cliente pida desde el móvil.
            </p>
          </div>
          <BotonPrimario onClick={() => setEditandoMesa('nueva')}>+ Mesa</BotonPrimario>
        </header>

        {mesas.isLoading ? (
          <div className="h-32 animate-pulse rounded-2xl bg-piedra-200" />
        ) : listaMesas.length === 0 ? (
          <Vacio
            titulo="Todavía no hay mesas"
            texto="Créalas con el mismo número que tienen en el local."
          />
        ) : (
          <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
            {listaMesas.map((mesa) => (
              <li
                key={mesa.id}
                className="flex items-center justify-between gap-2 rounded-xl border
                           border-piedra-200 bg-white p-3"
              >
                <div className="min-w-0">
                  <p className="font-bold text-piedra-900">{mesa.number}</p>
                  <p className="cifras truncate text-xs text-piedra-500">
                    {mesa.capacity} puestos
                    {mesa.zone?.name ? ` · ${mesa.zone.name}` : ''}
                  </p>
                </div>

                <div className="flex shrink-0 gap-1">
                  <button
                    onClick={() => setEditandoMesa(mesa)}
                    aria-label={`Editar mesa ${mesa.number}`}
                    className="flex h-9 w-9 items-center justify-center rounded-lg
                               text-piedra-500 hover:bg-piedra-100"
                  >
                    ✎
                  </button>
                  <button
                    onClick={() => {
                      if (confirm(`¿Eliminar la mesa ${mesa.number}?`)) borrarMesa.mutate(mesa.id)
                    }}
                    aria-label={`Eliminar mesa ${mesa.number}`}
                    className="flex h-9 w-9 items-center justify-center rounded-lg
                               text-piedra-400 hover:bg-cancelado-suave hover:text-cancelado"
                  >
                    ✕
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section>
        <header className="mb-3 flex items-center justify-between gap-3">
          <div>
            <h2 className="font-semibold text-piedra-900">Zonas</h2>
            <p className="text-sm text-piedra-500">
              Salón, terraza, segundo piso. Sirven para agrupar el plano de mesas.
            </p>
          </div>
          <BotonSecundario onClick={() => setEditandoZona('nueva')}>+ Zona</BotonSecundario>
        </header>

        {listaZonas.length === 0 ? (
          <p className="rounded-xl border border-dashed border-piedra-300 p-6 text-center text-sm
                        text-piedra-500">
            Sin zonas. Si tu local es de una sola sala, puedes dejarlo así.
          </p>
        ) : (
          <ul className="flex flex-col gap-2">
            {listaZonas.map((zona) => (
              <li
                key={zona.id}
                className="flex items-center justify-between gap-3 rounded-xl border
                           border-piedra-200 bg-white p-3.5"
              >
                <div>
                  <p className="font-semibold text-piedra-900">{zona.name}</p>
                  <p className="cifras text-xs text-piedra-500">
                    {zona.tables_count ?? 0} mesa{zona.tables_count === 1 ? '' : 's'}
                  </p>
                </div>

                <div className="flex gap-1">
                  <button
                    onClick={() => setEditandoZona(zona)}
                    aria-label={`Editar zona ${zona.name}`}
                    className="flex h-9 w-9 items-center justify-center rounded-lg
                               text-piedra-500 hover:bg-piedra-100"
                  >
                    ✎
                  </button>
                  <button
                    onClick={() => {
                      // El backend pide confirmación explícita si la zona tiene
                      // mesas: se le pregunta al usuario y se reintenta.
                      const conMesas = (zona.tables_count ?? 0) > 0
                      const texto = conMesas
                        ? `"${zona.name}" tiene ${zona.tables_count} mesa(s). Si la eliminas quedarán sin zona. ¿Continuar?`
                        : `¿Eliminar la zona "${zona.name}"?`

                      if (confirm(texto)) borrarZona.mutate({ id: zona.id, forzar: conMesas })
                    }}
                    aria-label={`Eliminar zona ${zona.name}`}
                    className="flex h-9 w-9 items-center justify-center rounded-lg
                               text-piedra-400 hover:bg-cancelado-suave hover:text-cancelado"
                  >
                    ✕
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      {editandoMesa && (
        <FormMesa
          mesa={editandoMesa === 'nueva' ? null : editandoMesa}
          zonas={listaZonas}
          onCerrar={() => setEditandoMesa(null)}
          onGuardado={() => {
            setEditandoMesa(null)
            refrescar()
          }}
        />
      )}

      {editandoZona && (
        <FormZona
          zona={editandoZona === 'nueva' ? null : editandoZona}
          onCerrar={() => setEditandoZona(null)}
          onGuardado={() => {
            setEditandoZona(null)
            refrescar()
          }}
        />
      )}
    </div>
  )
}

function FormMesa({
  mesa,
  zonas,
  onCerrar,
  onGuardado,
}: {
  mesa: Mesa | null
  zonas: Zona[]
  onCerrar: () => void
  onGuardado: () => void
}) {
  const [numero, setNumero] = useState(mesa?.number ?? '')
  const [capacidad, setCapacidad] = useState(String(mesa?.capacity ?? 4))
  const [zonaId, setZonaId] = useState<string>(mesa?.zone?.id ? String(mesa.zone.id) : '')
  const [error, setError] = useState<string | null>(null)

  const guardar = useMutation({
    mutationFn: () =>
      gestionAjustes.guardarMesa(
        {
          number: numero.trim(),
          capacity: Number(capacidad) || 4,
          zone_id: zonaId ? Number(zonaId) : null,
        },
        mesa?.id,
      ),
    onSuccess: onGuardado,
    onError: (e) => {
      const fallo = interpretarError(e)
      setError(
        fallo.campos?.number?.[0] ??
          (fallo.esLimite
            ? `Tu plan permite ${fallo.limite?.limite} mesas y ya tienes ${fallo.limite?.actual}.`
            : fallo.mensaje),
      )
    },
  })

  return (
    <Hoja titulo={mesa ? `Mesa ${mesa.number}` : 'Nueva mesa'} onCerrar={onCerrar}>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          setError(null)
          guardar.mutate()
        }}
        className="flex flex-col gap-4"
      >
        <CampoTexto
          etiqueta="Número o nombre"
          ayuda='Admite texto: "12", "A1", "Terraza-3"'
          valor={numero}
          onCambio={setNumero}
          autoFocus
        />

        <CampoTexto etiqueta="Puestos" tipo="number" valor={capacidad} onCambio={setCapacidad} />

        {zonas.length > 0 && (
          <label className="flex flex-col gap-1.5">
            <span className="text-sm font-medium text-piedra-700">Zona</span>
            <select
              value={zonaId}
              onChange={(e) => setZonaId(e.target.value)}
              className={claseEntrada}
            >
              <option value="">Sin zona</option>
              {zonas.map((z) => (
                <option key={z.id} value={z.id}>
                  {z.name}
                </option>
              ))}
            </select>
          </label>
        )}

        {error && <AvisoError mensaje={error} />}

        <div className="flex gap-2">
          <BotonSecundario type="button" onClick={onCerrar} className="flex-1">
            Cancelar
          </BotonSecundario>
          <BotonPrimario
            type="submit"
            disabled={!numero.trim() || guardar.isPending}
            className="flex-[2]"
          >
            {guardar.isPending ? 'Guardando…' : 'Guardar'}
          </BotonPrimario>
        </div>
      </form>
    </Hoja>
  )
}

function FormZona({
  zona,
  onCerrar,
  onGuardado,
}: {
  zona: Zona | null
  onCerrar: () => void
  onGuardado: () => void
}) {
  const [nombre, setNombre] = useState(zona?.name ?? '')
  const [error, setError] = useState<string | null>(null)

  const guardar = useMutation({
    mutationFn: () => gestionAjustes.guardarZona({ name: nombre.trim() }, zona?.id),
    onSuccess: onGuardado,
    onError: (e) => setError(interpretarError(e).mensaje),
  })

  return (
    <Hoja titulo={zona ? zona.name : 'Nueva zona'} onCerrar={onCerrar}>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          setError(null)
          guardar.mutate()
        }}
        className="flex flex-col gap-4"
      >
        <CampoTexto
          etiqueta="Nombre"
          ayuda="Salón principal, Terraza, Segundo piso…"
          valor={nombre}
          onCambio={setNombre}
          autoFocus
        />

        {error && <AvisoError mensaje={error} />}

        <div className="flex gap-2">
          <BotonSecundario type="button" onClick={onCerrar} className="flex-1">
            Cancelar
          </BotonSecundario>
          <BotonPrimario
            type="submit"
            disabled={!nombre.trim() || guardar.isPending}
            className="flex-[2]"
          >
            {guardar.isPending ? 'Guardando…' : 'Guardar'}
          </BotonPrimario>
        </div>
      </form>
    </Hoja>
  )
}
