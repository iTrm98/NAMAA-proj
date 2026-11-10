import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { CameraTestResult, Detection, DeviceRejections, HardwareNode, NodeToken } from '@/types'

const INCLUDE_TEST_KEY = 'namaa.hardware.includeTest'

/** The "Show test bench" choice, remembered per browser (storage may be blocked). */
export function loadIncludeTest(): boolean {
  try {
    return window.localStorage.getItem(INCLUDE_TEST_KEY) === '1'
  } catch {
    return false
  }
}

export function saveIncludeTest(on: boolean) {
  try {
    window.localStorage.setItem(INCLUDE_TEST_KEY, on ? '1' : '0')
  } catch {
    // not remembered: fine
  }
}

/** GET /admin/nodes. Live: reading.received patches the cache, node.updated invalidates it. */
export function useHardwareNodes(includeTest: boolean) {
  return useQuery({
    queryKey: ['admin', 'nodes', includeTest],
    queryFn: ({ signal }) => api<{ data: HardwareNode[] }>(`/admin/nodes?include_test=${includeTest ? 1 : 0}`, { signal }).then((r) => r.data),
    refetchInterval: 60_000,
  })
}

export function useDeviceRejections() {
  return useQuery({
    queryKey: ['admin', 'device-rejections'],
    queryFn: ({ signal }) => api<{ data: DeviceRejections[] }>('/admin/device-rejections', { signal }).then((r) => r.data),
    refetchInterval: 15_000,
  })
}

/** Put the node the server returned into every cached node list. */
function usePatchNode() {
  const queryClient = useQueryClient()
  return (node: HardwareNode) =>
    queryClient.setQueriesData<HardwareNode[]>({ queryKey: ['admin', 'nodes'] }, (nodes) => nodes?.map((n) => (n.id === node.id ? node : n)))
}

export function useCalibrate(nodeId: number) {
  const patch = usePatchNode()
  return useMutation({
    mutationFn: (point: 'dry' | 'wet') => api<{ data: HardwareNode }>(`/admin/nodes/${nodeId}/calibration`, { method: 'POST', body: { point } }).then((r) => r.data),
    onSuccess: patch,
  })
}

export function useRelayTest(nodeId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (confirm: boolean) =>
      api<{ data: { node_id: number; seconds: number; until: string } }>(`/admin/nodes/${nodeId}/relay-test`, { method: 'POST', body: confirm ? { confirm: true } : {} }).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'nodes'] }),
  })
}

/** The token is returned once: the caller keeps it only while the dialog is open (mutation.reset() on close). */
export function useRegenerateToken(nodeId: number) {
  return useMutation({
    mutationFn: () => api<{ data: NodeToken }>(`/admin/nodes/${nodeId}/token`, { method: 'POST' }).then((r) => r.data),
    gcTime: 0,
  })
}

export function useUpdateCamera(nodeId: number) {
  const patch = usePatchNode()
  return useMutation({
    mutationFn: (body: { capture_mode?: 'url' | 'browser'; camera_url?: string | null }) =>
      api<{ data: HardwareNode }>(`/admin/nodes/${nodeId}/camera`, { method: 'PATCH', body }).then((r) => r.data),
    onSuccess: patch,
  })
}

export function useCameraTest(nodeId: number) {
  return useMutation({
    mutationFn: () => api<{ data: CameraTestResult }>(`/admin/nodes/${nodeId}/camera-test`, { method: 'POST' }).then((r) => r.data),
  })
}

/** One browser-camera frame (multipart `image`) through the normal pipeline. */
export function useSendBrowserFrame(nodeId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (image: Blob) => {
      const form = new FormData()
      form.append('image', image, 'frame.jpg')
      return api<{ data: Detection }>(`/admin/nodes/${nodeId}/frame`, { method: 'POST', body: form }).then((r) => r.data)
    },
    onSuccess: (detection) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'parks', detection.park_id, 'detections'] })
      void queryClient.invalidateQueries({ queryKey: ['admin', 'nodes'] })
    },
  })
}
