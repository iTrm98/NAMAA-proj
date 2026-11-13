import 'maplibre-gl/dist/maplibre-gl.css'
import type { FeatureCollection, MultiPolygon, Polygon } from 'geojson'
import { Camera } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import Map, { Layer, type MapLayerMouseEvent, NavigationControl, Popup, Source } from 'react-map-gl/maplibre'
import { Link } from 'react-router'
import { OpenAlertsPanel } from '@/components/OpenAlertsPanel'
import { CATEGORY_META, PriorityBadge } from '@/components/ops'
import { useOpenAlerts } from '@/hooks/useOps'
import { SourceBadge, STATUS_COLORS, StatusPill } from '@/components/StatusPill'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { parkName, useParks } from '@/hooks/useParks'
import { formatAgo, formatDate, formatDateTime, formatNumber } from '@/lib/format'
import { NDVI_GRADIENT, NDVI_NONE, ndviColor } from '@/lib/ndvi'
import { MAKKAH, MAPTILER_KEY, mapShellProps, mapStyle, useBasemap } from '@/lib/mapStyle'
import { ParkMarkers } from '@/components/map/ParkMarkers'
import { mapParks } from '@/lib/parkClusters'
import { useBasePath } from '@/lib/scope'
import { cn } from '@/lib/utils'
import type { Park } from '@/types'

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

type MapLayer = 'status' | 'ndvi'

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

  return (
    <div className="flex h-full flex-col lg:flex-row">
      <div className="min-h-[480px] flex-1">
        <ParkMapView parks={parks.data} />
      </div>
      <OpenAlertsPanel className="border-t lg:w-96 lg:shrink-0 lg:border-s lg:border-t-0" />
    </div>
  )
}

