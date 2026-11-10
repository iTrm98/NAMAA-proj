import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useScope } from '@/lib/scope'
import type { Park, ParkDetail, ReadingRange, ReadingSeries } from '@/types'

/** Live updates come over Reverb; polling every 60 s is only the fallback. */
export const POLL_MS = 60_000

export function useParks() {
  const scope = useScope()
  return useQuery({
    queryKey: [scope, 'parks'],
    queryFn: ({ signal }) => api<{ data: Park[] }>(`/${scope}/parks`, { signal }).then((r) => r.data),
    refetchInterval: POLL_MS,
  })
}

export function usePark(id: number) {
  const scope = useScope()
  return useQuery({
    queryKey: [scope, 'parks', id],
    queryFn: ({ signal }) => api<{ data: ParkDetail }>(`/${scope}/parks/${id}`, { signal }).then((r) => r.data),
    // Every 5 s while satellite data is being recomputed after an outline change.
    refetchInterval: (query) => (query.state.data?.ndvi_recomputing ? 5_000 : POLL_MS),
    enabled: Number.isFinite(id),
  })
}

export function useParkReadings(id: number, range: ReadingRange) {
  const scope = useScope()
  return useQuery({
    queryKey: [scope, 'parks', id, 'readings', range],
    queryFn: ({ signal }) => api<ReadingSeries>(`/${scope}/parks/${id}/readings?range=${range}`, { signal }),
    refetchInterval: POLL_MS,
    placeholderData: keepPreviousData,
    enabled: Number.isFinite(id),
  })
}

export function parkName(park: Pick<Park, 'name_ar' | 'name_en'>, language: string) {
  return language === 'ar' ? park.name_ar : park.name_en
}
