import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { api } from '@/lib/api'
import { onLive } from '@/lib/echo'
import type { StandLive } from '@/types'

const KEY = ['admin', 'stand', 'live']

/** GET /admin/stand/live: refreshed by Reverb events, with a 2 s poll as the fallback. */
export function useStandLive() {
  const queryClient = useQueryClient()
  useEffect(
    () =>
      onLive((event) => {
        if (event === 'reading.received' || event === 'pump.changed' || event === 'node.updated') void queryClient.invalidateQueries({ queryKey: KEY })
      }),
    [queryClient],
  )
  return useQuery({
    queryKey: KEY,
    queryFn: ({ signal }) => api<{ data: StandLive }>('/admin/stand/live', { signal }).then((r) => r.data),
    refetchInterval: 2_000,
    retry: false,
  })
}

function useStandAction<V>(path: string, body: (v: V) => unknown) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (v: V) => api<{ data: StandLive }>(path, { method: 'POST', body: body(v) }).then((r) => r.data),
    onSuccess: (data) => queryClient.setQueryData(KEY, data),
  })
}

export const useStandPump = () => useStandAction<boolean>('/admin/stand/pump', (on) => ({ on }))
export const useStandCalibrate = () => useStandAction<'dry' | 'wet'>('/admin/stand/calibrate', (point) => ({ point }))
