/**
 * Deriva la paleta de un restaurante a partir de un solo color.
 *
 * La carta pública es lo único que el comensal ve del restaurante, y en un SaaS
 * multi-restaurante no puede salir con el verde del panel. El administrador
 * elige un hex y de ahí sale la escala completa, que se inyecta como variables
 * CSS: `bg-marca-600` y compañía ya son `var(--color-marca-600)`, así que
 * recolorear la carta entera no toca ni una clase de Tailwind.
 *
 * El cálculo va en OKLCH y no en HSL. Bajar la luminosidad en HSL apaga unos
 * tonos mucho más que otros —un amarillo se ensucia y un azul apenas cambia—,
 * y con restaurantes eligiendo colores arbitrarios eso significa que a algunos
 * les saldría una carta descolorida. En OKLCH los pasos se ven igual de
 * separados para cualquier tono.
 *
 * Todo se resuelve en JavaScript en vez de con `oklch(from …)` del navegador:
 * quien abre esto está sentado en una mesa con el móvil que tenga, y la sintaxis
 * de color relativo aún deja fuera equipos que siguen en la calle.
 */

/** Luminosidad OKLCH de cada peso. Los mismos saltos para cualquier color. */
const NIVELES = {
  50: 0.971,
  100: 0.936,
  200: 0.885,
  300: 0.83,
  400: 0.746,
  500: 0.68,
  600: 0.588,
  700: 0.505,
  800: 0.443,
  900: 0.396,
  950: 0.29,
} as const

export type Peso = keyof typeof NIVELES
export type Escala = Record<Peso, string>

// ── Conversión ──────────────────────────────────────────────────────────────

function aLineal(c: number): number {
  return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
}

function aGama(c: number): number {
  return c <= 0.0031308 ? c * 12.92 : 1.055 * c ** (1 / 2.4) - 0.055
}

function hexARgb(hex: string): [number, number, number] | null {
  const limpio = hex.trim().replace(/^#/, '')
  if (!/^[0-9a-fA-F]{6}$/.test(limpio)) return null

  return [
    parseInt(limpio.slice(0, 2), 16) / 255,
    parseInt(limpio.slice(2, 4), 16) / 255,
    parseInt(limpio.slice(4, 6), 16) / 255,
  ]
}

function rgbAHex(r: number, g: number, b: number): string {
  const canal = (c: number) =>
    Math.round(Math.min(1, Math.max(0, c)) * 255)
      .toString(16)
      .padStart(2, '0')

  return `#${canal(r)}${canal(g)}${canal(b)}`
}

/** sRGB → OKLCH. Devuelve luminosidad, croma y tono en grados. */
function aOklch(rgb: [number, number, number]): { l: number; c: number; h: number } {
  const [r, g, b] = rgb.map(aLineal)

  const l_ = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b)
  const m_ = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b)
  const s_ = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b)

  const L = 0.2104542553 * l_ + 0.793617785 * m_ - 0.0040720468 * s_
  const a = 1.9779984951 * l_ - 2.428592205 * m_ + 0.4505937099 * s_
  const bb = 0.0259040371 * l_ + 0.7827717662 * m_ - 0.808675766 * s_

  return {
    l: L,
    c: Math.hypot(a, bb),
    h: (Math.atan2(bb, a) * 180) / Math.PI,
  }
}

function aRgb(l: number, c: number, h: number): [number, number, number] {
  const rad = (h * Math.PI) / 180
  const a = c * Math.cos(rad)
  const b = c * Math.sin(rad)

  const l_ = (l + 0.3963377774 * a + 0.2158037573 * b) ** 3
  const m_ = (l - 0.1055613458 * a - 0.0638541728 * b) ** 3
  const s_ = (l - 0.0894841775 * a - 1.291485548 * b) ** 3

  return [
    aGama(4.0767416621 * l_ - 3.3077115913 * m_ + 0.2309699292 * s_),
    aGama(-1.2684380046 * l_ + 2.6097574011 * m_ - 0.3413193965 * s_),
    aGama(-0.0041960863 * l_ - 0.7034186147 * m_ + 1.707614701 * s_),
  ]
}

function cabeEnPantalla([r, g, b]: [number, number, number]): boolean {
  // Un pelo de margen: el redondeo a entero de 8 bits no debe contar como
  // fuera de gama.
  return [r, g, b].every((c) => c >= -0.0001 && c <= 1.0001)
}

