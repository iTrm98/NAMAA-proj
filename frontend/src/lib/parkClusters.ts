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

export const CLUSTER_RADIUS = 48
export const CLUSTER_MAX_ZOOM = 12
/** From this zoom on, parks show their name; below it they are dots and clusters. */
export const LABEL_ZOOM = 13

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

export type ClusterItem =
  | { kind: 'park'; park: Park; lng: number; lat: number }
  | { kind: 'cluster'; id: number; count: number; status: ParkStatus; lng: number; lat: number }

/** Demo-stand and archived parks never appear on the maps. */
export function mapParks(parks: Park[]): Park[] {
  return parks.filter((p) => p.site_type !== 'demo_stand' && !p.archived_at)
}

export function rankOf(status: ParkStatus): number {
  return STATUS_RANK[status] ?? 0
}

export function buildIndex(parks: Park[]) {
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
export function clustersFor(index: Supercluster<PointProps, ClusterProps>, parks: Park[], bounds: Bounds, zoom: number): ClusterItem[] {
  const byId = new Map(parks.map((p) => [p.id, p]))
  const out: ClusterItem[] = []
  for (const f of index.getClusters(bounds, Math.floor(zoom))) {
    const [lng, lat] = f.geometry.coordinates
    const props = f.properties as PointProps & ClusterProps & { cluster?: boolean; cluster_id?: number; point_count?: number }
    if (props.cluster) {
      out.push({ kind: 'cluster', id: props.cluster_id ?? 0, count: props.point_count ?? 0, status: props.status, lng, lat })
    } else {
      const park = byId.get(props.parkId)
      if (park) out.push({ kind: 'park', park, lng, lat })
    }
  }
  return out
}

export function expansionZoom(index: Supercluster<PointProps, ClusterProps>, clusterId: number): number {
  return index.getClusterExpansionZoom(clusterId)
}
