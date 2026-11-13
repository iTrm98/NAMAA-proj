import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, api } from '@/lib/api'
import type { OsmExport, ParkClaim, ParkClaimInput, SelectionResponse, SetupGeoFeature } from '@/types'

/** GET /admin/park-setup/osm. A 404 (no export fetched yet) resolves to null so the page can show the hint. */
export function useOsmExport() {
  return useQuery({
    queryKey: ['admin', 'park-setup', 'osm'],
    queryFn: async ({ signal }): Promise<OsmExport | null> => {
      try {
        return await api<OsmExport>('/admin/park-setup/osm', { signal })
      } catch (e) {
        if (e instanceof ApiError && e.status === 404) return null
        throw e
      }
    },
    retry: false,
  })
}

/** GET /admin/park-setup/selection: the saved FeatureCollection plus the simulated count and the cap. */
export function useSelection() {
  return useQuery({
    queryKey: ['admin', 'park-setup', 'selection'],
    queryFn: async ({ signal }): Promise<SelectionResponse | null> => {
      try {
        return await api<SelectionResponse>('/admin/park-setup/selection', { signal })
      } catch (e) {
        if (e instanceof ApiError && e.status === 404) return null
        throw e
      }
    },
    retry: false,
  })
}

export function useSaveSelection() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (features: SetupGeoFeature[]) => api<SelectionResponse>('/admin/park-setup/selection', { method: 'PUT', body: { features } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'park-setup', 'selection'] }),
  })
}

export function useClaims(parkId: number | null) {
  return useQuery({
    queryKey: ['admin', 'park-setup', 'claims', parkId],
    queryFn: ({ signal }) => api<{ data: ParkClaim[] }>(`/admin/parks/${parkId}/claims`, { signal }).then((r) => r.data),
    enabled: parkId != null,
    retry: false,
  })
}

export function useAddClaim(parkId: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (body: ParkClaimInput) => api<{ data: ParkClaim }>(`/admin/parks/${parkId}/claims`, { method: 'POST', body }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'park-setup', 'claims', parkId] }),
  })
}

export function useDeleteClaim(parkId: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => api<unknown>(`/admin/claims/${id}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'park-setup', 'claims', parkId] }),
  })
}
