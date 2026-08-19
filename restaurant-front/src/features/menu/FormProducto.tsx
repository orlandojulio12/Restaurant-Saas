import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { interpretarError } from '../../api/client'
import { gestionMenu } from '../../api/gestion'
import type { Categoria, GrupoAdicionales, Producto } from '../../api/tipos'
import {
  AvisoError,
  BotonPrimario,
  BotonSecundario,
  Campo,
  claseEntrada,
  Hoja,
} from '../../components/ui'
import { dinero } from '../../lib/formato'

export default function FormProducto({
  producto,
  categorias,
  grupos,
  moneda,
  onCerrar,
  onGuardado,
}: {
  producto: Producto | null
  categorias: Categoria[]
  grupos: GrupoAdicionales[]
  moneda: string
  onCerrar: () => void
  onGuardado: () => void
}) {
  const [categoriaId, setCategoriaId] = useState<number | ''>(
    producto?.category_id ?? categorias[0]?.id ?? '',
  )
  const [nombre, setNombre] = useState(producto?.name ?? '')
  const [descripcion, setDescripcion] = useState(producto?.description ?? '')
  const [precio, setPrecio] = useState(producto ? String(Number(producto.price)) : '')
  const [costo, setCosto] = useState(producto ? String(Number(producto.cost)) : '')
  const [minutos, setMinutos] = useState(String(producto?.preparation_time ?? 0))
  const [disponible, setDisponible] = useState(producto?.is_available ?? true)
  const [imagen, setImagen] = useState<File | null>(null)
  const [gruposElegidos, setGruposElegidos] = useState<number[]>(
    (producto?.additional_groups ?? []).map((g) => g.id),
  )

  const guardar = useMutation({
    mutationFn: () =>
      gestionMenu.guardarProducto(
        {
          category_id: Number(categoriaId),
          name: nombre,
          description: descripcion || null,
          price: Number(precio || 0),
          cost: Number(costo || 0),
          preparation_time: Number(minutos || 0),
          is_available: disponible,
          additional_group_ids: gruposElegidos,
          imagen,
        },
        producto?.id,
      ),
    onSuccess: onGuardado,
  })

  const fallo = guardar.isError ? interpretarError(guardar.error) : null

  // Margen: es el dato que convierte una carta en un negocio, y calcularlo
  // mentalmente por cada plato es justo lo que nadie hace.
  const margen =
    Number(precio) > 0 && Number(costo) > 0
      ? ((Number(precio) - Number(costo)) / Number(precio)) * 100
      : null

  return (
    <Hoja
      titulo={producto ? 'Editar producto' : 'Nuevo producto'}
      onCerrar={onCerrar}
      pie={
        <div className="flex gap-2">
          <BotonSecundario onClick={onCerrar} className="flex-1">
            Cancelar
          </BotonSecundario>
          <BotonPrimario
            onClick={() => guardar.mutate()}
            disabled={!nombre.trim() || !precio || !categoriaId || guardar.isPending}
            className="flex-[2]"
          >
            {guardar.isPending ? 'Guardando…' : 'Guardar'}
          </BotonPrimario>
        </div>
      }
    >
      <Campo etiqueta="Categoría" error={fallo?.campos?.category_id?.[0]}>
        <select
          value={categoriaId}
          onChange={(e) => setCategoriaId(Number(e.target.value))}
          className={claseEntrada}
        >
          {categorias.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
      </Campo>

      <Campo etiqueta="Nombre" error={fallo?.campos?.name?.[0]}>
        <input
          value={nombre}
          onChange={(e) => setNombre(e.target.value)}
          placeholder="Bandeja paisa"
          autoFocus
          className={claseEntrada}
        />
      </Campo>

      <div className="grid grid-cols-2 gap-3">
        <Campo etiqueta="Precio" error={fallo?.campos?.price?.[0]}>
          <input
            type="number"
            inputMode="numeric"
            value={precio}
            onChange={(e) => setPrecio(e.target.value)}
            placeholder="0"
            className={`${claseEntrada} cifras text-right`}
          />
        </Campo>

        <Campo etiqueta="Costo" ayuda="(opcional)">
          <input
            type="number"
            inputMode="numeric"
            value={costo}
            onChange={(e) => setCosto(e.target.value)}
            placeholder="0"
            className={`${claseEntrada} cifras text-right`}
          />
        </Campo>
      </div>

      {margen !== null && (
        <p
          className={`-mt-2 mb-4 rounded-lg px-3 py-2 text-sm font-medium ${
            margen < 0
              ? 'bg-cancelado-suave text-cancelado'
              : margen < 30
                ? 'bg-pendiente-suave text-pendiente'
                : 'bg-listo-suave text-listo'
          }`}
        >
          Margen: <span className="cifras font-bold">{margen.toFixed(0)}%</span>
          {margen < 0 && ' — el costo supera al precio.'}
          {margen >= 0 && margen < 30 && ' — ajustado para un restaurante.'}
        </p>
      )}

      <Campo etiqueta="Descripción" ayuda="(opcional)">
        <textarea
          value={descripcion ?? ''}
          onChange={(e) => setDescripcion(e.target.value)}
          rows={2}
          className={`${claseEntrada} py-2.5`}
        />
      </Campo>

      <Campo etiqueta="Minutos de preparación" ayuda="(opcional)">
        <input
          type="number"
          inputMode="numeric"
          value={minutos}
          onChange={(e) => setMinutos(e.target.value)}
          className={`${claseEntrada} cifras`}
        />
      </Campo>

      <Campo etiqueta="Foto" ayuda="(opcional, máx. 5 MB)" error={fallo?.campos?.image?.[0]}>
        <div className="flex items-center gap-3">
          {(imagen || producto?.image_url) && (
            <img
              src={imagen ? URL.createObjectURL(imagen) : producto!.image_url!}
              alt=""
              className="h-16 w-16 rounded-xl object-cover"
            />
          )}
          <input
            type="file"
            accept="image/*"
            onChange={(e) => setImagen(e.target.files?.[0] ?? null)}
            className="flex-1 text-sm text-piedra-600 file:mr-3 file:rounded-lg file:border-0
                       file:bg-piedra-100 file:px-3 file:py-2 file:text-sm file:font-semibold
                       file:text-piedra-700"
          />
        </div>
      </Campo>

      {grupos.length > 0 && (
        <Campo etiqueta="Opciones" ayuda="(grupos de adicionales)">
          <div className="flex flex-col gap-1.5">
            {grupos.map((g) => {
              const marcado = gruposElegidos.includes(g.id)

              return (
                <label
                  key={g.id}
                  className={`flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border
                              px-3.5 ${
                                marcado
                                  ? 'border-marca-500 bg-marca-50'
                                  : 'border-piedra-200'
                              }`}
                >
                  <input
                    type="checkbox"
                    checked={marcado}
                    onChange={() =>
                      setGruposElegidos((p) =>
                        marcado ? p.filter((id) => id !== g.id) : [...p, g.id],
                      )
                    }
                    className="h-5 w-5 accent-marca-600"
                  />
                  <span className="flex-1 text-sm text-piedra-800">
                    {g.name}
                    <span className="block text-xs text-piedra-500">
                      {g.additionals
                        .slice(0, 3)
                        .map((a) => a.name)
                        .join(', ')}
                      {g.additionals.length > 3 && '…'}
                    </span>
                  </span>
                </label>
              )
            })}
          </div>
        </Campo>
      )}

      <label className="flex min-h-12 items-center gap-3 rounded-xl border border-piedra-300 px-3.5">
        <input
          type="checkbox"
          checked={disponible}
          onChange={(e) => setDisponible(e.target.checked)}
          className="h-5 w-5 accent-marca-600"
        />
        <span className="flex-1 text-sm text-piedra-800">
          Disponible
          <span className="block text-xs text-piedra-500">
            Al agotarse, desmárcalo en vez de eliminarlo: conserva el histórico.
          </span>
        </span>
      </label>

      {precio && (
        <p className="mt-4 text-center text-sm text-piedra-500">
          Se venderá a <span className="cifras font-bold text-piedra-900">
            {dinero(precio, moneda)}
          </span>
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
