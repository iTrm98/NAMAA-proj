import 'maplibre-gl/dist/maplibre-gl.css'
import turfArea from '@turf/area'
import turfDifference from '@turf/difference'
import turfUnion from '@turf/union'
import type { Feature, MultiPolygon, Polygon } from 'geojson'
import type { Map as MapLibreMap } from 'maplibre-gl'
import { Eraser, Pencil, Wand2, RotateCcw, Save, Scissors, Sprout, TriangleAlert, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import Map, { Layer, NavigationControl, Source } from 'react-map-gl/maplibre'
import { toast } from 'sonner'
import { TerraDraw, TerraDrawPolygonMode, TerraDrawSelectMode } from 'terra-draw'
import { TerraDrawMapLibreGLAdapter } from 'terra-draw-maplibre-gl-adapter'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { type OutlineWarning, useSaveOutline } from '@/hooks/useOps'
import { parkName } from '@/hooks/useParks'
import { api, ApiError } from '@/lib/api'
import { formatNumber } from '@/lib/format'
import { outerPoints } from '@/lib/geo'
import { SatelliteNote } from '@/components/map/SatelliteNote'
import { type Basemap, attributionFor, mapStyle, MAPTILER_KEY } from '@/lib/mapStyle'
import type { ParkDetail } from '@/types'

const MODE_SELECT = 'select'
const MODE_POLYGON = 'polygon'
/** Boundary: yellow (Terra Draw). Green zone: green (a map layer edited with union / difference). */
const BOUNDARY = '#facc15'
const GREEN = '#8BE36B'

type Target = 'boundary' | 'green' | 'cutout'

function boundsOf(ring: number[][]): [[number, number], [number, number]] {
  const lngs = ring.map((c) => c[0])
  const lats = ring.map((c) => c[1])
  return [
    [Math.min(...lngs), Math.min(...lats)],
    [Math.max(...lngs), Math.max(...lats)],
  ]
}

/** The polygon on the Terra Draw canvas (the boundary), or null. */
function currentPolygon(draw: TerraDraw | null): Polygon | null {
  const found = draw?.getSnapshot().find((f) => f.geometry.type === 'Polygon')
  return found ? (found.geometry as Polygon) : null
}

/** Any polygon or multipolygon as a MultiPolygon; null when empty. */
function asMulti(geometry: Polygon | MultiPolygon | null | undefined): MultiPolygon | null {
  if (!geometry) return null
  const multi: MultiPolygon = geometry.type === 'Polygon' ? { type: 'MultiPolygon', coordinates: [geometry.coordinates] } : geometry
  return multi.coordinates.length ? multi : null
}

const toFeature = (g: Polygon | MultiPolygon): Feature<Polygon | MultiPolygon> => ({ type: 'Feature', properties: {}, geometry: g })
const pair = (a: Polygon | MultiPolygon, b: Polygon | MultiPolygon) => ({ type: 'FeatureCollection' as const, features: [toFeature(a), toFeature(b)] })

/** Terra Draw has no holes: the green zone is merged (union) or cut (difference) on the client. */
function mergeGreen(current: MultiPolygon | null, drawn: Polygon, target: 'green' | 'cutout'): MultiPolygon | null {
  if (target === 'green') return asMulti(current ? (turfUnion(pair(current, drawn))?.geometry ?? current) : drawn)
  if (!current) return null
  return asMulti(turfDifference(pair(current, drawn))?.geometry ?? null)
}

function errorLines(e: unknown, fallback: string): string[] {
  if (e instanceof ApiError && e.body) {
    const fields = Object.values(e.body.errors ?? {}).flat()
    return fields.length ? fields : [e.body.message]
  }
  return [fallback]
}

/**
 * Outline editor (Sprint 6.2): two layers on Esri imagery. The boundary is edited with
 * Terra Draw (drag points, midpoints add, right-click deletes) or redrawn. The green zone
 * is a MultiPolygon built on the client: "add green" unions a drawn polygon, "cut out"
 * subtracts one (holes). Both areas are geodesic (@turf/area) and live. The server
 * revalidates and returns warnings. "Verified" is only set when the box is ticked.
 */
export function OutlineEditor({ park, open, onOpenChange }: { park: ParkDetail; open: boolean; onOpenChange: (open: boolean) => void }) {
  const { t, i18n } = useTranslation()
  const save = useSaveOutline(park.id)
  const drawRef = useRef<TerraDraw | null>(null)
  const targetRef = useRef<Target>('boundary')
  const greenRef = useRef<MultiPolygon | null>(null)
  const initialBoundary = park.boundary ?? park.polygon
  const initialGreen = useMemo(() => asMulti(park.green_zone as Polygon | MultiPolygon | null | undefined), [park.green_zone])
  const [boundaryArea, setBoundaryArea] = useState<number | null>(park.boundary_area_m2 ?? park.area_m2)
  const [green, setGreenState] = useState<MultiPolygon | null>(initialGreen)
  const [drawing, setDrawing] = useState<Target | null>(null)
  const [basemap, setBasemap] = useState<Basemap>('esri')
  const [checked, setChecked] = useState(false)
  const [draftNote, setDraftNote] = useState<string | null>(null)
  const [draftWarning, setDraftWarning] = useState(false)
  const [errors, setErrors] = useState<string[]>([])
  const [warnings, setWarnings] = useState<OutlineWarning[]>([])
  // A multi-part boundary is shown read-only (Terra Draw edits single polygons); redrawing replaces it.
  const multiBoundary = initialBoundary?.type === 'MultiPolygon' ? (initialBoundary as MultiPolygon) : null
  const [multiShown, setMultiShown] = useState(multiBoundary !== null)
  const ringPts = outerPoints(initialBoundary)
  const ring = ringPts.length ? ringPts : [[park.lng, park.lat]]

  const setGreen = useCallback((next: MultiPolygon | null) => {
    greenRef.current = next
    setGreenState(next)
  }, [])
  const suggest = useMutation({
    mutationFn: () => api<{ data: { draft: MultiPolygon | null } }>(`/admin/parks/${park.id}/green-zone/suggest`, { method: 'POST' }).then((r) => r.data.draft),
    onSuccess: (draft) => {
      if (draft) {
        // Only a draft: it becomes the editable green zone; nothing is saved until the user saves.
        setGreen(draft)
        setDraftWarning(true)
        setDraftNote(t('outline.draftOk'))
      } else {
        setDraftNote(t('outline.draftEmpty'))
      }
    },
    onError: (e) => {
      setDraftWarning(false)
      setDraftNote(e instanceof ApiError ? (e.status === 503 ? t('outline.suggestUnavailable') : e.status === 422 ? t('outline.suggestInvalid') : t('outline.suggestFailed')) : t('outline.suggestFailed'))
    },
  })
  const greenArea = green ? Math.round(turfArea(toFeature(green))) : null

  const refresh = useCallback(() => {
    const polygon = currentPolygon(drawRef.current)
    if (polygon) {
      setMultiShown(false)
      setBoundaryArea(Math.round(turfArea(polygon)))
    } else if (multiBoundary && drawRef.current?.getSnapshot().length === 0 && targetRef.current === 'boundary') {
      setBoundaryArea(Math.round(turfArea(toFeature(multiBoundary))))
    }
  }, [multiBoundary])

  const loadCurrent = useCallback(() => {
    const draw = drawRef.current
    if (!draw) return
    draw.clear()
    draw.setMode(MODE_SELECT)
    targetRef.current = 'boundary'
    setDrawing(null)
    setGreen(initialGreen)
    setDraftWarning(false)
    setMultiShown(multiBoundary !== null)
    if (initialBoundary?.type === 'Polygon') {
      const id = crypto.randomUUID()
      draw.addFeatures([{ id, type: 'Feature', geometry: initialBoundary, properties: { mode: MODE_POLYGON } }])
      draw.selectFeature(id)
    }
    refresh()
  }, [initialBoundary, initialGreen, multiBoundary, refresh, setGreen])

  const onLoad = useCallback(
    (map: MapLibreMap) => {
      const draw = new TerraDraw({
        adapter: new TerraDrawMapLibreGLAdapter({ map }),
        modes: [
          new TerraDrawSelectMode({
            flags: {
              [MODE_POLYGON]: {
                feature: { draggable: true, coordinates: { midpoints: true, draggable: true, deletable: true } },
              },
            },
            styles: { selectedPolygonColor: BOUNDARY, selectedPolygonOutlineColor: BOUNDARY, selectedPolygonFillOpacity: 0.12, selectionPointColor: BOUNDARY, midPointColor: '#ffffff' },
          }),
          new TerraDrawPolygonMode({ styles: { fillColor: BOUNDARY, outlineColor: BOUNDARY, fillOpacity: 0.12 } }),
        ],
      })
      draw.start()
      draw.on('change', refresh)
      draw.on('finish', (id) => {
        const target = targetRef.current
        draw.setMode(MODE_SELECT)
        setDrawing(null)
        if (target === 'boundary') {
          draw.selectFeature(id)
          refresh()
          return
        }
        // A green or cut-out polygon: merge it into the green zone, then drop the drawn feature.
        const drawn = draw.getSnapshotFeature(id)?.geometry
        draw.removeFeatures([id])
        targetRef.current = 'boundary'
        if (drawn?.type !== 'Polygon') return
        try {
          setGreen(mergeGreen(greenRef.current, drawn as Polygon, target))
        } catch {
          setErrors([t('outline.mergeFailed')])
        }
      })
      drawRef.current = draw
      loadCurrent()
    },
    [loadCurrent, refresh, setGreen, t],
  )

  // Stop the drawing library when the dialog closes (the map is unmounted with it).
  useEffect(() => {
    if (open) return
    try {
      drawRef.current?.stop()
    } catch {
      // the map is already gone
    }
    drawRef.current = null
  }, [open])

  const startDraw = (target: Target) => {
    const draw = drawRef.current
    if (!draw) return
    setErrors([])
    if (target === 'cutout' && !greenRef.current) {
      setErrors([t('outline.cutNeedsGreen')])
      return
    }
    if (target === 'boundary') {
      draw.clear()
      setBoundaryArea(null)
      setMultiShown(false)
    }
    targetRef.current = target
    draw.setMode(MODE_POLYGON)
    setDrawing(target)
  }

  const cancelDraw = () => {
    const draw = drawRef.current
    if (!draw) return
    draw.setMode(MODE_SELECT)
    targetRef.current = 'boundary'
    setDrawing(null)
    // A cancelled boundary redraw leaves nothing on the canvas: put the saved one back.
    if (!currentPolygon(draw) && !multiShown) loadCurrent()
  }

  const submit = () => {
    const boundary = currentPolygon(drawRef.current)
    if (!boundary && !multiShown) {
      setErrors([t('outline.needPolygon')])
      return
    }
    setErrors([])
    setWarnings([])
    save.mutate(
      {
        ...(boundary ? { boundary } : {}),
        ...(green || initialGreen ? { green_zone: green } : {}),
        imagery: basemap === 'esri' ? 'esri' : 'maptiler',
        checked,
      },
      {
        onSuccess: ({ warnings: returned }) => {
          toast.success(t('outline.saved'))
          if (returned.length) setWarnings(returned)
          else onOpenChange(false)
        },
        onError: (e) => setErrors(errorLines(e, t('common.error'))),
      },
    )
  }

  const greenData = useMemo(() => (green ? toFeature(green) : null), [green])
  const hint = drawing === 'boundary' ? t('outline.drawHint') : drawing === 'green' ? t('outline.greenHint') : drawing === 'cutout' ? t('outline.cutHint') : t('outline.editHint')
  const basemaps: Basemap[] = MAPTILER_KEY ? ['esri', 'satellite'] : ['esri']

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[95vh] w-[95vw] max-w-5xl flex-col gap-3 overflow-y-auto sm:max-w-5xl">
        <DialogHeader>
          <DialogTitle>{t('outline.title', { park: parkName(park, i18n.language) })}</DialogTitle>
          <DialogDescription>{hint}</DialogDescription>
        </DialogHeader>

        <div className="relative h-[55vh] min-h-[320px] overflow-hidden rounded-lg border" dir="ltr">
          {open && (
            <Map
              initialViewState={{ bounds: boundsOf(ring), fitBoundsOptions: { padding: 60, maxZoom: 19 } }}
              mapStyle={mapStyle(basemap)}
              attributionControl={{ compact: false, customAttribution: attributionFor(basemap) }}
              style={{ width: '100%', height: '100%' }}
              onLoad={(e) => onLoad(e.target)}
              maxZoom={20}
            >
              <NavigationControl position="top-left" showCompass={false} />
              {multiShown && multiBoundary && (
                <Source id="boundary-multi" type="geojson" data={toFeature(multiBoundary)}>
                  <Layer id="boundary-multi-fill" type="fill" paint={{ 'fill-color': BOUNDARY, 'fill-opacity': 0.12 }} />
                  <Layer id="boundary-multi-line" type="line" paint={{ 'line-color': BOUNDARY, 'line-width': 2 }} />
                </Source>
              )}
              {greenData && (
                <Source id="green-zone" type="geojson" data={greenData}>
                  <Layer id="green-zone-fill" type="fill" paint={{ 'fill-color': GREEN, 'fill-opacity': 0.35 }} />
                  <Layer id="green-zone-line" type="line" paint={{ 'line-color': GREEN, 'line-width': 2, 'line-dasharray': [2, 1] }} />
                </Source>
              )}
            </Map>
          )}
          <SatelliteNote basemap={basemap} className="bottom-8" />
          <div className="absolute end-2 top-2 flex flex-col gap-1 rounded-md bg-[var(--solid-over-map)] px-3 py-2 text-sm" dir={i18n.dir()}>
            <span className="inline-flex items-center gap-2">
              <i className="size-3 rounded-sm" style={{ background: BOUNDARY }} aria-hidden />
              {t('outline.boundaryArea')}: <span className="font-semibold tabular-nums">{boundaryArea == null ? '—' : `${formatNumber(boundaryArea, 0)} m²`}</span>
            </span>
            <span className="inline-flex items-center gap-2">
              <i className="size-3 rounded-sm" style={{ background: GREEN }} aria-hidden />
              {t('outline.greenArea')}: <span className="font-semibold tabular-nums">{greenArea == null ? '—' : `${formatNumber(greenArea, 0)} m²`}</span>
            </span>
          </div>
          {basemaps.length > 1 && (
            <div className="absolute start-2 bottom-8 flex gap-1 rounded-md bg-[var(--solid-over-map)] p-1" role="group" aria-label={t('overview.baseMap')} dir={i18n.dir()}>
              {basemaps.map((b) => (
                <Button key={b} size="sm" variant={basemap === b ? 'default' : 'ghost'} onClick={() => setBasemap(b)} aria-pressed={basemap === b}>
                  {b === 'esri' ? t('map.esri') : t('admin.satellite')}
                </Button>
              ))}
            </div>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-medium">{t('outline.layerBoundary')}</span>
            <Button type="button" variant="outline" size="sm" onClick={() => startDraw('boundary')} disabled={drawing !== null}>
              <Pencil />
              {t('outline.redraw')}
            </Button>
            <Button type="button" variant="ghost" size="sm" onClick={loadCurrent} disabled={drawing !== null}>
              <RotateCcw />
              {t('outline.reset')}
            </Button>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-medium">{t('outline.layerGreen')}</span>
            <Button type="button" variant="outline" size="sm" onClick={() => startDraw('green')} disabled={drawing !== null}>
              <Sprout />
              {t('outline.addGreen')}
            </Button>
            <Button type="button" variant="outline" size="sm" onClick={() => { setDraftNote(null); suggest.mutate() }} disabled={drawing !== null || suggest.isPending}>
              <Wand2 />
              {suggest.isPending ? t('outline.suggesting') : t('outline.suggest')}
            </Button>
            <Button type="button" variant="outline" size="sm" onClick={() => startDraw('cutout')} disabled={drawing !== null || !green}>
              <Scissors />
              {t('outline.cutOut')}
            </Button>
            <Button type="button" variant="ghost" size="sm" onClick={() => setGreen(null)} disabled={drawing !== null || !green}>
              <Eraser />
              {t('outline.clearGreen')}
            </Button>
          </div>
          {drawing && (
            <Button type="button" variant="ghost" size="sm" onClick={cancelDraw}>
              <X />
              {t('outline.cancelDraw')}
            </Button>
          )}
        </div>

        {draftWarning && (
          <p className="m-0 inline-flex items-center gap-1.5 rounded-lg bg-[var(--clean)]/15 p-3 text-sm text-[#FFE2A0]" role="status">
            <TriangleAlert className="size-4 shrink-0" aria-hidden />
            {t('outline.draftWarning')}
          </p>
        )}
        {draftNote && <p className="m-0 text-sm text-text-2" role="status">{draftNote}</p>}

        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="size-4 accent-[var(--accent)]" checked={checked} onChange={(e) => setChecked(e.target.checked)} />
          {t('outline.checked')}
        </label>

        {warnings.length > 0 && (
          <div className="rounded-lg bg-[var(--clean)]/15 p-3 text-sm text-[#FFE2A0]" role="status">
            <p className="m-0 mb-1 inline-flex items-center gap-1.5 font-medium">
              <TriangleAlert className="size-4" aria-hidden />
              {t('outline.warningsTitle')}
            </p>
            <ul className="m-0 ps-5">
              {warnings.map((w) => (
                <li key={w}>{t(`outline.warning.${w}`)}</li>
              ))}
            </ul>
          </div>
        )}
        {errors.length > 0 && (
          <ul className="m-0 list-none p-0 text-sm text-destructive" role="alert">
            {errors.map((line) => (
              <li key={line}>{line}</li>
            ))}
          </ul>
        )}

        <DialogFooter>
          <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
            {warnings.length ? t('common.close') : t('common.cancel')}
          </Button>
          <Button type="button" onClick={submit} disabled={save.isPending || drawing !== null}>
            <Save />
            {save.isPending ? t('outline.saving') : t('outline.save')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
