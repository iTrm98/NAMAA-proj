import type { GeoJsonMultiPolygon, GeoJsonPolygon } from '@/types'

export type AreaGeometry = GeoJsonPolygon | GeoJsonMultiPolygon

/** Every outer ring of a Polygon or MultiPolygon (holes dropped); empty for anything else. */
export function outerRings(g: { type?: string; coordinates?: unknown } | null | undefined): [number, number][][] {
  if (!g || !Array.isArray(g.coordinates)) return []
  if (g.type === 'Polygon') {
    const r = (g.coordinates as [number, number][][])[0]
    return r ? [r] : []
  }
  if (g.type === 'MultiPolygon') {
    return (g.coordinates as [number, number][][][]).map((p) => p?.[0]).filter((r): r is [number, number][] => Array.isArray(r))
  }
  return []
}

/** All outer-ring points of a geometry, for bounds. */
export function outerPoints(g: Parameters<typeof outerRings>[0]): [number, number][] {
  return outerRings(g).flat()
}

export const isAreaGeometry = (g: { type?: string } | null | undefined): boolean => g?.type === 'Polygon' || g?.type === 'MultiPolygon'
