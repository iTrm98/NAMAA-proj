import 'maplibre-gl/dist/maplibre-gl.css'
import type { FeatureCollection, Polygon } from 'geojson'
import type { StyleSpecification } from 'maplibre-gl'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import Map, { Layer, type MapLayerMouseEvent, Marker, NavigationControl, Popup, Source } from 'react-map-gl/maplibre'
import { Link } from 'react-router'
import { SourceBadge, STATUS_COLORS, StatusPill } from '@/components/StatusPill'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { parkName, useParks } from '@/hooks/useParks'
import { formatDateTime, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { Park } from '@/types'

const MAPTILER_KEY = import.meta.env.VITE_MAPTILER_KEY?.trim() || ''
const MAKKAH = { latitude: 21.42, longitude: 39.83, zoom: 11 }

type Basemap = 'satellite' | 'streets'

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

function mapStyle(basemap: Basemap): string | StyleSpecification {
  if (!MAPTILER_KEY) return OSM_STYLE
  const map = basemap === 'satellite' ? 'satellite' : 'streets-v2'
  return `https://api.maptiler.com/maps/${map}/style.json?key=${encodeURIComponent(MAPTILER_KEY)}`
}

function boundsOf(parks: Park[]): [[number, number], [number, number]] | null {
  const points = parks.flatMap((p) => p.polygon?.coordinates?.[0] ?? [[p.lng, p.lat]])
  if (points.length === 0) return null
  const lngs = points.map((c) => c[0])
  const lats = points.map((c) => c[1])
  return [
    [Math.min(...lngs), Math.min(...lats)],
    [Math.max(...lngs), Math.max(...lats)],
  ]
}

export default function ParkMap() {
  const { t } = useTranslation()
  const parks = useParks()

  if (parks.isPending) return <Skeleton className="m-4 h-[calc(100%-2rem)]" />
  if (parks.isError)
    return (
      <div className="p-6 text-sm text-destructive">
        {t('common.error')}{' '}
        <button className="underline" onClick={() => parks.refetch()}>
          {t('common.retry')}
        </button>
      </div>
    )

  return <ParkMapView parks={parks.data} />
}

function ParkMapView({ parks }: { parks: Park[] }) {
  const { t, i18n } = useTranslation()
  const [basemap, setBasemap] = useState<Basemap>('satellite')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const selected = parks.find((p) => p.id === selectedId) ?? null

  const features = useMemo<FeatureCollection<Polygon>>(
    () => ({
      type: 'FeatureCollection',
      features: parks
        .filter((p) => p.polygon?.type === 'Polygon')
        .map((p) => ({
          type: 'Feature',
          id: p.id,
          geometry: p.polygon,
          properties: { id: p.id, color: STATUS_COLORS[p.status] },
        })),
    }),
    [parks],
  )

  // Only computed once: the initial view fits every park; polling must not move the map.
  const [initialView] = useState(() => {
    const bounds = boundsOf(parks)
    return bounds ? { bounds, fitBoundsOptions: { padding: 80, maxZoom: 16 } } : MAKKAH
  })

  function onClick(event: MapLayerMouseEvent) {
    const id = event.features?.[0]?.properties?.id
    setSelectedId(typeof id === 'number' ? id : null)
  }

  return (
    <div className="relative h-full min-h-[480px]" dir="ltr">
      <Map
        initialViewState={initialView}
        mapStyle={mapStyle(basemap)}
        interactiveLayerIds={['park-fill']}
        onClick={onClick}
        cursor="auto"
        style={{ width: '100%', height: '100%' }}
      >
        <NavigationControl position="top-right" showCompass={false} />

        <Source id="parks" type="geojson" data={features}>
          <Layer id="park-fill" type="fill" paint={{ 'fill-color': ['get', 'color'], 'fill-opacity': 0.45 }} />
          <Layer id="park-outline" type="line" paint={{ 'line-color': ['get', 'color'], 'line-width': 2 }} />
        </Source>

        {/* A marker per park: visible at every zoom (the polygons are small at city zoom). */}
        {parks.map((park) => (
          <Marker key={park.id} longitude={park.lng} latitude={park.lat} anchor="bottom" onClick={(e) => {
            e.originalEvent.stopPropagation()
            setSelectedId(park.id)
          }}>
            <button
              type="button"
              className="flex items-center gap-1 rounded-full bg-background/90 px-1.5 py-1 shadow ring-1 ring-black/10"
              aria-label={parkName(park, i18n.language)}
              title={parkName(park, i18n.language)}
            >
              <span className="size-3 rounded-full ring-2 ring-white/80" style={{ backgroundColor: STATUS_COLORS[park.status] }} />
              {park.source === 'simulated' && <SourceBadge source="simulated" className="px-1 py-0 text-[0.6rem]" />}
            </button>
          </Marker>
        ))}

        {selected && (
          <Popup
            longitude={selected.lng}
            latitude={selected.lat}
            anchor="top"
            offset={8}
            onClose={() => setSelectedId(null)}
            closeOnClick={false}
            maxWidth="280px"
          >
            <ParkPopup park={selected} />
          </Popup>
        )}
      </Map>

      {MAPTILER_KEY ? (
        <div className="absolute top-3 left-3 flex gap-1 rounded-lg bg-background/90 p-1 shadow" dir={i18n.dir()}>
          {(['satellite', 'streets'] as const).map((b) => (
            <Button key={b} size="sm" variant={basemap === b ? 'default' : 'ghost'} onClick={() => setBasemap(b)}>
              {t(`admin.${b}`)}
            </Button>
          ))}
        </div>
      ) : (
        <div className="absolute top-3 left-3 max-w-xs rounded-lg bg-background/90 px-3 py-1.5 text-xs text-muted-foreground shadow" dir={i18n.dir()}>
          {t('admin.osmNote')}
        </div>
      )}
    </div>
  )
}

function ParkPopup({ park }: { park: Park }) {
  const { t, i18n } = useTranslation()
  const latest = park.latest

  return (
    <div dir={i18n.dir()} className="flex min-w-52 flex-col gap-2 p-1 text-sm text-neutral-900">
      <div className="flex items-start justify-between gap-2 pe-4">
        <span className="font-semibold">{parkName(park, i18n.language)}</span>
      </div>
      <div className="flex items-center gap-1.5">
        <StatusPill status={park.status} />
        <SourceBadge source={park.source} />
      </div>
      <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
        <dt className="text-neutral-500">{t('park.moisture')}</dt>
        <dd className="font-medium">{latest?.moisture_pct != null ? `${formatNumber(latest.moisture_pct)} %` : '—'}</dd>
        <dt className="text-neutral-500">{t('park.temperature')}</dt>
        <dd className="font-medium">{latest?.temperature != null ? `${formatNumber(latest.temperature)} °C` : '—'}</dd>
        <dt className="text-neutral-500">{t('park.humidity')}</dt>
        <dd className="font-medium">{latest?.humidity != null ? `${formatNumber(latest.humidity)} %` : '—'}</dd>
        <dt className="text-neutral-500">{t('admin.lastReading')}</dt>
        <dd className={cn('font-medium', !park.last_reading_at && 'text-neutral-500')}>
          {park.last_reading_at ? formatDateTime(park.last_reading_at) : t('admin.noReadingYet')}
        </dd>
      </dl>
      <Link to={`/admin/parks/${park.id}`} className="text-xs font-medium text-green-700 underline-offset-4 hover:underline">
        {t('admin.openPark')}
      </Link>
    </div>
  )
}