/**
 * El color más saturado de ese tono y esa luminosidad que la pantalla puede
 * mostrar. Sin esto, un tono vivo al 40 % de luz sale recortado a un canal y
 * cambia de matiz: un rojo intenso vira a naranja al oscurecerlo.
 */
function ajustarAGama(l: number, c: number, h: number): [number, number, number] {
  if (cabeEnPantalla(aRgb(l, c, h))) return aRgb(l, c, h)

  let bajo = 0
  let alto = c

  for (let i = 0; i < 18; i++) {
    const medio = (bajo + alto) / 2
    if (cabeEnPantalla(aRgb(l, medio, h))) bajo = medio
    else alto = medio
  }

  return aRgb(l, bajo, h)
}

// ── Escala ──────────────────────────────────────────────────────────────────

/**
 * Cuánto croma admite una luminosidad. Cerca del blanco y del negro casi
 * ninguno: forzarlo ahí es lo que produce esos grises sucios con tinte.
 */
function margen(l: number): number {
  return Math.max(0.06, 1 - Math.abs(2 * l - 1) ** 1.5)
}

/**
 * Los once pesos derivados de un hex, o null si el hex no es válido —el
 * llamador cae entonces en la paleta por defecto en vez de romper la carta.
 */
export function escalaDeMarca(hex: string | null | undefined): Escala | null {
  if (!hex) return null

  const rgb = hexARgb(hex)
  if (!rgb) return null

  const { l, c, h } = aOklch(rgb)

  // Un gris no tiene escala de marca que derivar: mejor la paleta por defecto,
  // que al menos distingue los botones del fondo.
  if (c < 0.02) return null

  // El croma se reparte según el margen que deja cada luminosidad, tomando
  // como referencia el del color elegido. Así el peso más cercano al original
  // sale prácticamente igual a lo que el restaurante escogió.
  const proporcion = Math.min(2.5, c / margen(l))

  const escala = {} as Escala

  for (const [peso, luz] of Object.entries(NIVELES)) {
    const [r, g, b] = ajustarAGama(luz, proporcion * margen(luz), h)
    escala[Number(peso) as Peso] = rgbAHex(r, g, b)
  }

  return escala
}

/** Luminancia relativa WCAG. */
function luminancia([r, g, b]: [number, number, number]): number {
  const [rl, gl, bl] = [r, g, b].map(aLineal)
  return 0.2126 * rl + 0.7152 * gl + 0.0722 * bl
}

function contraste(a: [number, number, number], b: [number, number, number]): number {
  const [claro, oscuro] = [luminancia(a), luminancia(b)].sort((x, y) => y - x)
  return (claro + 0.05) / (oscuro + 0.05)
}

/**
 * Blanco o casi negro, el que se lea mejor encima del color dado.
 *
 * El restaurante elige su color sin pensar en el texto que va encima, y un
 * amarillo de marca con letras blancas deja el nombre del local ilegible al
 * sol, que es justo donde se usa esto.
 */
export function textoSobre(hex: string): string {
  const fondo = hexARgb(hex)
  if (!fondo) return '#ffffff'

  const blanco: [number, number, number] = [1, 1, 1]
  const tinta: [number, number, number] = [0.09, 0.08, 0.075]

  return contraste(fondo, blanco) >= contraste(fondo, tinta) ? '#ffffff' : '#171412'
}

/**
 * Las variables CSS que recolorean todo lo que haya dentro del contenedor.
 * Devuelve un objeto vacío cuando no hay marca configurada, y la carta se queda
 * con la paleta por defecto.
 */
export function variablesDeMarca(hex: string | null | undefined): Record<string, string> {
  const escala = hex ? escalaDeMarca(hex) : null
  if (!escala || !hex) return {}

  const vars: Record<string, string> = {}

  for (const [peso, color] of Object.entries(escala)) {
    vars[`--color-marca-${peso}`] = color
  }

  // La cabecera va con el color exacto que eligió el restaurante, no con un
  // peso derivado. Un amarillo de marca al 70 % de oscuridad es un verde
  // oliva: correcto en OKLCH y sin nada que ver con lo que escogieron. La
  // escala sirve para lo demás —tintes, bordes, el acento de los precios—,
  // donde lo que importa es la relación entre pesos y no clavar el tono.
  vars['--marca-pura'] = hex.trim()

  // El texto que va encima, decidido por contraste y no suponiendo que toda
  // marca es oscura: un amarillo con letras blancas deja el nombre del local
  // ilegible al sol, que es justo donde se lee esto.
  vars['--tinta-marca'] = textoSobre(hex)

  return vars
}
