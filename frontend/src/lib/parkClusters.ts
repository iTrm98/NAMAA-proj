import Supercluster from 'supercluster'
import type { Park, ParkStatus } from '@/types'

/** Worst first: a cluster shows the most urgent status of its parks. */
export const STATUS_RANK: Record<ParkStatus, number> = {
  dry: 5,
  offline: 5,
  over: 4,
  awaiting_install: 3,
  ok: 2,
  no_sensor: 1,
}

/** Parks cluster only when their markers truly overlap on screen. */
export const CLUSTER_RADIUS = 40
/** No clustering from zoom 12 and closer. */
export const CLUSTER_MAX_ZOOM = 11
/** Park outlines (polygons) appear from this zoom. */
export const OUTLINE_ZOOM = 13
/** Values (moisture, green cover) appear next to the name from this zoom. */
export const VALUE_ZOOM = 14

export type Bounds = [number, number, number, number]

interface PointProps {
  parkId: number
  status: ParkStatus
  rank: number
}
interface ClusterProps {
  rank: number
  status: ParkStatus
}

export type ParkIndex = Supercluster<PointProps, ClusterProps>

export type ClusterItem =
  | { kind: 'park'; park: Park; lng: number; lat: number }
  | { kind: 'cluster'; id: number; count: number; status: ParkStatus; lng: number; lat: number; parkIds: number[] }

/** Demo-stand and archived parks never appear on the maps. */
export function mapParks(parks: Park[]): Park[] {
  return parks.filter((p) => p.site_type !== 'demo_stand' && !p.archived_at)
}

export function rankOf(status: ParkStatus): number {
  return STATUS_RANK[status] ?? 0
}

/** Satellite-only parks have no sensors: drawn as a ring instead of a filled dot. */
export function isSatelliteOnly(park: Park): boolean {
  return park.monitoring_mode === 'satellite_only' || park.status === 'no_sensor'
}

export function buildIndex(parks: Park[]): ParkIndex {
  const index = new Supercluster<PointProps, ClusterProps>({
    radius: CLUSTER_RADIUS,
    maxZoom: CLUSTER_MAX_ZOOM,
    map: (p) => ({ rank: p.rank, status: p.status }),
    reduce: (acc, p) => {
      if (p.rank > acc.rank) {
        acc.rank = p.rank
        acc.status = p.status
      }
    },
  })
  index.load(
    parks.map((park) => ({
      type: 'Feature' as const,
      geometry: { type: 'Point' as const, coordinates: [park.lng, park.lat] },
      properties: { parkId: park.id, status: park.status, rank: rankOf(park.status) },
    })),
  )
  return index
}

/** Parks and clusters for the current view. Pure: same input, same output. */
export function clustersFor(index: ParkIndex, parks: Park[], bounds: Bounds, zoom: number): ClusterItem[] {
  const byId = new Map(parks.map((p) => [p.id, p]))
  const out: ClusterItem[] = []
  for (const f of index.getClusters(bounds, Math.floor(zoom))) {
    const [lng, lat] = f.geometry.coordinates
    const props = f.properties as PointProps & ClusterProps & { cluster?: boolean; cluster_id?: number; point_count?: number }
    if (props.cluster) {
      const id = props.cluster_id ?? 0
      const parkIds = index.getLeaves(id, 4).map((l) => (l.properties as PointProps).parkId)
      out.push({ kind: 'cluster', id, count: props.point_count ?? 0, status: props.status, lng, lat, parkIds })
    } else {
      const park = byId.get(props.parkId)
      if (park) out.push({ kind: 'park', park, lng, lat })
    }
  }
  return out
}

export function expansionZoom(index: ParkIndex, clusterId: number): number {
  return index.getClusterExpansionZoom(clusterId)
}

export type Side = 'right' | 'left' | 'top' | 'bottom'
export interface LabelBox {
  key: string
  x: number
  y: number
  w: number
  h: number
}

const SIDES: Side[] = ['right', 'left', 'top', 'bottom']
const GAP = 8
const LABEL_H = 20

/** Estimated label width in px for a text at 12 px. */
export function labelWidth(text: string): number {
  return Math.ceil(text.length * 6.6 + 14)
}

function rectFor(side: Side, x: number, y: number, w: number, h: number) {
  if (side === 'right') return { x: x + GAP, y: y - h / 2, w, h }
  if (side === 'left') return { x: x - GAP - w, y: y - h / 2, w, h }
  if (side === 'top') return { x: x - w / 2, y: y - GAP - h, w, h }
  return { x: x - w / 2, y: y + GAP, w, h }
}

function overlap(a: { x: number; y: number; w: number; h: number }, b: { x: number; y: number; w: number; h: number }): number {
  const ox = Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x)
  const oy = Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y)
  return ox > 0 && oy > 0 ? ox * oy : 0
}

/**
 * Picks a side of its dot for every label so labels do not collide: the first free side wins
 * (right, left, top, bottom); if none is free, the side with the least overlap. Nothing is ever hidden.
 * `points` are screen positions in px; `heights` default to a single line.
 */
export function placeLabels(points: { key: string; x: number; y: number; w: number; h?: number }[]): Map<string, Side> {
  const placed: { x: number; y: number; w: number; h: number }[] = []
  const dots = points.map((p) => ({ x: p.x - 7, y: p.y - 7, w: 14, h: 14 }))
  const out = new Map<string, Side>()
  for (const p of points) {
    const h = p.h ?? LABEL_H
    let best: Side = 'right'
    let bestCost = Infinity
    for (const side of SIDES) {
      const r = rectFor(side, p.x, p.y, p.w, h)
      let cost = 0
      for (const o of placed) cost += overlap(r, o)
      for (const d of dots) cost += overlap(r, d) * 2
      if (cost < bestCost) {
        bestCost = cost
        best = side
      }
      if (cost === 0) break
    }
    placed.push(rectFor(best, p.x, p.y, p.w, h))
    out.set(p.key, best)
  }
  return out
}
