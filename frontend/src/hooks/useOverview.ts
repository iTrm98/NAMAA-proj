import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useScope } from '@/lib/scope'
import type { Overview } from '@/types'

/** The dashboard in one request. Live events invalidate it; the interval keeps watering progress moving. */
export function useOverview() {
  const scope = useScope()
  return useQuery({
    queryKey: [scope, 'overview'],
    queryFn: ({ signal }) => api<{ data: Overview }>(`/${scope}/overview`, { signal }).then((r) => r.data),
    refetchInterval: 20_000,
  })
}
