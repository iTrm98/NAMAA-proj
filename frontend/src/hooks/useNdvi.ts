import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { type ApiScope, useScope } from '@/lib/scope'
import type { NdviPoint, NdviRun } from '@/types'

/** NDVI history of a park, oldest first (live: ndvi.updated invalidates ['admin','parks']). */
export function useParkNdvi(parkId: number, scopeOverride?: ApiScope, days = 90) {
  const contextScope = useScope()
  const scope = scopeOverride ?? contextScope
  return useQuery({
    queryKey: [scope, 'parks', parkId, 'ndvi', days],
    queryFn: ({ signal }) => api<{ data: NdviPoint[] }>(`/${scope}/parks/${parkId}/ndvi?days=${days}`, { signal }).then((r) => r.data),
    enabled: Number.isFinite(parkId),
    staleTime: 60_000,
  })
}

/** Demo page: the last "Fetch satellite data now" run, polled every 2 s while it runs. */
export function useNdviRun() {
  return useQuery({
    queryKey: ['admin', 'demo', 'ndvi'],
    queryFn: ({ signal }) => api<{ data: NdviRun | null }>('/admin/demo/ndvi', { signal }).then((r) => r.data),
    refetchInterval: (query) => (query.state.data && !query.state.data.finished_at ? 2_000 : false),
  })
}

export function useStartNdvi() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => api<{ data: NdviRun }>('/admin/demo/ndvi', { method: 'POST' }).then((r) => r.data),
    onSuccess: (run) => queryClient.setQueryData(['admin', 'demo', 'ndvi'], run),
  })
}
