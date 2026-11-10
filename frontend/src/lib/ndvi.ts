/**
 * NDVI colour scale for the map's vegetation layer: brown (bare, <= 0.1) ->
 * yellow (sparse, 0.3) -> green (dense, >= 0.6). Grey when there is no value yet.
 */
export const NDVI_STOPS: [number, string][] = [
  [0.1, '#8c5a2b'],
  [0.3, '#e9c46a'],
  [0.6, '#2a9d4b'],
]

export const NDVI_NONE = '#9ca3af'

function hex(c: string): [number, number, number] {
  return [1, 3, 5].map((i) => parseInt(c.slice(i, i + 2), 16)) as [number, number, number]
}

export function ndviColor(mean: number | null | undefined): string {
  if (mean == null || Number.isNaN(mean)) return NDVI_NONE
  const [first] = NDVI_STOPS
  const last = NDVI_STOPS[NDVI_STOPS.length - 1]
  if (mean <= first[0]) return first[1]
  if (mean >= last[0]) return last[1]
  for (let i = 0; i < NDVI_STOPS.length - 1; i++) {
    const [a, ca] = NDVI_STOPS[i]
    const [b, cb] = NDVI_STOPS[i + 1]
    if (mean <= b) {
      const f = (mean - a) / (b - a)
      const [r1, g1, b1] = hex(ca)
      const [r2, g2, b2] = hex(cb)
      const mix = (x: number, y: number) => Math.round(x + (y - x) * f).toString(16).padStart(2, '0')
      return `#${mix(r1, r2)}${mix(g1, g2)}${mix(b1, b2)}`
    }
  }
  return last[1]
}

/** CSS gradient of the scale (legend). */
export const NDVI_GRADIENT = `linear-gradient(to right, ${NDVI_STOPS.map(([, c]) => c).join(', ')})`
