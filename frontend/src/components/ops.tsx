import type { TFunction } from 'i18next'
import { AlarmClock, CheckCircle2, Clock, Cpu, Droplets, Sprout, Trash2, TrendingUp, XCircle } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { apiBlob, isAbortError } from '@/lib/api'
import { formatAgo, formatDateTime, formatDuration, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { AlertCategory, AlertType, Task, TaskPriority, TaskStatus } from '@/types'

/** Small shared pieces for alerts and tasks (admin and worker). */


/**
 * Priority colours: one fixed colour per priority, identical in every view
 * (urgent red, high orange, normal blue, low grey). Users see the priority
 * only; the server keeps the severity to itself.
 */
const PRIORITY_STYLE: Record<TaskPriority, string> = {
  urgent: 'bg-red-600 text-white ring-red-700/40',
  high: 'bg-orange-500 text-white ring-orange-600/40',
  normal: 'bg-blue-600 text-white ring-blue-700/40',
  low: 'bg-zinc-500 text-white ring-zinc-600/40',
}

/** Category: what the problem is and who handles it. Icon + frame colour, used on alert cards, task cards and the map popup. */
export const CATEGORY_META: Record<AlertCategory, { icon: ReactNode; text: string; frame: string; row: string; bar: string }> = {
  water: { icon: <Droplets />, text: 'text-cyan-600 dark:text-cyan-400', frame: 'border-s-cyan-500', row: '[&>td:first-child]:border-s-cyan-500', bar: 'bg-cyan-500' },
  plant: { icon: <Sprout />, text: 'text-emerald-600 dark:text-emerald-400', frame: 'border-s-emerald-500', row: '[&>td:first-child]:border-s-emerald-500', bar: 'bg-emerald-500' },
  cleanliness: { icon: <Trash2 />, text: 'text-amber-600 dark:text-amber-400', frame: 'border-s-amber-500', row: '[&>td:first-child]:border-s-amber-500', bar: 'bg-amber-500' },
  devices: { icon: <Cpu />, text: 'text-violet-600 dark:text-violet-400', frame: 'border-s-violet-500', row: '[&>td:first-child]:border-s-violet-500', bar: 'bg-violet-500' },
}

/** The same frame on a table row (on its first cell). */
export function categoryRowFrame(category: AlertCategory | null | undefined): string {
  return category ? cn('[&>td:first-child]:border-s-4', CATEGORY_META[category].row) : ''
}

/** The frame classes of a card for a category (a coloured start edge). */
export function categoryFrame(category: AlertCategory | null | undefined): string {
  return category ? cn('border-s-4', CATEGORY_META[category].frame) : ''
}

export function CategoryTag({ category, className }: { category: AlertCategory; className?: string }) {
  const { t } = useTranslation()
  const meta = CATEGORY_META[category]
  return (
    <span className={cn('inline-flex items-center gap-1 text-xs font-medium [&_svg]:size-3.5', meta.text, className)}>
      {meta.icon}
      {t(`category.${category}`)}
    </span>
  )
}

export function PriorityBadge({ priority, className }: { priority: TaskPriority; className?: string }) {
  const { t } = useTranslation()
  return (
    <span className={cn('inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-semibold ring-1 ring-inset', PRIORITY_STYLE[priority], className)}>
      {t(`priority.${priority}`)}
    </span>
  )
}

const STATUS_STYLE: Record<TaskStatus, string> = {
  open: 'bg-amber-500/15 text-amber-700 ring-amber-500/30 dark:text-amber-300',
  in_progress: 'bg-sky-500/15 text-sky-700 ring-sky-500/30 dark:text-sky-300',
  done: 'bg-status-ok/15 text-status-ok ring-status-ok/30',
  cancelled: 'bg-muted text-muted-foreground ring-border',
}

export function TaskStatusBadge({ status }: { status: TaskStatus }) {
  const { t } = useTranslation()
  return (
    <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', STATUS_STYLE[status])}>
      {t(`taskStatus.${status}`)}
    </span>
  )
}

/** Verified ✓ / Not verified ✗ / Waiting (only for done tasks). */
export function VerifyBadge({ task }: { task: Pick<Task, 'status' | 'verified' | 'verify_reason'> }) {
  const { t } = useTranslation()
  if (task.status !== 'done') return <span className="text-xs text-muted-foreground">—</span>
  if (task.verified === true)
    return (
      <span className="inline-flex items-center gap-1 text-xs font-medium text-status-ok">
        <CheckCircle2 className="size-4" />
        {t('verify.verified')}
      </span>
    )
  if (task.verified === false)
    return (
      <span className="inline-flex items-center gap-1 text-xs font-medium text-destructive" title={task.verify_reason ? t(`verify.${task.verify_reason}`) : undefined}>
        <XCircle className="size-4" />
        {t('verify.notVerified')}
      </span>
    )
  return (
    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
      <Clock className="size-4 animate-pulse" />
      {t('verify.waiting')}
    </span>
  )
}

/** One line with the numbers behind an alert, in plain words. */
export function alertDetail(t: TFunction, type: AlertType, data: Record<string, unknown> | null): string | null {
  const d = data ?? {}
  const n = (key: string, digits = 1) => formatNumber(typeof d[key] === 'number' ? (d[key] as number) : null, digits)
  switch (type) {
    case 'dry':
      return d.moisture_pct == null ? null : t('alertDetail.dry', { value: n('moisture_pct'), min: n('moisture_min', 0) })
    case 'heat_stress':
      return d.moisture_pct == null
        ? null
        : t('alertDetail.heat_stress', { value: n('moisture_pct'), min: n('moisture_min', 0), temp: n(typeof d.heat_temperature === 'number' ? 'heat_temperature' : 'temperature') })
    case 'blockage':
      return t('alertDetail.blockage', { flow: n('flow', 2), expected: n('expected_flow', 1) })
    case 'low_battery':
      return d.battery_pct == null ? null : t('alertDetail.low_battery', { value: n('battery_pct', 0) })
    case 'over':
      return d.moisture_pct == null ? null : t('alertDetail.over', { value: n('moisture_pct'), max: n('moisture_max', 0) })
    case 'no_flow':
      return t('alertDetail.no_flow', { flow: n('flow', 2) })
    case 'leak':
      return t('alertDetail.leak', { flow: n('flow', 2) })
    case 'offline':
      return typeof d.last_seen_at === 'string' ? t('alertDetail.offline', { since: formatAgo(d.last_seen_at) }) : t('alertDetail.offlineNever')
    case 'sensor_fault':
      if (d.moisture_raw == null) return null
      return t(d.reason === 'stuck' ? 'alertDetail.sensor_fault_stuck' : 'alertDetail.sensor_fault', { raw: n('moisture_raw', 0) })
    case 'ndvi_drop':
      return typeof d.ndvi !== 'number' ? null : t('alertDetail.ndvi_drop', { value: n('ndvi', 2), drop: n('drop_pct', 0), baseline: n('baseline', 2) })
    case 'litter':
      return t('alertDetail.litter', { count: Number(d.count ?? 0), labels: Array.isArray(d.labels) ? (d.labels as string[]).join(', ') : '' })
    case 'litter_repeated': {
      const reason = typeof d.repeated_reason === 'string' ? d.repeated_reason : 'many_items'
      return t(`alertDetail.litter_repeated_${reason}`, { count: Number(d.count ?? 0) })
    }
    default:
      return null
  }
}

/** Task photos are protected: fetched with the Bearer token, shown from an object URL. */
export function TaskPhoto({ path, alt, className }: { path: string; alt: string; className?: string }) {
  const [url, setUrl] = useState<string | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    const controller = new AbortController()
    let objectUrl: string | null = null
    apiBlob(path, controller.signal)
      .then((blob) => {
        objectUrl = URL.createObjectURL(blob)
        setUrl(objectUrl)
      })
      .catch((error: unknown) => {
        // Unmounted or the frame changed: the abort is expected, not a failure.
        if (isAbortError(error) || controller.signal.aborted) return
        setFailed(true)
      })
    return () => {
      controller.abort()
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [path])

  if (failed) return <span className="text-xs text-muted-foreground">—</span>
  if (!url) return <span className={cn('inline-block animate-pulse rounded bg-muted', className)} />
  return (
    <a href={url} target="_blank" rel="noreferrer">
      <img src={url} alt={alt} className={cn('rounded object-cover', className)} />
    </a>
  )
}

/** The current time, refreshed every `ms` (for countdowns). */
export function useNow(ms = 1000): number {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const id = window.setInterval(() => setNow(Date.now()), ms)
    return () => window.clearInterval(id)
  }, [ms])
  return now
}

