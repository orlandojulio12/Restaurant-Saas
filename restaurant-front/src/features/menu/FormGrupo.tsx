import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { interpretarError } from '../../api/client'
import { gestionMenu } from '../../api/gestion'
import type { GrupoAdicionales } from '../../api/tipos'
import {
  AvisoError,
  BotonPrimario,
  BotonSecundario,
  Campo,
  claseEntrada,
  Hoja,
} from '../../components/ui'

type Linea = {
  id?: number
  name: string
  extra_price: string
  is_available: boolean
}

export default function FormGrupo({
  grupo,
  onCerrar,
  onGuardado,
}: {
  grupo: GrupoAdicionales | null
  onCerrar: () => void
  onGuardado: () => void
}) {
  const [nombre, setNombre] = useState(grupo?.name ?? '')
  const [tipo, setTipo] = useState<'single' | 'multiple'>(grupo?.selection_type ?? 'single')
  const [obligatorio, setObligatorio] = useState(grupo?.is_required ?? false)
  const [lineas, setLineas] = useState<Linea[]>(
    grupo?.additionals.map((a) => ({
      id: a.id,
      name: a.name,
      extra_price: String(Number(a.extra_price)),
      is_available: a.is_available,
    })) ?? [{ name: '', extra_price: '0', is_available: true }],
  )

  const guardar = useMutation({
    mutationFn: () =>
      gestionMenu.guardarGrupo(
        {
          name: nombre,
          selection_type: tipo,
          is_required: obligatorio,
          additionals: lineas
            .filter((l) => l.name.trim())
            .map((l) => ({
              id: l.id,
              name: l.name.trim(),
              extra_price: Number(l.extra_price || 0),
              is_available: l.is_available,
            })),
        },
        grupo?.id,
      ),
    onSuccess: onGuardado,
  })

  const fallo = guardar.isError ? interpretarError(guardar.error) : null
  const validas = lineas.filter((l) => l.name.trim()).length

  const cambiar = (i: number, cambios: Partial<Linea>) =>
    setLineas((p) => p.map((l, j) => (i === j ? { ...l, ...cambios } : l)))

  return (
    <Hoja
      titulo={grupo ? 'Editar grupo' : 'Nuevo grupo de adicionales'}
      bajada="Las opciones que se ofrecen al pedir un producto."
      onCerrar={onCerrar}
      pie={
        <div className="flex gap-2">
          <BotonSecundario onClick={onCerrar} className="flex-1">
            Cancelar
          </BotonSecundario>
          <BotonPrimario
            onClick={() => guardar.mutate()}
            disabled={!nombre.trim() || guardar.isPending}
            className="flex-[2]"
          >
            {guardar.isPending ? 'Guardando…' : 'Guardar'}
          </BotonPrimario>
        </div>
      }
    >
      <Campo etiqueta="Nombre del grupo" error={fallo?.campos?.name?.[0]}>
        <input
          value={nombre}
          onChange={(e) => setNombre(e.target.value)}
          placeholder="Tamaño, Salsas, Punto de cocción…"
          autoFocus
          className={claseEntrada}
        />
      </Campo>

      <Campo etiqueta="¿Cuántas puede elegir?">
        <div className="flex gap-2">
          {(
            [
              ['single', 'Solo una'],
              ['multiple', 'Varias'],
            ] as const
          ).map(([valor, texto]) => (
            <button
              key={valor}
              onClick={() => setTipo(valor)}
              className={`min-h-12 flex-1 rounded-xl text-sm font-semibold transition ${
                tipo === valor
                  ? 'bg-marca-600 text-white'
                  : 'bg-piedra-100 text-piedra-600 hover:bg-piedra-200'
              }`}
            >
              {texto}
            </button>
          ))}
        </div>
      </Campo>

      <label className="mb-4 flex min-h-12 items-center gap-3 rounded-xl border
                        border-piedra-300 px-3.5">
        <input
          type="checkbox"
          checked={obligatorio}
          onChange={(e) => setObligatorio(e.target.checked)}
          className="h-5 w-5 accent-marca-600"
        />
        <span className="flex-1 text-sm text-piedra-800">
          Obligatorio
          <span className="block text-xs text-piedra-500">
            No se podrá añadir el producto sin elegir una opción.
          </span>
        </span>
      </label>

      <div className="mb-2 flex items-center justify-between">
        <span className="text-sm font-semibold text-piedra-800">Opciones</span>
        <span className="cifras text-xs text-piedra-500">{validas}</span>
      </div>

      <ul className="mb-3 flex flex-col gap-2">
        {lineas.map((l, i) => (
          <li key={i} className="flex items-center gap-2">
            <input
              value={l.name}
              onChange={(e) => cambiar(i, { name: e.target.value })}
              placeholder="Nombre"
              className={`${claseEntrada} flex-[2]`}
            />
            <input
              type="number"
              inputMode="numeric"
              value={l.extra_price}
              onChange={(e) => cambiar(i, { extra_price: e.target.value })}
              placeholder="0"
              title="Precio extra"
              className={`${claseEntrada} cifras flex-1 text-right`}
            />
            <button
              onClick={() => cambiar(i, { is_available: !l.is_available })}
              title={l.is_available ? 'Disponible' : 'Agotado'}
              className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-lg ${
                l.is_available
                  ? 'bg-listo-suave text-listo'
                  : 'bg-piedra-100 text-piedra-400'
              }`}
            >
              {l.is_available ? '✓' : '✕'}
            </button>
            <button
              onClick={() => setLineas((p) => p.filter((_, j) => j !== i))}
              aria-label="Quitar opción"
              className="flex h-12 w-10 shrink-0 items-center justify-center rounded-xl
                         text-piedra-400 hover:bg-cancelado-suave hover:text-cancelado"
            >
              −
            </button>
          </li>
        ))}
      </ul>

      <BotonSecundario
        onClick={() =>
          setLineas((p) => [...p, { name: '', extra_price: '0', is_available: true }])
        }
        className="w-full"
      >
        + Añadir opción
      </BotonSecundario>

      {grupo && (
        <p className="mt-4 text-xs text-piedra-500">
          Las opciones que ya aparecen en algún pedido no se eliminan al quitarlas de la lista:
          se marcan como agotadas para no romper el histórico.
        </p>
      )}

      {fallo && (
        <div className="mt-4">
          <AvisoError mensaje={fallo.mensaje} esLimite={fallo.esLimite} />
        </div>
      )}
    </Hoja>
  )
}
