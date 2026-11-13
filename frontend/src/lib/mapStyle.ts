import { useCallback, useState } from 'react'
import '@/lib/maplibreWorker'
import type { StyleSpecification } from 'maplibre-gl'

/** Basemaps: MapTiler (VITE_MAPTILER_KEY) satellite or streets; OpenStreetMap without a key. */
export const MAPTILER_KEY = import.meta.env.VITE_MAPTILER_KEY?.trim() || ''
export const MAKKAH = { latitude: 21.42, longitude: 39.83, zoom: 11 }
/** The map never leaves greater Makkah: [[west, south], [east, north]]. */
export const MAX_BOUNDS: [number, number, number, number] = [39.5, 21.1, 40.2, 21.8]
export const MIN_ZOOM = 10

export const ATTRIBUTION = '© OpenStreetMap contributors · Contains modified Copernicus Sentinel data 2026'
export const ESRI_ATTRIBUTION = 'Imagery © Esri, Maxar, Earthstar Geographics'

/** Attribution line for the basemap in use: always the base text, plus Esri when it is on. */
export function attributionFor(basemap: Basemap): string {
  return basemap === 'esri' ? `${ATTRIBUTION} · ${ESRI_ATTRIBUTION}` : ATTRIBUTION
}

/** Shared MapLibre props: bounds, zoom limits and attribution. */
export function mapShellProps(basemap: Basemap) {
  return {
    maxBounds: MAX_BOUNDS,
    minZoom: MIN_ZOOM,
    attributionControl: { compact: false, customAttribution: attributionFor(basemap) },
  } as const
}

export type Basemap = 'satellite' | 'streets' | 'esri'

const BASEMAP_KEY = 'namaa.map.basemap'

/** The user's last basemap choice; Satellite by default (it needs the MapTiler key). */
export function useBasemap(): [Basemap, (next: Basemap) => void] {
  const [basemap, setBasemap] = useState<Basemap>(() => {
    try {
      const saved = localStorage.getItem(BASEMAP_KEY)
      if (saved === 'satellite' || saved === 'streets' || saved === 'esri') return saved
    } catch {
      // storage blocked (private window): fall back to the default
    }
    return 'satellite'
  })
  const choose = useCallback((next: Basemap) => {
    setBasemap(next)
    try {
      localStorage.setItem(BASEMAP_KEY, next)
    } catch {
      // not remembered, still switched
    }
  }, [])
  return [basemap, choose]
}

/** Without a MapTiler key: OpenStreetMap raster tiles (with attribution). */
const OSM_STYLE: StyleSpecification = {
  version: 8,
  sources: {
    osm: {
      type: 'raster',
      tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
      tileSize: 256,
      maxzoom: 19,
    },
  },
  layers: [{ id: 'osm', type: 'raster', source: 'osm' }],
}

/** Esri World Imagery: no key needed. Attribution comes from attributionFor(). */
const ESRI_STYLE: StyleSpecification = {
  version: 8,
  sources: {
    esri: {
      type: 'raster',
      tiles: ['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'],
      tileSize: 256,
      maxzoom: 19,
    },
  },
  layers: [{ id: 'esri', type: 'raster', source: 'esri' }],
}

export function mapStyle(basemap: Basemap): string | StyleSpecification {
  if (basemap === 'esri') return ESRI_STYLE
  if (!MAPTILER_KEY) return OSM_STYLE
  const map = basemap === 'satellite' ? 'satellite' : 'streets-v2'
  return `https://api.maptiler.com/maps/${map}/style.json?key=${encodeURIComponent(MAPTILER_KEY)}`
}
