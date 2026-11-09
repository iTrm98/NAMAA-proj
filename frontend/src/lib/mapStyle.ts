import type { StyleSpecification } from 'maplibre-gl'

/** Basemaps: MapTiler (VITE_MAPTILER_KEY) satellite or streets; OpenStreetMap without a key. */
export const MAPTILER_KEY = import.meta.env.VITE_MAPTILER_KEY?.trim() || ''
export const MAKKAH = { latitude: 21.42, longitude: 39.83, zoom: 11 }

export type Basemap = 'satellite' | 'streets'

/** Without a MapTiler key: OpenStreetMap raster tiles (with attribution). */
const OSM_STYLE: StyleSpecification = {
  version: 8,
  sources: {
    osm: {
      type: 'raster',
      tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
      tileSize: 256,
      maxzoom: 19,
      attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">OpenStreetMap</a> contributors',
    },
  },
  layers: [{ id: 'osm', type: 'raster', source: 'osm' }],
}

export function mapStyle(basemap: Basemap): string | StyleSpecification {
  if (!MAPTILER_KEY) return OSM_STYLE
  const map = basemap === 'satellite' ? 'satellite' : 'streets-v2'
  return `https://api.maptiler.com/maps/${map}/style.json?key=${encodeURIComponent(MAPTILER_KEY)}`
}
