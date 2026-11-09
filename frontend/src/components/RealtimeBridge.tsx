import type { TFunction } from 'i18next'
import { type QueryClient, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { useAuth } from '@/lib/auth'
import { connectEcho, disconnectEcho, type LiveEvent, onLive } from '@/lib/echo'
import { appendToCachedSeries, type LiveReading } from '@/lib/liveSeries'
import { beep, vibrate } from '@/lib/notify'
import type { Park, User } from '@/types'

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

  useEffect(() => () => disconnectEcho(), [])

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

  switch (event) {
    case 'reading.received': {
      const reading = payload.reading as Park['latest'] & { pump_target: boolean }
      // Patch the park list in place (map + sidebar) so 5 s readings do not refetch it.
      queryClient.setQueryData<Park[]>(['admin', 'parks'], (parks) =>
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
      void queryClient.invalidateQueries({ queryKey: ['admin', 'parks', parkId], exact: true })
      if (reading) appendToCachedSeries(queryClient, parkId, reading as LiveReading)
      break
    }
    case 'park.status_changed':
    case 'pump.changed':
      invalidate(queryClient, ['admin', 'parks'])
      break
    case 'alert.raised':
    case 'alert.resolved':
    case 'alert.updated':
      invalidate(queryClient, ['admin', 'alerts'], ['admin', 'parks'])
      break
    case 'task.created':
    case 'task.updated':
    case 'task.escalated':
      invalidate(queryClient, ['admin', 'tasks'], ['admin', 'alerts'], ['admin', 'workers'], ['admin', 'parks', parkId])
      break
  }
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
