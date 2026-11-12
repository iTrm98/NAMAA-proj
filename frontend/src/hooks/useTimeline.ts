import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useScope } from '@/lib/scope'
import type { ParkTimeline } from '@/types'

/** The proof chain of a park's latest problem (null data: the park has no alerts). Live events invalidate it with the park. */
export function useParkTimeline(parkId: number, alertId?: number) {
  const scope = useScope()
  return useQuery({
    queryKey: [scope, 'parks', parkId, 'timeline', alertId ?? 'latest'],
    queryFn: ({ signal }) => api<{ data: ParkTimeline | null }>(`/${scope}/parks/${parkId}/timeline${alertId ? `?alert_id=${alertId}` : ''}`, { signal }).then((r) => r.data),
    enabled: Number.isFinite(parkId),
    refetchInterval: 30_000,
  })
}
