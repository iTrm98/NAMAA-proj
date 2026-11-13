import type { ReactNode } from 'react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Marker, useMap } from 'react-map-gl/maplibre'
import { STATUS_COLORS } from '@/components/StatusPill'
import { parkName } from '@/hooks/useParks'
import { buildIndex, clustersFor, expansionZoom, isSatelliteOnly, labelWidth, mapParks, placeLabels, type Bounds, type Side, VALUE_ZOOM } from '@/lib/parkClusters'
import { cn } from '@/lib/utils'
import type { Park } from '@/types'

interface Props {
  parks: Park[]
  selectedId: number | null
  onSelect: (park: Park) => void
  /** Colour of a park's dot (status or NDVI layer). */
  colorOf: (park: Park) => string
  /** Short value next to the name from zoom 14 (moisture, green cover). */
  detail?: (park: Park) => string | null
}

const PILL = 'rounded-full bg-[rgba(16,18,14,.82)] px-2 py-[3px] text-[12px] leading-none font-medium whitespace-nowrap text-white [text-shadow:0_1px_2px_rgba(0,0,0,.8)]'

const SIDE_STYLE: Record<Side, React.CSSProperties> = {
  right: { left: 'calc(100% + 2px)', top: '50%', transform: 'translateY(-50%)' },
  left: { right: 'calc(100% + 2px)', top: '50%', transform: 'translateY(-50%)' },
  top: { bottom: 'calc(100% + 2px)', left: '50%', transform: 'translateX(-50%)' },
  bottom: { top: 'calc(100% + 2px)', left: '50%', transform: 'translateX(-50%)' },
}

/**
 * Parks on every map as HTML markers: a status dot and the park name, always visible. Labels that would
 * collide move to another side of their dot; only markers that truly overlap are clustered (never from
 * zoom 12). Values appear from zoom 14. Must sit inside a react-map-gl Map.
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
  const values = (view?.zoom ?? 0) >= VALUE_ZOOM

  const sides = useMemo(() => {
    if (!map || !view) return new Map<string, Side>()
    return placeLabels(
      items.map((item) => {
        const p = map.project([item.lng, item.lat])
        if (item.kind === 'cluster') return { key: `c${item.id}`, x: p.x, y: p.y, w: 120, h: 14 + 16 * item.parkIds.length }
        const text = parkName(item.park, i18n.language) + (values ? ` ${detail?.(item.park) ?? ''}` : '')
        return { key: `p${item.park.id}`, x: p.x, y: p.y, w: labelWidth(text) }
      }),
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [map, view, items, i18n.language, values])

  return (
    <>
      {items.map((item): ReactNode => {
        if (item.kind === 'cluster') {
          const color = STATUS_COLORS[item.status]
          const names = item.parkIds.map((id) => visible.find((p) => p.id === id)).filter((p): p is Park => !!p)
          return (
            <Marker
              key={`c${item.id}`}
              longitude={item.lng}
              latitude={item.lat}
              anchor="center"
              style={{ zIndex: 2 }}
              onClick={(e) => {
                e.originalEvent.stopPropagation()
                map?.flyTo({ center: [item.lng, item.lat], zoom: expansionZoom(index, item.id), duration: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 500 })
              }}
            >
              <button
                type="button"
                aria-label={t('map.cluster', { count: item.count })}
                className="namaa-marker relative flex size-[26px] items-center justify-center rounded-full border-2 border-white text-[12px] font-semibold text-[#10120e] tabular-nums"
                style={{ background: color }}
              >
                {item.count}
                <span dir={i18n.dir()} className="absolute top-full left-1/2 mt-1 flex -translate-x-1/2 flex-col items-center gap-0.5 font-normal">
                  {names.map((p) => (
                    <span key={p.id} className={PILL}>
                      {parkName(p, i18n.language)}
                    </span>
                  ))}
                  {item.count > names.length && <span className={PILL}>+{item.count - names.length}</span>}
                </span>
              </button>
            </Marker>
          )
        }
        const park = item.park
        const ring = isSatelliteOnly(park)
        const on = park.id === selectedId
        const name = parkName(park, i18n.language)
        const extra = values ? detail?.(park) : null
        const side = sides.get(`p${park.id}`) ?? 'right'
        const color = colorOf(park)
        return (
          <Marker
            key={park.id}
            longitude={item.lng}
            latitude={item.lat}
            anchor="center"
            style={{ zIndex: on ? 3 : 1 }}
            onClick={(e) => {
              e.originalEvent.stopPropagation()
              onSelect(park)
            }}
          >
            <button
              type="button"
              dir={i18n.dir()}
              aria-label={`${name}, ${t(`status.${park.status}`)}`}
              className="namaa-marker relative flex size-6 items-center justify-center"
            >
              <span
                className={cn('size-[11px] shrink-0 rounded-full', on && 'outline-2 outline-offset-2 outline-[var(--accent-lime)]')}
                style={ring ? { border: `2.5px solid ${color}`, background: 'rgba(16,18,14,.55)' } : { background: color, border: '1.5px solid rgba(255,255,255,.85)' }}
                aria-hidden
              />
              <span className={cn(PILL, 'absolute flex items-center gap-1.5 transition-opacity')} style={SIDE_STYLE[side]}>
                {name}
                {extra && <span className="font-normal text-white/75">{extra}</span>}
              </span>
            </button>
          </Marker>
        )
      })}
    </>
  )
}
