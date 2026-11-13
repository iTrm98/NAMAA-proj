import type { ReactNode } from 'react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Marker, useMap } from 'react-map-gl/maplibre'
import { STATUS_COLORS } from '@/components/StatusPill'
import { parkName } from '@/hooks/useParks'
import { buildIndex, clustersFor, expansionZoom, LABEL_ZOOM, mapParks, type Bounds } from '@/lib/parkClusters'
import { cn } from '@/lib/utils'
import type { Park } from '@/types'

interface Props {
  parks: Park[]
  selectedId: number | null
  onSelect: (park: Park) => void
  /** Colour of a park's dot (status or NDVI layer). */
  colorOf: (park: Park) => string
  /** Short text next to the name at label zoom. */
  detail?: (park: Park) => string | null
}

/**
 * Parks on the map as HTML markers: clusters and 10 px dots below zoom 13, name labels from 13.
 * Must sit inside a react-map-gl Map. Demo-stand and archived parks are dropped here.
 */
export function ParkMarkers({ parks, selectedId, onSelect, colorOf, detail }: Props) {
  const { t, i18n } = useTranslation()
  const { current: map } = useMap()
  const [view, setView] = useState<{ bounds: Bounds; zoom: number } | null>(null)

  const visible = useMemo(() => mapParks(parks), [parks])
  const index = useMemo(() => buildIndex(visible), [visible])

  const sync = useCallback(() => {
    if (!map) return
    const b = map.getBounds()
    setView({ bounds: [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()], zoom: map.getZoom() })
  }, [map])

  useEffect(() => {
    if (!map) return
    sync()
    map.on('moveend', sync)
    map.on('load', sync)
    return () => {
      map.off('moveend', sync)
      map.off('load', sync)
    }
  }, [map, sync])

  const items = useMemo(() => (view ? clustersFor(index, visible, view.bounds, view.zoom) : []), [index, visible, view])
  const labels = (view?.zoom ?? 0) >= LABEL_ZOOM

  return (
    <>
      {items.map((item): ReactNode => {
        if (item.kind === 'cluster') {
          const color = STATUS_COLORS[item.status]
          return (
            <Marker
              key={`c${item.id}`}
              longitude={item.lng}
              latitude={item.lat}
              anchor="center"
              onClick={(e) => {
                e.originalEvent.stopPropagation()
                map?.flyTo({ center: [item.lng, item.lat], zoom: expansionZoom(index, item.id), duration: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 500 })
              }}
            >
              <button
                type="button"
                aria-label={t('map.cluster', { count: item.count })}
                className="flex size-9 items-center justify-center rounded-full border-2 bg-[rgba(16,18,14,.85)] text-[13px] font-medium text-[var(--text)] tabular-nums"
                style={{ borderColor: color }}
              >
                {item.count}
              </button>
            </Marker>
          )
        }
        const park = item.park
        const ring = park.status === 'awaiting_install'
        const on = park.id === selectedId
        const name = parkName(park, i18n.language)
        const extra = labels ? detail?.(park) : null
        return (
          <Marker
            key={park.id}
            longitude={item.lng}
            latitude={item.lat}
            anchor="center"
            onClick={(e) => {
              e.originalEvent.stopPropagation()
              onSelect(park)
            }}
          >
            <button
              type="button"
              dir={i18n.dir()}
              aria-label={`${name}, ${t(`status.${park.status}`)}`}
              title={name}
              className={cn(
                'flex items-center gap-2 whitespace-nowrap text-[13px] text-[var(--text)]',
                labels
                  ? cn('rounded-full bg-[rgba(16,18,14,.82)] py-[7px] ps-2 pe-3', ring ? 'border border-dashed border-white/30' : 'border border-white/15')
                  : 'size-6 justify-center',
                on && 'outline-2 outline-offset-2 outline-[var(--accent-lime)] rounded-full',
              )}
            >
              <span
                className={cn('shrink-0 rounded-full border-[1.5px]', labels ? 'size-3' : 'size-2.5 border-white/40')}
                style={ring ? { borderColor: STATUS_COLORS.awaiting_install } : { background: colorOf(park), borderColor: labels ? 'transparent' : undefined }}
                aria-hidden
              />
              {labels && <span className="font-medium">{name}</span>}
              {extra && <span className="text-xs text-[var(--text-muted)]">{extra}</span>}
            </button>
          </Marker>
        )
      })}
    </>
  )
}
