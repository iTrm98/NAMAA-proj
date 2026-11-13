import type { TFunction } from 'i18next'
import 'maplibre-gl/dist/maplibre-gl.css'
import type { FeatureCollection, MultiPolygon, Polygon } from 'geojson'
import { Maximize2, Minus, Plus, X } from 'lucide-react'
import { type ReactNode, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import Map, { Layer, type MapLayerMouseEvent, type MapRef, Source } from 'react-map-gl/maplibre'
import { Link } from 'react-router'
import { Chip, GlassCard, Segmented } from '@/components/ds'
import type { ChipTone } from '@/components/ds'
import { STATUS_COLORS } from '@/components/StatusPill'
import { parkName } from '@/hooks/useParks'
import { isAutoWatering } from '@/lib/irrigation'
import { formatNumber } from '@/lib/format'
import { MAKKAH, MAPTILER_KEY, mapShellProps, mapStyle, useBasemap } from '@/lib/mapStyle'
import { ParkMarkers } from '@/components/map/ParkMarkers'
import { SatelliteNote } from '@/components/map/SatelliteNote'
import { isAreaGeometry, outerPoints } from '@/lib/geo'
import { mapParks, OUTLINE_ZOOM } from '@/lib/parkClusters'
import { NdviPixels, PIXEL_ZOOM } from '@/components/map/NdviPixels'
import { SatelliteLegendLines, SatelliteSummary } from '@/components/map/SatelliteBlocks'
import { greenCoverLabel } from '@/lib/satellite'
import { NDVI_GRADIENT, NDVI_NONE, ndviColor } from '@/lib/ndvi'
import { useBasePath } from '@/lib/scope'
import { cn } from '@/lib/utils'
import type { Park } from '@/types'

type MapLayer = 'status' | 'ndvi'
type Tr = TFunction

/** Event name the Rail "map layers" button sends to show or hide the map controls. */
export const MAP_LAYERS_EVENT = 'namaa:map-layers'

function boundsOf(parks: Park[]): [[number, number], [number, number]] | null {
  const points = parks.flatMap((p) => { const pts = outerPoints(p.polygon); return pts.length ? pts : [[p.lng, p.lat] as [number, number]] })
  if (points.length === 0) return null
  const lngs = points.map((c) => c[0])
  const lats = points.map((c) => c[1])
  return [
    [Math.min(...lngs), Math.min(...lats)],
    [Math.max(...lngs), Math.max(...lats)],
  ]
}

const FIT = { padding: { top: 90, bottom: 100, left: 70, right: 70 }, maxZoom: 16, duration: 600 }
const SOLID = 'border border-[var(--glass-border-strong)] bg-[var(--solid-over-map)]'

function statusTone(status: Park['status']): ChipTone {
  return status === 'ok' ? 'ok' : status === 'dry' ? 'high' : status === 'over' ? 'normal' : 'muted'
}

/** Short line next to the park name on its pin: moisture, or the state when there is no reading. */
function pinLabel(park: Park, t: Tr): string {
  if (park.status === 'no_sensor') return t('mode.satellite_only')
  if (park.status === 'awaiting_install') return t('overview.awaitingShort')
  if (isAutoWatering(park)) return t('overview.wateringAuto')
  const m = park.latest?.moisture_pct
  return m != null ? `${formatNumber(m)}%` : t(`status.${park.status}`)
}

/** The live map: every park named, status colours, grey rings for parks awaiting installation, solid controls, side card. */
export function OverviewMap({ parks: allParks, selectedId: controlledId, onSelect, className }: { parks: Park[]; selectedId?: number | null; onSelect?: (id: number | null) => void; className?: string }) {
  const { t, i18n } = useTranslation()
  const base = useBasePath()
  const parks = useMemo(() => mapParks(allParks), [allParks])
  const mapRef = useRef<MapRef>(null)
  const [basemap, setBasemap] = useBasemap()
  const [layer, setLayer] = useState<MapLayer>('status')
  const [ownId, setOwnId] = useState<number | null>(null)
  const selectedId = controlledId !== undefined ? controlledId : ownId
  const setSelectedId = (id: number | null) => {
    setOwnId(id)
    onSelect?.(id)
  }
  const [controls, setControls] = useState(true)
  const [zoom, setZoom] = useState(0)
  const [bounds, setBounds] = useState<[number, number, number, number] | null>(null)
  const selected = parks.find((p) => p.id === selectedId) ?? null
  const imageDate = parks.reduce<string | null>((newest, p) => (p.ndvi && (!newest || p.ndvi.date > newest) ? p.ndvi.date : newest), null)

  useEffect(() => {
    const toggle = () => setControls((v) => !v)
    window.addEventListener(MAP_LAYERS_EVENT, toggle)
    return () => window.removeEventListener(MAP_LAYERS_EVENT, toggle)
  }, [])

  const syncBounds = useCallback(() => {
    const b = mapRef.current?.getBounds()
    if (b) setBounds([b.getWest(), b.getSouth(), b.getEast(), b.getNorth()])
  }, [])
  // Parks whose centre is in view while the pixel view is on (vegetation mode, zoom 15+).
  const pixelParks = useMemo(
    () => (layer === 'ndvi' && zoom >= PIXEL_ZOOM && bounds ? parks.filter((p) => p.satellite?.has_pixels && p.lng >= bounds[0] - 0.01 && p.lng <= bounds[2] + 0.01 && p.lat >= bounds[1] - 0.01 && p.lat <= bounds[3] + 0.01) : []),
    [layer, zoom, bounds, parks],
  )

  const colorOf = useCallback((p: Park) => (layer === 'ndvi' ? ndviColor(p.ndvi?.too_small ? null : p.ndvi?.mean) : STATUS_COLORS[p.status]), [layer])

  const features = useMemo<FeatureCollection<Polygon | MultiPolygon>>(
    () => ({
      type: 'FeatureCollection',
      features: parks
        .filter((p) => isAreaGeometry(p.polygon))
        .map((p) => ({
          type: 'Feature',
          id: p.id,
          geometry: p.polygon as Polygon | MultiPolygon,
          properties: { id: p.id, color: colorOf(p), awaiting: layer === 'status' && p.status === 'awaiting_install' },
        })),
    }),
    [parks, colorOf, layer],
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

  // Computed once: the first view fits every park; polling must not move the map.
  const [initialView] = useState(() => {
    const bounds = boundsOf(parks)
    return bounds ? { bounds, fitBoundsOptions: { padding: FIT.padding, maxZoom: 11 } } : MAKKAH
  })

  const fit = useCallback(() => {
    const bounds = boundsOf(parks)
    if (bounds) mapRef.current?.fitBounds(bounds, FIT)
  }, [parks])

  function pick(park: Park) {
    setSelectedId(park.id)
    const map = mapRef.current
    if (map) map.flyTo({ center: [park.lng, park.lat], zoom: Math.max(map.getZoom(), 15), duration: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 700 })
  }

  function onClick(event: MapLayerMouseEvent) {
    const id = event.features?.[0]?.properties?.id
    const park = typeof id === 'number' ? parks.find((p) => p.id === id) : undefined
    if (park) pick(park)
    else setSelectedId(null)
  }

  return (
    <GlassCard solid padding={0} className={cn('relative h-[500px] min-h-[460px] min-w-0 flex-[5_1_620px] overflow-hidden', className)} dir="ltr">
      <Map
        ref={mapRef}
        initialViewState={initialView}
        mapStyle={mapStyle(basemap)}
        {...mapShellProps(basemap)}
        interactiveLayerIds={['park-fill']}
        onClick={onClick}
        onZoom={(e) => setZoom(e.viewState.zoom)}
        onMoveEnd={syncBounds}
        onLoad={(e) => {
          setZoom(e.target.getZoom())
          syncBounds()
        }}
        cursor="auto"
        style={{ width: '100%', height: '100%' }}
      >
        <Source id="parks" type="geojson" data={features}>
          <Layer id="park-fill" type="fill" minzoom={OUTLINE_ZOOM} paint={{ 'fill-color': ['get', 'color'], 'fill-opacity': ['case', ['get', 'awaiting'], 0.06, 0.4] }} />
          <Layer id="park-outline" type="line" minzoom={OUTLINE_ZOOM} paint={{ 'line-color': ['get', 'color'], 'line-width': 2 }} />
        </Source>

        {/* Green zone (Sprint 6.2): a distinct dashed green, drawn above the boundary, only where a park has one. */}
        <Source id="green-zones" type="geojson" data={greenZones}>
          <Layer id="green-zone-fill" type="fill" minzoom={OUTLINE_ZOOM} paint={{ 'fill-color': '#8BE36B', 'fill-opacity': 0.3 }} />
          <Layer id="green-zone-line" type="line" minzoom={OUTLINE_ZOOM} paint={{ 'line-color': '#8BE36B', 'line-width': 1.5, 'line-dasharray': [2, 1] }} />
        </Source>

        {pixelParks.length > 0 && <NdviPixels parks={pixelParks} />}

        <ParkMarkers parks={parks} selectedId={selectedId} onSelect={pick} colorOf={colorOf} detail={(p) => (layer === 'ndvi' ? greenCoverLabel(p) : pinLabel(p, t))} />
      </Map>
      <SatelliteNote basemap={basemap} />

      <div className="pointer-events-none absolute inset-x-4 top-4 flex flex-wrap items-center justify-between gap-2.5" dir={i18n.dir()}>
        <div className={cn('pointer-events-auto flex items-center gap-2.5 rounded-full px-3.5 py-2', SOLID)}>
          <h2 className="m-0 text-[15px] font-medium">{t('overview.liveMap')}</h2>
          <span className="text-xs text-text-2 max-2xl:hidden">{t('overview.mapHint')}</span>
        </div>
        {controls && (
          <div className="pointer-events-auto flex flex-wrap gap-2">
            <Segmented
              ariaLabel={t('ndvi.layer')}
              value={layer}
              onChange={setLayer}
              options={[
                { value: 'status', label: t('ndvi.layer_status') },
                { value: 'ndvi', label: t('overview.vegetation') },
              ]}
              className="!bg-[var(--solid-over-map)]"
            />
            <Segmented
              ariaLabel={t('overview.baseMap')}
              value={basemap}
              onChange={setBasemap}
              options={[
                ...(MAPTILER_KEY
                  ? [
                      { value: 'satellite' as const, label: t('admin.satellite') },
                      { value: 'streets' as const, label: t('admin.streets') },
                    ]
                  : [{ value: 'streets' as const, label: t('admin.streets') }]),
                { value: 'esri' as const, label: t('map.esri') },
              ]}
              className="!bg-[var(--solid-over-map)]"
            />
          </div>
        )}
      </div>

      {selected && (
        <div
          dir={i18n.dir()}
          className="absolute end-4 top-[72px] flex w-[300px] max-w-[calc(100%-32px)] flex-col gap-2.5 rounded-[20px] border border-white/[.14] bg-[rgba(16,18,14,.9)] p-4 max-sm:end-auto max-sm:start-4 max-sm:top-auto max-sm:bottom-[72px]"
          role="dialog"
          aria-label={parkName(selected, i18n.language)}
        >
          <div className="flex items-center justify-between gap-2">
            <h3 className="m-0 text-base font-medium">{parkName(selected, i18n.language)}</h3>
            <button
              type="button"
              onClick={() => setSelectedId(null)}
              aria-label={t('common.close')}
              className="flex size-8 items-center justify-center rounded-full border border-[var(--glass-border)] bg-white/[.06]"
            >
              <X className="size-4" />
            </button>
          </div>
          <div className="flex flex-wrap gap-1.5">
            <Chip tone={statusTone(selected.status)}>{t(`status.${selected.status}`)}</Chip>
            {isAutoWatering(selected) && <Chip tone="normal">{t('overview.wateringAuto')}</Chip>}
            {selected.monitoring_mode ? <Chip tone="muted">{t(`mode.${selected.monitoring_mode}`)}</Chip> : <Chip tone="muted">{t(`source.${selected.source}`)}</Chip>}
          </div>
          <p className="m-0 text-[13px] leading-normal text-[var(--chip-muted-fg)]">
            {selected.open_alerts_count > 0 ? t('admin.openAlerts', { count: selected.open_alerts_count }) : t('overview.noOpenAlerts')}
          </p>
          <SatelliteSummary park={selected} />
          <Link
            to={`${base}/parks/${selected.id}`}
            className="flex h-10 items-center justify-center rounded-full bg-[var(--accent-lime)] text-[13px] font-medium text-[var(--on-accent)] no-underline"
          >
            {t('overview.openPark')}
          </Link>
        </div>
      )}

      <div className={cn('absolute start-4 bottom-9 flex flex-wrap gap-3.5 rounded-2xl px-3.5 py-2.5 text-xs text-[var(--chip-muted-fg)] max-sm:hidden', SOLID)} dir={i18n.dir()}>
        {layer === 'status' ? (
          <>
            <Legend color={STATUS_COLORS.ok} label={t('status.ok')} />
            <Legend color={STATUS_COLORS.dry} label={t('overview.needsWater')} />
            <Legend color={STATUS_COLORS.over} label={t('status.over')} />
            <Legend color={STATUS_COLORS.awaiting_install} label={t('status.awaiting_install')} />
            <Legend color="var(--text-muted)" label={t('map.legendSimulated')} />
            <Legend ring label={t('map.legendSatellite')} />
            {parks.some((p) => p.status === 'no_sensor') && <Legend color={STATUS_COLORS.no_sensor} label={t('status.no_sensor')} />}
          </>
        ) : (
          <div className="flex w-52 flex-col gap-1">
            <div className="h-2 rounded-full" style={{ background: NDVI_GRADIENT }} />
            <div className="flex justify-between" dir="ltr">
              <span>0.1</span>
              <span>0.3</span>
              <span>0.6+</span>
            </div>
            <span className="flex items-center gap-1.5">
              <span className="size-2.5 rounded-full" style={{ backgroundColor: NDVI_NONE }} />
              {t('ndvi.noValue')}
            </span>
            <SatelliteLegendLines imageDate={imageDate} />
          </div>
        )}
      </div>

      <div className="absolute end-4 bottom-9 flex flex-col items-center gap-1.5" dir={i18n.dir()}>
        <MapButton label={t('overview.zoomIn')} onClick={() => mapRef.current?.zoomIn()}>
          <Plus />
        </MapButton>
        <span className="text-[11px] text-[var(--text-muted)] tabular-nums">{formatNumber(zoom, 1)}</span>
        <MapButton label={t('overview.zoomOut')} onClick={() => mapRef.current?.zoomOut()}>
          <Minus />
        </MapButton>
        <MapButton label={t('overview.fitAll')} onClick={fit}>
          <Maximize2 />
        </MapButton>
      </div>
    </GlassCard>
  )
}

function Legend({ color, ring, label }: { color?: string; ring?: boolean; label: string }) {
  return (
    <span className="flex items-center gap-1.5">
      <span className="size-[9px] rounded-full border-[1.5px] border-transparent" style={ring ? { borderColor: 'var(--text-muted)' } : { background: color }} aria-hidden />
      {label}
    </span>
  )
}

function MapButton({ label, onClick, children }: { label: string; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      onClick={onClick}
      className="flex size-11 items-center justify-center rounded-full border border-[var(--glass-border)] bg-[var(--solid-over-map)] text-[var(--text)] hover:bg-white/10 [&>svg]:size-4"
    >
      {children}
    </button>
  )
}
