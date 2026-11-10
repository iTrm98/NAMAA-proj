import type { TFunction } from 'i18next'
import { type QueryClient, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { useAuth } from '@/lib/auth'
import { connectEcho, disconnectEcho, type LiveEvent, onLive, releaseEcho } from '@/lib/echo'
import { appendToCachedSeries, type LiveReading } from '@/lib/liveSeries'
import { beep, vibrate } from '@/lib/notify'
import type { HardwareNode, Park, User } from '@/types'

/**
 * Keeps one Reverb connection for the signed-in user and turns live events
 * into cache updates: small changes are patched in place, the rest invalidates
 * the matching queries (TanStack Query refetches only what is on screen).
 */
export function RealtimeBridge() {
  const { token, user } = useAuth()
  const queryClient = useQueryClient()
  const { t } = useTranslation()
  const navigate = useNavigate()

  useEffect(() => {
    if (!token || !user) {
      disconnectEcho()
      return
    }
    connectEcho(user, token)
    return onLive((event, payload) => {
      handle(queryClient, user, event, payload)
      if (event === 'task.created' && user.role === 'worker') notifyNewTask(t, payload, (id) => navigate(`/worker/tasks/${id}`))
      if (event === 'task.escalated' && user.role === 'admin') notifyEscalated(t, payload, () => navigate('/admin/tasks?status=overdue'))
    })
  }, [token, user, queryClient, t, navigate])

  // Deferred: StrictMode's second mount reuses the same connection (see releaseEcho).
  useEffect(() => () => releaseEcho(), [])

  return null
}

function invalidate(queryClient: QueryClient, ...keys: unknown[][]) {
  for (const queryKey of keys) void queryClient.invalidateQueries({ queryKey })
}

function handle(queryClient: QueryClient, user: User, event: LiveEvent, payload: Record<string, unknown>) {
  const parkId = Number(payload.park_id ?? (payload.task as { park_id?: number } | undefined)?.park_id)

  if (user.role === 'worker') {
    // The worker's list and the open task detail (moisture, pump, verification).
    invalidate(queryClient, ['worker', 'tasks'])
    return
  }

  // Admin and municipality pages share the components; their caches differ only in the first key.
  const scope = user.role === 'municipality' ? 'municipality' : 'admin'

  switch (event) {
    case 'reading.received': {
      const reading = payload.reading as Park['latest'] & { pump_target: boolean }
      // Patch the park list in place (map + sidebar) so 5 s readings do not refetch it.
      queryClient.setQueryData<Park[]>([scope, 'parks'], (parks) =>
        parks?.map((park) =>
          park.id === parkId && reading
            ? {
                ...park,
                status: payload.status as Park['status'],
                last_reading_at: reading.at,
                pump_target: reading.pump_target,
                latest: { ...reading, node_id: Number(payload.node_id) },
              }
            : park,
        ),
      )
      // The park detail only (exact): the chart series get the point appended, not refetched.
      void queryClient.invalidateQueries({ queryKey: [scope, 'parks', parkId], exact: true })
      if (reading) appendToCachedSeries(queryClient, parkId, reading as LiveReading, scope)
      if (scope === 'admin' && reading) patchHardwareNode(queryClient, Number(payload.node_id), reading as unknown as Record<string, unknown>)
      break
    }
    case 'node.updated':
      // Calibration, camera mode or relay test changed (hardware page).
      invalidate(queryClient, [scope, 'nodes'])
      break
    case 'park.status_changed':
    case 'pump.changed':
      invalidate(queryClient, [scope, 'parks'])
      break
    case 'alert.raised':
    case 'alert.resolved':
    case 'alert.updated':
      invalidate(queryClient, [scope, 'alerts'], [scope, 'parks'], [scope, 'impact'])
      break
    case 'task.created':
    case 'task.updated':
    case 'task.escalated':
      invalidate(queryClient, [scope, 'tasks'], [scope, 'alerts'], [scope, 'workers'], [scope, 'parks', parkId], [scope, 'impact'])
      break
    case 'ndvi.updated':
      invalidate(queryClient, [scope, 'parks'])
      break
    case 'detection.created': {
      // New camera frame: patch the map's item count, refetch the park's frame strip.
      const count = Number(payload.count ?? 0)
      const at = (payload.detection as { captured_at?: string } | undefined)?.captured_at ?? null
      queryClient.setQueryData<Park[]>([scope, 'parks'], (parks) =>
        parks?.map((park) => (park.id === parkId && park.camera ? { ...park, camera: { ...park.camera, last_count: count, last_frame_at: at } } : park)),
      )
      invalidate(queryClient, [scope, 'parks', parkId, 'detections'])
      break
    }
  }
}

/** Hardware page: the node's last payload and liveness, patched in place (a refetch every 5 s would be wasteful). */
function patchHardwareNode(queryClient: QueryClient, nodeId: number, reading: Record<string, unknown>) {
  const num = (key: string) => (typeof reading[key] === 'number' ? (reading[key] as number) : null)
  queryClient.setQueriesData<HardwareNode[]>({ queryKey: ['admin', 'nodes'] }, (nodes) =>
    nodes?.map((node) => {
      if (node.id !== nodeId) return node
      const last = {
        at: String(reading.at ?? new Date().toISOString()),
        moisture_raw: num('moisture_raw'),
        moisture_pct: num('moisture_pct'),
        temperature: num('temperature'),
        humidity: num('humidity'),
        flow: num('flow'),
        water_level: num('water_level'),
        battery_pct: num('battery_pct'),
        pump_on: typeof reading.pump_on === 'boolean' ? reading.pump_on : null,
      }
      return {
        ...node,
        online: true,
        last_seen_at: new Date().toISOString(),
        seconds_since_seen: 0,
        rssi: num('rssi') ?? node.rssi,
        uptime_s: num('uptime_s') ?? node.uptime_s,
        fw_version: typeof reading.fw_version === 'string' ? reading.fw_version : node.fw_version,
        via: reading.via === 'wifi' || reading.via === 'usb' ? reading.via : node.via,
        last_reading: last,
        calibration: node.calibration ? { ...node.calibration, latest_raw: last.moisture_raw, latest_pct: last.moisture_pct } : null,
      }
    }),
  )
}

/** Admin notice: a task passed its due time (raised one level, or overdue when already urgent). */
function notifyEscalated(t: TFunction, payload: Record<string, unknown>, open: () => void) {
  const task = payload.task as { title: string; priority: string; overdue: boolean } | undefined
  if (!task) return
  const message = task.overdue && task.priority === 'urgent'
    ? t('tasks.overdueToast', { title: task.title })
    : t('tasks.escalatedToast', { title: task.title, priority: t(`priority.${task.priority}`) })
  toast.warning(message, { duration: 10_000, action: { label: t('worker.open'), onClick: open } })
}

function notifyNewTask(
  t: TFunction,
  payload: Record<string, unknown>,
  open: (id: number) => void,
) {
  const task = payload.task as { id: number; title: string; priority: string } | undefined
  beep()
  vibrate()
  toast(t('worker.newTask'), {
    description: task?.title,
    duration: 8000,
    action: task ? { label: t('worker.open'), onClick: () => open(task.id) } : undefined,
  })
}
