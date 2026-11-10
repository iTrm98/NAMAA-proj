import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { type ApiScope, useScope } from '@/lib/scope'
import type { Detection } from '@/types'

export type { ApiScope } from '@/lib/scope'

/** The newest camera frames of a park (live: detection.created invalidates this). */
export function useParkFrames(parkId: number, scopeOverride?: ApiScope, limit = 6) {
  const contextScope = useScope()
  const scope = scopeOverride ?? contextScope
  return useQuery({
    queryKey: [scope, 'parks', parkId, 'detections'],
    queryFn: ({ signal }) => api<{ data: Detection[] }>(`/${scope}/parks/${parkId}/detections?limit=${limit}`, { signal }).then((r) => r.data),
    enabled: Number.isFinite(parkId),
    staleTime: 5_000,
  })
}

/** Demo page: one photo (file or webcam frame) for a camera node, through the normal camera pipeline. */
export function useUploadFrame() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ nodeId, image, via }: { nodeId: number; image: Blob; via: 'file' | 'webcam' }) => {
      const form = new FormData()
      form.append('node_id', String(nodeId))
      form.append('via', via)
      form.append('image', image, via === 'webcam' ? 'webcam.jpg' : image instanceof File ? image.name : 'photo.jpg')
      return api<{ data: Detection }>('/admin/demo/frame', { method: 'POST', body: form }).then((r) => r.data)
    },
    onSuccess: (detection) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'parks', detection.park_id, 'detections'] })
    },
  })
}
