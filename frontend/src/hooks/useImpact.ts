import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useScope } from '@/lib/scope'
import type { Impact } from '@/types'

/** Impact figures of the last N days (admin: the company; municipality: every company). Live via alert/task events. */
export function useImpact(days: number) {
  const scope = useScope()
  return useQuery({
    queryKey: [scope, 'impact', days],
    queryFn: ({ signal }) => api<{ data: Impact }>(`/${scope}/impact?days=${days}`, { signal }).then((r) => r.data),
    placeholderData: (previous) => previous,
    refetchInterval: 60_000,
  })
}
