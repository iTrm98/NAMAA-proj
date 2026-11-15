import '@/lib/maplibreWorker'
import type { Map as MapLibreMap, StyleSpecification } from 'maplibre-gl'
import { useDemoInfo } from '@/hooks/useOps'

/** Basemaps: MapTiler (VITE_MAPTILER_KEY) satellite or streets; OpenStreetMap without a key. */
export const MAPTILER_KEY = import.meta.env.VITE_MAPTILER_KEY?.trim() || ''
export const MAKKAH = { latitude: 21.42, longitude: 39.83, zoom: 11 }
/** The map never leaves greater Makkah: [[west, south], [east, north]]. */
export const MAX_BOUNDS: [number, number, number, number] = [39.5, 21.1, 40.2, 21.8]

export const ATTRIBUTION = '© OpenStreetMap contributors · Contains modified Copernicus Sentinel data 2026'
export const ESRI_ATTRIBUTION = 'Imagery © Esri, Maxar, Earthstar Geographics'

/** Attribution line for the basemap in use: always the base text, plus Esri when it is on. */
export function attributionFor(basemap: Basemap): string {
  return basemap === 'esri' ? `${ATTRIBUTION} · ${ESRI_ATTRIBUTION}` : ATTRIBUTION
}

export type Bounds = [number, number, number, number]
export interface MapCfg {
  basemap: Basemap
  bounds: Bounds
  lock: boolean
  minZoom: number
  maxZoom: number
}

/** Shared MapLibre props: zoom limits, bounds (only when locked) and attribution. */
export function mapShellProps(cfg: MapCfg) {
  return {
    ...(cfg.lock ? { maxBounds: cfg.bounds } : {}),
    minZoom: cfg.minZoom,
    maxZoom: cfg.maxZoom,
    attributionControl: { compact: false, customAttribution: attributionFor(cfg.basemap) },
  }
}

export type Basemap = 'satellite' | 'streets' | 'esri'

/** The demo basemap, bounds and zoom limits, from GET /demo/status `map` (MapTiler satellite and greater Makkah, unlocked, until it answers). */
export function useMapConfig(): MapCfg {
  const info = useDemoInfo()
  const map = info.data?.map
  return {
    basemap: map?.basemap === 'esri' ? 'esri' : 'satellite',
    bounds: map?.bounds ?? MAX_BOUNDS,
    lock: map?.lock === true,
    minZoom: map?.min_zoom ?? 2,
    maxZoom: map?.max_zoom ?? 22,
  }
}

/** Locked maps only: the lowest zoom is the one where the whole bounds fit the view. */
export function fitMinZoom(map: MapLibreMap, cfg: MapCfg) {
  if (!cfg.lock) return
  const b = cfg.bounds
  const camera = map.cameraForBounds([[b[0], b[1]], [b[2], b[3]]])
  if (camera?.zoom != null) map.setMinZoom(Math.max(cfg.minZoom, camera.zoom - 0.1))
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
