import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useRef, useState } from 'react'
import { api, ApiError, isAbortError } from '@/lib/api'
import { onLive } from '@/lib/echo'
import type { CameraMode, GatewayLive, Readiness, StandLive, TestFrame } from '@/types'

const KEY = ['admin', 'stand', 'live']

/** The Bench A payload of GET /admin/stand/live (under `bench` since Sprint 6.3; the old flat shape is still accepted). */
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
    queryFn: ({ signal }) => api<{ data: GatewayLive }>('/admin/stand/live', { signal }).then((r) => r.data),
    select: (d) => (d.bench ?? (d as unknown as StandLive)) as StandLive,
    refetchInterval: 2_000,
    retry: false,
  })
}

/** The whole live payload (zones, gateway, timeline), same cache as useStandLive. Refreshed by Reverb events, 2 s poll as the fallback. */
export function useGatewayLive() {
  const queryClient = useQueryClient()
  useEffect(
    () =>
      onLive((event) => {
        if (event === 'reading.received' || event === 'pump.changed' || event === 'node.updated' || event === 'alert.raised' || event === 'alert.resolved') void queryClient.invalidateQueries({ queryKey: KEY })
      }),
    [queryClient],
  )
  return useQuery({
    queryKey: KEY,
    queryFn: ({ signal }) => api<{ data: GatewayLive }>('/admin/stand/live', { signal }).then((r) => r.data),
    refetchInterval: 2_000,
    retry: false,
  })
}

/** POST /admin/stand/pump { zone, on, seconds? } (Gateway zones). 502 gateway_unreachable when the controller does not answer. */
export function useZonePump() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (v: { zone: 'A' | 'B'; on: boolean; seconds?: number }) => api<{ data: GatewayLive }>('/admin/stand/pump', { method: 'POST', body: v }).then((r) => r.data),
    onSuccess: (data) => queryClient.setQueryData(KEY, data),
  })
}

/** POST /admin/stand/auto { on }: the Gateway's automatic irrigation. */
export function useGatewayAuto() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (on: boolean) => api<{ data: GatewayLive }>('/admin/stand/auto', { method: 'POST', body: { on } }).then((r) => r.data),
    onSuccess: (data) => queryClient.setQueryData(KEY, data),
  })
}

/** POST /admin/stand/test { key }: one part of the pre-demo check (pump_a, pump_b, camera_a, camera_b, litter_a, litter_b). */
export function useStandTest() {
  return useMutation({
    mutationFn: (key: string) => api<{ data: { ok: boolean; detail: string } }>('/admin/stand/test', { method: 'POST', body: { key } }).then((r) => r.data),
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

export interface TraySnapshot {
  image: string
  image_w: number
  image_h: number
}

/** GET /admin/nodes/{id}/snapshot: one blurred frame to draw the tray area on. 503 = camera (bridge) or vision down. */
export function useTraySnapshot(nodeId: number | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ['admin', 'stand', 'snapshot', nodeId],
    queryFn: ({ signal }) => api<{ data: TraySnapshot }>(`/admin/nodes/${nodeId}/snapshot`, { signal }).then((r) => r.data),
    enabled: enabled && nodeId !== undefined,
    retry: false,
    staleTime: 0,
    gcTime: 0,
  })
}

/** PATCH /admin/nodes/{id}/camera { camera_roi }: null = the whole frame. */
export function useSaveTray(nodeId: number | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (roi: [number, number][] | null) => api(`/admin/nodes/${nodeId}/camera`, { method: 'PATCH', body: { camera_roi: roi } }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  })
}

export const useCameraMode = () => useStandAction<CameraMode>('/admin/stand/camera-mode', (mode) => ({ mode }))

/** GET /admin/stand/readiness: the pre-demo check, refreshed every 10 s. */
export function useReadiness() {
  return useQuery({
    queryKey: ['admin', 'stand', 'readiness'],
    queryFn: ({ signal }) => api<{ data: Readiness }>('/admin/stand/readiness', { signal }).then((r) => r.data),
    refetchInterval: 10_000,
    retry: false,
  })
}

/** PATCH /admin/nodes/{id}/camera { litter_threshold }: the per-camera override (null = the default). */
export function useSaveThreshold(nodeId: number | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (value: number | null) => api(`/admin/nodes/${nodeId}/camera`, { method: 'PATCH', body: { litter_threshold: value } }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  })
}

/** POST /admin/stand/test-snapshot: saves the newest blurred test frame with its boxes on the server (git-ignored storage). */
export function useTestSnapshot() {
  return useMutation({
    mutationFn: () => api<{ data: { path: string } }>('/admin/stand/test-snapshot', { method: 'POST' }).then((r) => r.data),
  })
}

export interface TestStats {
  frames: number
  litterFrames: number
  /** average inference time in ms (null before the first frame) */
  avgMs: number | null
  /** real frames per second over the last frames (null before the second frame) */
  fps: number | null
}

const EMPTY_STATS: TestStats = { frames: 0, litterFrames: 0, avgMs: null, fps: null }
const TARGET_MS = 1000

/**
 * The Test mode loop: one frame at a time (the next request starts when the last one is done, but not sooner than
 * 1 s after it started), at the threshold in `thresholdRef` (read before every request, so the slider applies live).
 * Turning it off, or switching to Demo, clears the frame and the counters: nothing of a test run is kept.
 */
export function useTestLoop(enabled: boolean, thresholdRef: React.RefObject<number>) {
  const [frame, setFrame] = useState<TestFrame | null>(null)
  const [error, setError] = useState<'camera' | 'vision' | 'other' | null>(null)
  const [stats, setStats] = useState<TestStats>(EMPTY_STATS)
  const sums = useRef({ ms: 0, msFrames: 0, litter: 0, frames: 0, times: [] as number[] })

  const clear = useCallback(() => {
    sums.current = { ms: 0, msFrames: 0, litter: 0, frames: 0, times: [] }
    setFrame(null)
    setError(null)
    setStats(EMPTY_STATS)
  }, [])

  useEffect(() => {
    if (!enabled) return
    clear()
    const controller = new AbortController()
    const wait = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms))
    void (async () => {
      while (!controller.signal.aborted) {
        const started = performance.now()
        try {
          const r = await api<{ data: TestFrame }>(`/admin/stand/test-frame?threshold=${thresholdRef.current}`, { signal: controller.signal })
          if (controller.signal.aborted) return
          const s = sums.current
          s.frames += 1
          if (r.data.count > 0) s.litter += 1
          if (r.data.inference_ms != null) {
            s.ms += r.data.inference_ms
            s.msFrames += 1
          }
          s.times = [...s.times, performance.now()].slice(-10)
          const span = s.times.length > 1 ? (s.times[s.times.length - 1] - s.times[0]) / 1000 : 0
          setFrame(r.data)
          setError(null)
          setStats({ frames: s.frames, litterFrames: s.litter, avgMs: s.msFrames ? s.ms / s.msFrames : null, fps: span > 0 ? (s.times.length - 1) / span : null })
        } catch (e) {
          if (isAbortError(e) || controller.signal.aborted) return
          const body = e instanceof ApiError ? (e.body as { camera_offline?: boolean; vision_offline?: boolean } | null) : null
          setError(body?.camera_offline ? 'camera' : body?.vision_offline ? 'vision' : 'other')
          await wait(1500)
        }
        await wait(Math.max(0, TARGET_MS - (performance.now() - started)))
      }
    })()
    return () => {
      controller.abort()
      clear()
    }
  }, [enabled, thresholdRef, clear])

  return { frame, error, stats, reset: clear }
}