/**
 * Time left until the task's due time, or "Overdue by …" in red. Only for
 * active tasks. Re-renders every second when close, every 15 s otherwise.
 */
export function DueBadge({ task, className }: { task: Pick<Task, 'status' | 'due_at'>; className?: string }) {
  const { t } = useTranslation()
  const due = task.due_at ? new Date(task.due_at).getTime() : null
  const now = useNow(due !== null && Math.abs(due - Date.now()) < 3_600_000 ? 1000 : 15_000)
  if (due === null || (task.status !== 'open' && task.status !== 'in_progress')) return null
  const seconds = Math.round((due - now) / 1000)
  const overdue = seconds < 0
  return (
    <span
      className={cn('inline-flex items-center gap-1 text-xs tabular-nums [&_svg]:size-3.5', overdue ? 'font-semibold text-red-600 dark:text-red-400' : 'text-muted-foreground', className)}
      title={formatDateTime(task.due_at)}
    >
      <AlarmClock />
      {overdue ? t('due.overdueBy', { time: formatDuration(-seconds) }) : t('due.left', { time: formatDuration(seconds) })}
    </span>
  )
}

/** "Escalated" (×n) when the server raised the task for being late. */
export function EscalatedBadge({ task }: { task: Pick<Task, 'escalation_count' | 'escalated_at'> }) {
  const { t } = useTranslation()
  if (!task.escalation_count) return null
  return (
    <span
      className="inline-flex items-center gap-1 rounded-md bg-red-500/10 px-1.5 py-0.5 text-xs font-medium text-red-700 ring-1 ring-red-500/30 ring-inset dark:text-red-300 [&_svg]:size-3.5"
      title={formatDateTime(task.escalated_at)}
    >
      <TrendingUp />
      {task.escalation_count > 1 ? t('due.escalatedTimes', { n: task.escalation_count }) : t('due.escalated')}
    </span>
  )
}

/** "mm:ss" until an ISO time; re-renders every second. */
export function useCountdown(iso: string | null | undefined): string | null {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    if (!iso) return
    const id = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(id)
  }, [iso])
  if (!iso) return null
  const seconds = Math.max(0, Math.round((new Date(iso).getTime() - now) / 1000))
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

/** Text colour per moisture state (matches the map colours). */
export const STATUS_TEXT = { ok: 'text-status-ok', dry: 'text-status-dry', over: 'text-status-over' } as const
