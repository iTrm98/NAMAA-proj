import type { ExpressionSpecification } from 'maplibre-gl'
import type { FeatureCollection } from 'geojson'
import { useQueries } from '@tanstack/react-query'
import { Layer, Source } from 'react-map-gl/maplibre'
import { api, ApiError } from '@/lib/api'
import { NDVI_STOPS } from '@/lib/ndvi'
import { useScope } from '@/lib/scope'
import type { Park } from '@/types'

export const PIXEL_ZOOM = 15

export type PixelCollection = FeatureCollection & { meta?: { date?: string } }

/** Same stops as the NDVI legend, so squares and legend always agree. */
const COLOR = ['interpolate', ['linear'], ['get', 'v'], ...NDVI_STOPS.flatMap(([v, c]) => [v, c])] as unknown as ExpressionSpecification

/**
 * Real 10 m Sentinel-2 pixels of the parks in view, fetched lazily (only parks that have pixels and are on screen).
 * Must sit inside a react-map-gl Map. Reports the newest pixel date through onDate.
 */
export function NdviPixels({ parks }: { parks: Park[] }) {
  // The pixels route exists for admins only; the municipality scope never fetches or draws them.
  const enabled = useScope() === 'admin'
  const results = useQueries({
    queries: parks.map((p) => ({
      queryKey: ['admin', 'parks', p.id, 'ndvi-pixels', p.satellite?.image_date ?? null],
      queryFn: ({ signal }: { signal: AbortSignal }) =>
        api<PixelCollection>(`/admin/parks/${p.id}/ndvi/pixels`, { signal }).catch((e: unknown) => {
          if (e instanceof ApiError && e.status === 404) return null
          throw e
        }),
      enabled,
      staleTime: 10 * 60_000,
      retry: false,
    })),
  })
  return (
    <>
      {parks.map((p, i) => {
        const data = results[i]?.data
        if (!enabled || !data) return null
        return (
          <Source key={p.id} id={`ndvi-pixels-${p.id}`} type="geojson" data={data}>
            <Layer id={`ndvi-pixels-fill-${p.id}`} type="fill" minzoom={PIXEL_ZOOM - 0.01} paint={{ 'fill-color': COLOR, 'fill-opacity': 0.78 }} />
          </Source>
        )
      })}
    </>
  )
}