function ParkMapView({ parks: allParks }: { parks: Park[] }) {
  const parks = useMemo(() => mapParks(allParks), [allParks])
  const { t, i18n } = useTranslation()
  const [basemap, setBasemap] = useBasemap()
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [layer, setLayer] = useState<MapLayer>('status')
  const selected = parks.find((p) => p.id === selectedId) ?? null
  const colorOf = (p: Park) => (layer === 'ndvi' ? ndviColor(p.ndvi?.too_small ? null : p.ndvi?.mean) : STATUS_COLORS[p.status])
  const imageDate = parks.reduce<string | null>((newest, p) => (p.ndvi && (!newest || p.ndvi.date > newest) ? p.ndvi.date : newest), null)

  const features = useMemo<FeatureCollection<Polygon>>(
    () => ({
      type: 'FeatureCollection',
      features: parks
        .filter((p) => p.polygon?.type === 'Polygon')
        .map((p) => ({
          type: 'Feature',
          id: p.id,
          geometry: p.polygon,
          properties: { id: p.id, color: layer === 'ndvi' ? ndviColor(p.ndvi?.too_small ? null : p.ndvi?.mean) : STATUS_COLORS[p.status] },
        })),
    }),
    [parks, layer],
  )

  const greenZones = useMemo<FeatureCollection<MultiPolygon>>(
    () => ({
      type: 'FeatureCollection',
      features: parks
        .filter((p) => p.green_zone)
        .map((p) => ({ type: 'Feature', properties: { id: p.id }, geometry: p.green_zone as MultiPolygon })),
    }),
    [parks],
  )

  // Only computed once: the initial view fits every park; polling must not move the map.
  const [initialView] = useState(() => {
    const bounds = boundsOf(parks)
    return bounds ? { bounds, fitBoundsOptions: { padding: 80, maxZoom: 11 } } : MAKKAH
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
        {...mapShellProps(basemap)}
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

        {/* Green zone (Sprint 6.2): a distinct dashed green, drawn above the boundary, only where a park has one. */}
        <Source id="green-zones" type="geojson" data={greenZones}>
          <Layer id="green-zone-fill" type="fill" paint={{ 'fill-color': '#8BE36B', 'fill-opacity': 0.3 }} />
          <Layer id="green-zone-line" type="line" paint={{ 'line-color': '#8BE36B', 'line-width': 1.5, 'line-dasharray': [2, 1] }} />
        </Source>

        <ParkMarkers parks={parks} selectedId={selectedId} onSelect={(p) => setSelectedId(p.id)} colorOf={colorOf} />

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

      <div className="absolute top-3 left-3 flex flex-col items-start gap-2" dir={i18n.dir()}>
        <div className="flex gap-1 rounded-lg bg-background/90 p-1 shadow" role="group" aria-label={t('overview.baseMap')}>
          {(MAPTILER_KEY ? (['satellite', 'streets', 'esri'] as const) : (['streets', 'esri'] as const)).map((b) => (
            <Button key={b} size="sm" variant={basemap === b ? 'default' : 'ghost'} onClick={() => setBasemap(b)} aria-pressed={basemap === b}>
              {b === 'esri' ? t('map.esri') : t(`admin.${b}`)}
            </Button>
          ))}
        </div>
        {!MAPTILER_KEY && basemap === 'streets' && <div className="max-w-xs rounded-lg bg-background/90 px-3 py-1.5 text-xs text-muted-foreground shadow">{t('admin.osmNote')}</div>}
        <div className="flex gap-1 rounded-lg bg-background/90 p-1 shadow" role="group" aria-label={t('ndvi.layer')}>
          {(['status', 'ndvi'] as const).map((l) => (
            <Button key={l} size="sm" variant={layer === l ? 'default' : 'ghost'} onClick={() => setLayer(l)} aria-pressed={layer === l}>
              {t(`ndvi.layer_${l}`)}
            </Button>
          ))}
        </div>
      </div>

      {layer === 'ndvi' && (
        <div className="absolute bottom-8 left-3 flex w-56 flex-col gap-1 rounded-lg bg-background/90 px-3 py-2 text-xs shadow" dir={i18n.dir()}>
          <span className="font-medium">{t('ndvi.legend')}</span>
          <div className="h-2.5 w-full rounded-full" style={{ background: NDVI_GRADIENT }} dir="ltr" />
          <div className="flex justify-between text-muted-foreground" dir="ltr">
            <span>0.1</span>
            <span>0.3</span>
            <span>0.6+</span>
          </div>
          <div className="flex justify-between text-muted-foreground">
            <span>{t('ndvi.bare')}</span>
            <span>{t('ndvi.dense')}</span>
          </div>
          <span className="flex items-center gap-1.5 text-muted-foreground">
            <span className="size-2.5 rounded-full" style={{ backgroundColor: NDVI_NONE }} />
            {t('ndvi.noValue')}
          </span>
          {parks.some((x) => x.ndvi?.too_small) && (
            <span className="flex items-center gap-1.5 text-muted-foreground">
              <span className="size-2.5 rounded-full" style={{ backgroundColor: NDVI_NONE }} />
              {t('ndvi.tooSmall')}: {parks.filter((x) => x.ndvi?.too_small).map((x) => parkName(x, i18n.language)).join(', ')}
            </span>
          )}
          {imageDate && (
            <span className="text-muted-foreground">
              {t('ndvi.latestImage')}: <bdi>{formatDate(imageDate, true)}</bdi>
            </span>
          )}
        </div>
      )}
    </div>
  )
}

function ParkPopup({ park }: { park: Park }) {
  const { t, i18n } = useTranslation()
  const base = useBasePath()
  const latest = park.latest
  const openAlerts = useOpenAlerts()
  // Already sorted: priority first, then oldest.
  const alerts = (openAlerts.data ?? []).filter((alert) => alert.park_id === park.id)

  return (
    <div dir={i18n.dir()} className="flex min-w-52 flex-col gap-2 p-1 text-sm text-neutral-900">
      <div className="flex items-start justify-between gap-2 pe-4">
        <span className="font-semibold">{parkName(park, i18n.language)}</span>
      </div>
      {park.company && <span className="-mt-1.5 text-xs text-neutral-500">{park.company.name}</span>}
      <div className="flex items-center gap-1.5">
        <StatusPill status={park.status} />
        {park.monitoring_mode ? <span className="text-xs text-neutral-500">{t(`mode.${park.monitoring_mode}`)}</span> : <SourceBadge source={park.source} />}
      </div>
      {park.camera && (
        <div className="flex items-center gap-1.5 text-xs" title={t('camera.title')}>
          <Camera className="size-3.5 text-neutral-500" />
          <span className={cn('font-medium', (park.camera.last_count ?? 0) > 0 ? 'text-amber-700' : 'text-green-700')}>
            {park.camera.last_frame_at == null ? t('camera.noFrames') : (park.camera.last_count ?? 0) > 0 ? t('camera.items', { count: park.camera.last_count }) : t('camera.clean')}
          </span>
          {park.camera.last_frame_at && <span className="text-neutral-500">{formatAgo(park.camera.last_frame_at)}</span>}
        </div>
      )}
      {alerts.length > 0 && (
        <ul className="flex flex-col gap-1">
          {alerts.map((alert) => {
            const meta = CATEGORY_META[alert.category]
            return (
              <li key={alert.id} className={cn('flex items-center gap-1.5 border-s-4 ps-1.5 text-xs', meta.frame)}>
                {/* The popup is always light: use the light colour of the category. */}
                <span className={cn('[&_svg]:size-3.5', meta.text.split(' ')[0])} title={t(`category.${alert.category}`)}>
                  {meta.icon}
                </span>
                <span className="font-medium">{t(`alertType.${alert.type}`)}</span>
                <PriorityBadge priority={alert.priority} className="ms-auto px-1 py-0 text-[11px]" />
              </li>
            )
          })}
        </ul>
      )}
      <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
        <dt className="text-neutral-500">{t('park.moisture')}</dt>
        <dd className="font-medium">{latest?.moisture_pct != null ? `${formatNumber(latest.moisture_pct)} %` : '—'}</dd>
        <dt className="text-neutral-500">{t('park.temperature')}</dt>
        <dd className="font-medium">{latest?.temperature != null ? `${formatNumber(latest.temperature)} °C` : '—'}</dd>
        <dt className="text-neutral-500">{t('park.humidity')}</dt>
        <dd className="font-medium">{latest?.humidity != null ? `${formatNumber(latest.humidity)} %` : '—'}</dd>
        <dt className="text-neutral-500">NDVI</dt>
        <dd className="flex items-center gap-1 font-medium">
          {park.ndvi?.too_small ? (
            <span className="font-normal text-neutral-500">{t('ndvi.tooSmall')}</span>
          ) : park.ndvi && park.ndvi.mean != null ? (
            <>
              <span className="size-2.5 rounded-full" style={{ backgroundColor: ndviColor(park.ndvi.mean) }} />
              {formatNumber(park.ndvi.mean, 2)}
            </>
          ) : (
            '—'
          )}
        </dd>
        <dd className="col-span-2 -mt-0.5 text-neutral-500">
          {park.ndvi?.date ? (
            <>
              {t('ndvi.latestImage')}: <bdi>{formatDate(park.ndvi.date, true)}</bdi>
            </>
          ) : (
            t('ndvi.noImage')
          )}
        </dd>
        {park.monitoring_mode !== 'satellite_only' && park.status !== 'no_sensor' && (
          <>
            <dt className="text-neutral-500">{t('admin.lastReading')}</dt>
            <dd className={cn('font-medium', !park.last_reading_at && 'text-neutral-500')}>
              {park.last_reading_at ? formatDateTime(park.last_reading_at) : t('admin.noReadingYet')}
            </dd>
          </>
        )}
      </dl>
      <Link to={`${base}/parks/${park.id}`} className="text-xs font-medium text-green-700 underline-offset-4 hover:underline">
        {t('admin.openPark')}
      </Link>
    </div>
  )
}
