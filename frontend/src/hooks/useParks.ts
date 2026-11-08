import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Park, ParkDetail, ReadingRange, ReadingSeries } from '@/types'

/** Polling every 15 s until live push (Reverb) arrives in Sprint 2. */
export const POLL_MS = 15_000

export function useParks() {
  return useQuery({
    queryKey: ['admin', 'parks'],
    queryFn: ({ signal }) => api<{ data: Park[] }>('/admin/parks', { signal }).then((r) => r.data),
    refetchInterval: POLL_MS,
  })
}

export function usePark(id: number) {
  return useQuery({
    queryKey: ['admin', 'parks', id],
    queryFn: ({ signal }) => api<{ data: ParkDetail }>(`/admin/parks/${id}`, { signal }).then((r) => r.data),
    refetchInterval: POLL_MS,
    enabled: Number.isFinite(id),
  })
}

export function useParkReadings(id: number, range: ReadingRange) {
  return useQuery({
    queryKey: ['admin', 'parks', id, 'readings', range],
    queryFn: ({ signal }) => api<ReadingSeries>(`/admin/parks/${id}/readings?range=${range}`, { signal }),
    refetchInterval: POLL_MS,
    placeholderData: keepPreviousData,
    enabled: Number.isFinite(id),
  })
}

export function parkName(park: Pick<Park, 'name_ar' | 'name_en'>, language: string) {
  return language === 'ar' ? park.name_ar : park.name_en
}
