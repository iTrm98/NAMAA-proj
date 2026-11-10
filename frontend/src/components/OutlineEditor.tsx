import 'maplibre-gl/dist/maplibre-gl.css'
import turfArea from '@turf/area'
import type { Polygon } from 'geojson'
import type { Map as MapLibreMap } from 'maplibre-gl'
import { Pencil, RotateCcw, Save } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import Map, { NavigationControl } from 'react-map-gl/maplibre'
import { toast } from 'sonner'
import { TerraDraw, TerraDrawPolygonMode, TerraDrawSelectMode } from 'terra-draw'
import { TerraDrawMapLibreGLAdapter } from 'terra-draw-maplibre-gl-adapter'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { useSaveOutline } from '@/hooks/useOps'
import { parkName } from '@/hooks/useParks'
import { ApiError } from '@/lib/api'
import { formatNumber } from '@/lib/format'
import { mapStyle, MAPTILER_KEY } from '@/lib/mapStyle'
import type { ParkDetail } from '@/types'

const MODE_SELECT = 'select'
const MODE_POLYGON = 'polygon'
const OUTLINE = '#facc15'

/** Bounds of a ring with a small margin. */
function boundsOf(ring: number[][]): [[number, number], [number, number]] {
  const lngs = ring.map((c) => c[0])
  const lats = ring.map((c) => c[1])
  return [
    [Math.min(...lngs), Math.min(...lats)],
    [Math.max(...lngs), Math.max(...lats)],
  ]
}

/** The first polygon drawn on the canvas, or null. */
function currentPolygon(draw: TerraDraw | null): Polygon | null {
  const feature = draw?.getSnapshot().find((f) => f.geometry.type === 'Polygon')
  return feature ? (feature.geometry as Polygon) : null
}

/**
 * Outline editor (Sprint 3.1, D2): the park on satellite imagery with its outline
 * editable (drag points, click a midpoint to add one, right-click a point to
 * delete it) or redrawn. The area is geodesic (@turf/area, the same formula as
 * the server) and updates live. Save asks for the source: traced or official.
 */
export function OutlineEditor({ park, open, onOpenChange }: { park: ParkDetail; open: boolean; onOpenChange: (open: boolean) => void }) {
  const { t, i18n } = useTranslation()
  const save = useSaveOutline(park.id)
  const drawRef = useRef<TerraDraw | null>(null)
  const [area, setArea] = useState<number | null>(park.area_m2)
  const [drawing, setDrawing] = useState(false)
  const [source, setSource] = useState<'traced' | 'official'>('traced')
  const [reference, setReference] = useState('')
  const [error, setError] = useState<string | null>(null)
  const ring = park.polygon?.coordinates?.[0] ?? [[park.lng, park.lat]]

  const refresh = useCallback(() => {
    const polygon = currentPolygon(drawRef.current)
    setArea(polygon ? Math.round(turfArea(polygon)) : null)
  }, [])

  const loadCurrent = useCallback(() => {
    const draw = drawRef.current
    if (!draw) return
    draw.clear()
    draw.setMode(MODE_SELECT)
    setDrawing(false)
    if (park.polygon?.type === 'Polygon') {
      const id = crypto.randomUUID()
      draw.addFeatures([{ id, type: 'Feature', geometry: park.polygon, properties: { mode: MODE_POLYGON } }])
      draw.selectFeature(id)
    }
    refresh()
  }, [park.polygon, refresh])

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
            styles: { selectedPolygonColor: OUTLINE, selectedPolygonOutlineColor: OUTLINE, selectedPolygonFillOpacity: 0.15, selectionPointColor: OUTLINE, midPointColor: '#ffffff' },
          }),
          new TerraDrawPolygonMode({ styles: { fillColor: OUTLINE, outlineColor: OUTLINE, fillOpacity: 0.15 } }),
        ],
      })
      draw.start()
      draw.on('change', refresh)
      draw.on('finish', (id) => {
        // A redraw is finished: back to editing it.
        draw.setMode(MODE_SELECT)
        draw.selectFeature(id)
        setDrawing(false)
        refresh()
      })
      drawRef.current = draw
      loadCurrent()
    },
    [loadCurrent, refresh],
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

  const redraw = () => {
    const draw = drawRef.current
    if (!draw) return
    draw.clear()
    draw.setMode(MODE_POLYGON)
    setDrawing(true)
    setArea(null)
  }

  const submit = () => {
    const polygon = currentPolygon(drawRef.current)
    if (!polygon) {
      setError(t('outline.needPolygon'))
      return
    }
    setError(null)
    save.mutate(
      { polygon, source, reference: reference.trim() || null },
      {
        onSuccess: () => {
          toast.success(t('outline.saved'))
          onOpenChange(false)
        },
        onError: (e) => setError(e instanceof ApiError && e.body?.message ? e.body.message : t('common.error')),
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[95vh] w-[95vw] max-w-5xl flex-col gap-3 sm:max-w-5xl">
        <DialogHeader>
          <DialogTitle>{t('outline.title', { park: parkName(park, i18n.language) })}</DialogTitle>
          <DialogDescription>{drawing ? t('outline.drawHint') : t('outline.editHint')}</DialogDescription>
        </DialogHeader>

        <div className="relative h-[60vh] min-h-[320px] overflow-hidden rounded-lg border">
          {open && (
            <Map
              initialViewState={{ bounds: boundsOf(ring), fitBoundsOptions: { padding: 60, maxZoom: 19 } }}
              mapStyle={mapStyle('satellite')}
              style={{ width: '100%', height: '100%' }}
              onLoad={(e) => onLoad(e.target)}
              maxZoom={20}
            >
              <NavigationControl position="top-left" showCompass={false} />
            </Map>
          )}
          <div className="absolute end-2 top-2 rounded-md bg-background/90 px-3 py-1.5 text-sm shadow">
            {t('outline.area')}: <span className="font-semibold tabular-nums">{area == null ? '—' : `${formatNumber(area, 0)} m²`}</span>
          </div>
          {!MAPTILER_KEY && <div className="absolute bottom-2 start-2 rounded-md bg-background/90 px-2 py-1 text-xs text-muted-foreground">{t('outline.noSatellite')}</div>}
        </div>

        <div className="flex flex-wrap items-end gap-3">
          <Button type="button" variant="outline" size="sm" onClick={redraw} disabled={drawing}>
            <Pencil />
            {t('outline.redraw')}
          </Button>
          <Button type="button" variant="ghost" size="sm" onClick={loadCurrent}>
            <RotateCcw />
            {t('outline.reset')}
          </Button>
          <label className="ms-auto flex items-center gap-2 text-sm">
            {t('outline.source')}
            <select className="rounded-md border bg-background px-2 py-1" value={source} onChange={(e) => setSource(e.target.value as 'traced' | 'official')}>
              <option value="traced">{t('sources.type.traced')}</option>
              <option value="official">{t('sources.type.official')}</option>
            </select>
          </label>
          <Input
            className="w-64"
            value={reference}
            onChange={(e) => setReference(e.target.value)}
            placeholder={source === 'official' ? t('outline.referenceRequired') : t('outline.referenceOptional')}
            aria-label={t('outline.reference')}
          />
        </div>
        {error && <p className="text-sm text-destructive">{error}</p>}

        <DialogFooter>
          <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button type="button" onClick={submit} disabled={save.isPending || drawing || (source === 'official' && !reference.trim())}>
            <Save />
            {save.isPending ? t('outline.saving') : t('outline.save')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
