import { Ban } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { CategoryTag, categoryRowFrame, DueBadge, EscalatedBadge, PriorityBadge, TaskPhoto, TaskStatusBadge, VerifyBadge } from '@/components/ops'
import { SourceBadge } from '@/components/StatusPill'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { isActive, useAdminTasks, useUpdateTask, useWorkers } from '@/hooks/useOps'
import { parkName, useParks } from '@/hooks/useParks'
import { formatAgo, formatDateTime } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { Task, TaskStatus } from '@/types'

const STATUSES: TaskStatus[] = ['open', 'in_progress', 'done', 'cancelled']
const NONE = 'none'

/** /admin/tasks: every task of the company. Filters live in the URL (?status=…&park=…). */
export default function TasksPage() {
  const { t, i18n } = useTranslation()
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? 'all'
  const parkId = params.get('park') ?? 'all'
  const tasks = useAdminTasks({ status, parkId })
  const parks = useParks()

  function setFilter(key: 'status' | 'park', value: string) {
    const next = new URLSearchParams(params)
    if (value === 'all') next.delete(key)
    else next.set(key, value)
    setParams(next, { replace: true })
  }

  return (
    <div className="flex flex-col gap-4 p-4 lg:p-6">
      <div className="flex flex-wrap items-end gap-3">
        <div className="me-auto">
          <h1 className="text-xl font-semibold">{t('tasks.title')}</h1>
          <p className="text-sm text-muted-foreground">{t('tasks.subtitle')}</p>
        </div>
        <Select value={status} onValueChange={(v) => setFilter('status', v)}>
          <SelectTrigger className="w-40" aria-label={t('tasks.status')}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{t('tasks.allStatuses')}</SelectItem>
            <SelectItem value="overdue">{t('tasks.overdue')}</SelectItem>
            {STATUSES.map((s) => (
              <SelectItem key={s} value={s}>
                {t(`taskStatus.${s}`)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select value={parkId} onValueChange={(v) => setFilter('park', v)}>
          <SelectTrigger className="w-52" aria-label={t('tasks.park')}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{t('tasks.allParks')}</SelectItem>
            {parks.data?.map((p) => (
              <SelectItem key={p.id} value={String(p.id)}>
                {parkName(p, i18n.language)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {tasks.isPending ? (
        <div className="flex flex-col gap-2">
          {[0, 1, 2, 3, 4].map((i) => (
            <Skeleton key={i} className="h-12 w-full" />
          ))}
        </div>
      ) : tasks.isError ? (
        <p className="text-sm text-destructive">
          {t('common.error')}{' '}
          <button className="underline" onClick={() => tasks.refetch()}>
            {t('common.retry')}
          </button>
        </p>
      ) : tasks.data.data.length === 0 ? (
        <p className="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground">{t('tasks.empty')}</p>
      ) : (
        <div className="overflow-x-auto rounded-lg border">
          <table className="w-full min-w-[1120px] text-sm">
            <thead className="bg-muted/50 text-xs text-muted-foreground">
              <tr className="[&>th]:px-3 [&>th]:py-2 [&>th]:text-start [&>th]:font-medium">
                <th>{t('tasks.colPriority')}</th>
                <th>{t('tasks.colTask')}</th>
                <th>{t('tasks.colPark')}</th>
                <th>{t('tasks.colWorker')}</th>
                <th>{t('tasks.colStatus')}</th>
                <th>{t('tasks.colDue')}</th>
                <th>{t('tasks.colVerification')}</th>
                <th>{t('tasks.colTime')}</th>
                <th>{t('tasks.colCreated')}</th>
                <th>{t('tasks.colPhoto')}</th>
                <th>
                  <span className="sr-only">{t('tasks.colActions')}</span>
                </th>
              </tr>
            </thead>
            <tbody className={cn('divide-y', tasks.isPlaceholderData && 'opacity-60')}>
              {tasks.data.data.map((task) => (
                <TaskRow key={task.id} task={task} />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function TaskRow({ task }: { task: Task }) {
  const { t, i18n } = useTranslation()
  const active = isActive(task.status)
  const urgent = task.priority === 'urgent' && active
  const frame = categoryRowFrame(task.category)

  return (
    <tr className={cn('align-middle [&>td]:px-3 [&>td]:py-2', frame, urgent && 'bg-red-500/5', !active && 'text-muted-foreground')}>
      <td>
        <div className="flex flex-col items-start gap-1">
          <PriorityBadge priority={task.priority} />
          <EscalatedBadge task={task} />
        </div>
      </td>
      <td className="max-w-64">
        {task.category && <CategoryTag category={task.category} />}
        <div className="truncate font-medium text-foreground" title={task.title}>
          {task.title}
        </div>
        {task.done_note && (
          <div className="truncate text-xs text-muted-foreground" title={task.done_note}>
            {t('tasks.doneNote')}: {task.done_note}
          </div>
        )}
      </td>
      <td>
        {task.park && (
          <Link to={`/admin/parks/${task.park.id}`} className="flex items-center gap-1.5 hover:underline">
            <span className="truncate">{parkName(task.park, i18n.language)}</span>
            <SourceBadge source={task.park.source} className="px-1 py-0 text-[0.6rem]" />
          </Link>
        )}
      </td>
      <td>{active ? <WorkerSelect task={task} /> : (task.assigned_to?.name ?? '—')}</td>
      <td>
        <TaskStatusBadge status={task.status} />
      </td>
      <td className="whitespace-nowrap">{active ? <DueBadge task={task} /> : '—'}</td>
      <td>
        <VerifyBadge task={task} />
      </td>
      <td className="tabular-nums">{task.time_to_resolve_minutes != null ? t('common.minutes', { n: task.time_to_resolve_minutes }) : '—'}</td>
      <td className="whitespace-nowrap text-xs" title={formatDateTime(task.created_at)}>
        {formatAgo(task.created_at)}
      </td>
      <td>{task.has_photo ? <TaskPhoto path={`/admin/tasks/${task.id}/photo`} alt={t('tasks.photoAlt')} className="size-10" /> : '—'}</td>
      <td className="text-end">{active && <CancelButton task={task} />}</td>
    </tr>
  )
}

function WorkerSelect({ task }: { task: Task }) {
  const { t } = useTranslation()
  const workers = useWorkers()
  const update = useUpdateTask()

  function reassign(value: string) {
    update.mutate(
      { id: task.id, assigned_to: value === NONE ? null : Number(value) },
      { onSuccess: () => toast.success(t('tasks.reassigned')), onError: () => toast.error(t('common.error')) },
    )
  }

  return (
    <Select value={task.assigned_to ? String(task.assigned_to.id) : NONE} onValueChange={reassign} disabled={update.isPending}>
      <SelectTrigger size="sm" className="w-40" aria-label={t('tasks.reassign')}>
        <SelectValue placeholder={task.assigned_to?.name ?? t('tasks.unassigned')} />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={NONE}>{t('tasks.unassigned')}</SelectItem>
        {/* Keep the current assignee listed while the worker list loads. */}
        {!workers.data && task.assigned_to && <SelectItem value={String(task.assigned_to.id)}>{task.assigned_to.name}</SelectItem>}
        {workers.data?.map((w) => (
          <SelectItem key={w.id} value={String(w.id)}>
            {t('createTask.workerLoad', { name: w.name, n: w.open_tasks + w.in_progress_tasks })}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

function CancelButton({ task }: { task: Task }) {
  const { t } = useTranslation()
  const update = useUpdateTask()

  return (
    <ConfirmDialog
      trigger={
        <Button size="icon-sm" variant="ghost" aria-label={t('tasks.cancel')} title={t('tasks.cancel')} disabled={update.isPending}>
          <Ban />
        </Button>
      }
      title={t('tasks.cancelTitle')}
      body={t('tasks.cancelBody')}
      confirmLabel={t('tasks.cancel')}
      destructive
      onConfirm={() =>
        update.mutate(
          { id: task.id, status: 'cancelled' },
          { onSuccess: () => toast.success(t('tasks.cancelled')), onError: () => toast.error(t('common.error')) },
        )
      }
    />
  )
}
