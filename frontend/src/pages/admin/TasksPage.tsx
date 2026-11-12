import { AlertTriangle, Ban, CheckCheck, ClipboardList, Plus } from 'lucide-react'
import { type ReactNode, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Chip, type ChipTone, Drawer, EmptyState, GlassCard, Kpi, Reveal, Segmented, Skeleton, TaskCard } from '@/components/ds'
import { CategoryTag, DueBadge, EscalatedBadge, TaskPhoto, TaskStatusBadge, VerifyBadge } from '@/components/ops'
import { CREATE_TASK_EVENT } from '@/components/shell/QuickActions'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { isActive, type TaskBoard, useTaskBoard, useUpdateTask, useWorkers } from '@/hooks/useOps'
import { useOverview } from '@/hooks/useOverview'
import { parkName, useParks } from '@/hooks/useParks'
import { formatAgo, formatDateTime } from '@/lib/format'
import { personName, taskTitle } from '@/lib/names'
import { cn } from '@/lib/utils'
import type { Task, TaskPriority } from '@/types'

const COLUMNS = ['todo', 'in_progress', 'checking', 'closed'] as const
const HINT = { todo: 'hintTodo', in_progress: 'hintInProgress', checking: 'hintChecking', closed: 'hintClosed' } as const
const PRIORITIES: TaskPriority[] = ['urgent', 'high', 'normal', 'low']
const PRIORITY_TONE: Record<TaskPriority, ChipTone> = { urgent: 'urgent', high: 'high', normal: 'normal', low: 'muted' }
const NONE = 'none'
const BTN_GHOST = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-[var(--glass-border-strong)] px-5 text-sm text-[var(--text)] hover:bg-white/10 disabled:opacity-50'

/** The closing line of a task card: verified with minutes, not verified with the reason, checking, cancelled. */
function useResult() {
  const { t } = useTranslation()
  return (task: Task): { text: string; tone: 'ok' | 'bad' | 'muted' | 'warn' } | undefined => {
    if (task.status === 'cancelled') return { text: t('tasksPage.resultCancelled'), tone: 'muted' }
    if (task.status !== 'done') return undefined
    if (task.verified === true) return { text: t('tasksPage.resultVerified', { n: task.time_to_resolve_minutes ?? 0 }), tone: 'ok' }
    if (task.verified === false) return { text: t('tasksPage.resultNotVerified', { reason: task.verify_reason ? t(`verify.${task.verify_reason}`) : t('verify.notVerified') }), tone: 'bad' }
    return { text: t('tasksPage.resultChecking'), tone: 'warn' }
  }
}

/** /admin/tasks: the board To do / In progress / Checking / Closed. Filters: ?park=, priority; ?status=overdue still works. */
export default function TasksPage() {
  const { t, i18n } = useTranslation()
  const [params, setParams] = useSearchParams()
  const parkId = params.get('park') ?? 'all'
  const overdueOnly = params.get('status') === 'overdue'
  const [priority, setPriority] = useState<TaskPriority | 'all'>('all')
  const [view, setView] = useState<'board' | 'table'>('board')
  const [openId, setOpenId] = useState<number | null>(null)
  const board = useTaskBoard(parkId)
  const parks = useParks()
  const overview = useOverview()
  const result = useResult()

  const watering = useMemo(() => {
    const map = new Map<number, number>()
    for (const x of overview.data?.needs_attention.tasks_in_progress ?? []) if (x.watering) map.set(x.id, x.watering.progress_pct)
    return map
  }, [overview.data])

  function setPark(value: string) {
    const next = new URLSearchParams(params)
    if (value === 'all') next.delete('park')
    else next.set('park', value)
    setParams(next, { replace: true })
  }

  const columns = useMemo(() => {
    const out = {} as TaskBoard
    for (const c of COLUMNS) {
      out[c] = (board.data?.data[c] ?? []).filter((x) => (priority === 'all' || x.priority === priority) && (!overdueOnly || x.overdue))
    }
    return out
  }, [board.data, priority, overdueOnly])
  const all = COLUMNS.flatMap((c) => columns[c])
  const opened = all.find((x) => x.id === openId) ?? null

  const counts = board.data?.counts
  const closedKnown = (board.data?.data.closed ?? []).filter((x) => x.verified !== null)
  const verifiedN = closedKnown.filter((x) => x.verified === true).length
  const escalated = [...(board.data?.data.todo ?? []), ...(board.data?.data.in_progress ?? [])].filter((x) => x.escalated_at).length

  return (
    <div className="flex flex-col gap-5 p-1">
      <section className="flex flex-wrap items-center gap-7">
        <h1 className="m-0 text-[40px] leading-[1.05] font-light tracking-[-.01em]">{t('tasks.title')}</h1>
        {counts && (
          <>
            <Kpi icon={<ClipboardList />} value={counts.todo + counts.in_progress} label={t('tasksPage.kpiOpen')} />
            {closedKnown.length > 0 && (
              <Kpi
                icon={<CheckCheck />}
                value={Math.round((verifiedN / closedKnown.length) * 100)}
                suffix="%"
                label={t('tasksPage.kpiVerified')}
                badge={{ tone: 'muted', children: `${verifiedN} / ${closedKnown.length}` }}
              />
            )}
            {escalated > 0 && <Kpi icon={<AlertTriangle />} value={escalated} label={t('tasksPage.kpiEscalated')} />}
          </>
        )}
        <div className="flex-1" />
        <button
          type="button"
          className="inline-flex min-h-11 items-center gap-2 rounded-full bg-[var(--accent-lime)] px-5 text-sm font-medium text-[var(--on-accent)] hover:brightness-95"
          onClick={() => window.dispatchEvent(new Event(CREATE_TASK_EVENT))}
        >
          <Plus className="size-4" aria-hidden />
          {t('tasksPage.newTask')}
        </button>
      </section>

      <p className="m-0 text-[13px] text-text-2">{t('tasksPage.rule')}</p>

      <section className="flex flex-wrap items-center gap-2.5">
        <Select value={parkId} onValueChange={setPark}>
          <SelectTrigger className="h-10 w-52 rounded-full" aria-label={t('tasks.park')}>
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
        <Segmented
          options={[{ value: 'all', label: t('tasksPage.allPriorities') }, ...PRIORITIES.map((p) => ({ value: p, label: t(`priority.${p}`) }))]}
          value={priority}
          onChange={setPriority}
          ariaLabel={t('tasksPage.priorityLabel')}
          className="max-w-full overflow-x-auto"
        />
        {overdueOnly && (
          <button type="button" className="inline-flex" onClick={() => setParams(new URLSearchParams([...params].filter(([k]) => k !== 'status')), { replace: true })}>
            <Chip tone="high">{t('tasks.overdue')} ×</Chip>
          </button>
        )}
        <div className="flex-1" />
        <Segmented
          options={[
            { value: 'board', label: t('tasksPage.board') },
            { value: 'table', label: t('tasksPage.table') },
          ]}
          value={view}
          onChange={setView}
          ariaLabel={t('tasksPage.viewLabel')}
        />
      </section>

      {board.isPending && (
        <div className="flex flex-wrap gap-4">
          {COLUMNS.map((c) => (
            <Skeleton key={c} h={260} radius={24} className="flex-[1_1_260px]" />
          ))}
        </div>
      )}
      {board.isError && (
        <EmptyState
          title={t('common.error')}
          action={
            <button type="button" className={BTN_GHOST} onClick={() => board.refetch()}>
              {t('common.retry')}
            </button>
          }
        />
      )}

      {board.data && view === 'board' && (
        <section className={cn('flex flex-wrap items-start gap-4', board.isPlaceholderData && 'opacity-60')}>
          {COLUMNS.map((c) => (
            <div key={c} className="flex min-w-0 flex-[1_1_260px] flex-col gap-3 rounded-[var(--r-card-sm)] border border-[var(--glass-border)] bg-white/[.04] p-3.5">
              <div className="flex items-center justify-between px-1">
                <h2 className="m-0 text-[15px] font-medium">{t(`tasksPage.${c}`)}</h2>
                <Chip tone="muted">{columns[c].length}</Chip>
              </div>
              <span className="px-1 text-xs text-text-2">{t(`tasksPage.${HINT[c]}`)}</span>
              {columns[c].length === 0 && <p className="m-0 rounded-[var(--r-mini)] border border-dashed border-[var(--glass-border)] p-4 text-center text-xs text-text-2">{t('tasksPage.emptyCol')}</p>}
              <div className="flex max-h-[calc(100vh-340px)] min-h-0 flex-col gap-3 overflow-y-auto pe-0.5">
              {columns[c].map((task, i) => (
                <Reveal key={task.id} index={i}>
                <TaskCard
                  task={task}
                  hideStatus
                  category={task.category ? t(`category.${task.category}`) : undefined}
                  assignee={task.assigned_to ? personName(task.assigned_to, i18n.language) : t('tasks.unassigned')}
                  progress={task.status === 'in_progress' ? watering.get(task.id) : undefined}
                  result={result(task)}
                  onClick={() => setOpenId(task.id)}
                />
                </Reveal>
              ))}
              </div>
              {counts && counts[c] > (board.data.data[c]?.length ?? 0) && (
                <span className="px-1 text-xs text-text-2">{t('tasksPage.showing', { shown: board.data.data[c].length, total: counts[c] })}</span>
              )}
            </div>
          ))}
        </section>
      )}

      {board.data && view === 'table' && (all.length === 0 ? <EmptyState icon={<ClipboardList />} title={t('tasks.empty')} /> : <TaskTable tasks={all} onOpen={setOpenId} />)}

      <Drawer open={opened !== null} onOpenChange={(o) => !o && setOpenId(null)} title={opened ? taskTitle(t, i18n.language, opened) : ''} description={opened?.park ? parkName(opened.park, i18n.language) : undefined} width={440}>
        {opened && <TaskDetails task={opened} />}
      </Drawer>
    </div>
  )
}

function TaskTable({ tasks, onOpen }: { tasks: Task[]; onOpen: (id: number) => void }) {
  const { t, i18n } = useTranslation()
  const result = useResult()
  const hasWorker = tasks.some((x) => x.assigned_to)
  const th = 'px-4 py-3 text-start text-xs font-medium text-text-2'
  return (
    <GlassCard radius="sm" padding={0} className="overflow-x-auto">
      <table className="w-full min-w-[820px] border-collapse text-[13px]">
        <thead>
          <tr className="border-b border-[var(--glass-border)]">
            <th className={th}>{t('tasks.colPriority')}</th>
            <th className={th}>{t('tasks.colTask')}</th>
            <th className={th}>{t('tasks.colPark')}</th>
            {hasWorker && <th className={th}>{t('tasks.colWorker')}</th>}
            <th className={th}>{t('tasks.colStatus')}</th>
            <th className={th}>{t('tasks.colVerification')}</th>
            <th className={th}>{t('tasks.colCreated')}</th>
          </tr>
        </thead>
        <tbody>
          {tasks.map((task) => (
            <tr key={task.id} className="cursor-pointer border-b border-[var(--glass-border)] last:border-0 hover:bg-white/[.04]" onClick={() => onOpen(task.id)}>
              <td className="px-4 py-3">
                <Chip tone={PRIORITY_TONE[task.priority]}>{t(`priority.${task.priority}`)}</Chip>
              </td>
              <td className="max-w-72 truncate px-4 py-3 font-medium">{taskTitle(t, i18n.language, task)}</td>
              <td className="px-4 py-3 text-text-2">{task.park && parkName(task.park, i18n.language)}</td>
              {hasWorker && <td className="px-4 py-3">{task.assigned_to && personName(task.assigned_to, i18n.language)}</td>}
              <td className="px-4 py-3">
                <TaskStatusBadge status={task.status} />
              </td>
              <td className="px-4 py-3 text-text-2">{result(task)?.text}</td>
              <td className="px-4 py-3 text-xs text-text-2" title={formatDateTime(task.created_at)}>
                {formatAgo(task.created_at)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </GlassCard>
  )
}

/** Drawer body: details and every task action (reassign, change priority, cancel, photo). */
function TaskDetails({ task }: { task: Task }) {
  const { t, i18n } = useTranslation()
  const active = isActive(task.status)
  const update = useUpdateTask()
  const workers = useWorkers(active)

  function reassign(value: string) {
    update.mutate(
      { id: task.id, assigned_to: value === NONE ? null : Number(value) },
      { onSuccess: () => toast.success(t('tasks.reassigned')), onError: () => toast.error(t('common.error')) },
    )
  }
  function changePriority(value: string) {
    update.mutate(
      { id: task.id, priority: value as TaskPriority },
      { onSuccess: () => toast.success(t('tasksPage.priorityChanged')), onError: () => toast.error(t('common.error')) },
    )
  }

  const rows: [string, ReactNode][] = [
    [t('tasks.colStatus'), <TaskStatusBadge key="s" status={task.status} />],
    [t('tasks.colVerification'), task.status === 'done' || task.verified !== null ? <VerifyBadge key="v" task={task} /> : null],
    [t('tasksPage.created'), formatAgo(task.created_at)],
    [t('tasks.colDue'), active ? <DueBadge key="d" task={task} /> : null],
    [t('tasks.colTime'), task.time_to_resolve_minutes != null ? t('common.minutes', { n: task.time_to_resolve_minutes }) : null],
  ]

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-1.5">
        <Chip tone={PRIORITY_TONE[task.priority]}>{t(`priority.${task.priority}`)}</Chip>
        {task.category && <CategoryTag category={task.category} />}
        <EscalatedBadge task={task} />
      </div>
      <dl className="m-0 flex flex-col text-[13px]">
        {rows
          .filter(([, v]) => v)
          .map(([k, v]) => (
            <div key={k} className="flex items-center justify-between gap-3 border-b border-[var(--glass-border)] py-2">
              <dt className="text-text-2">{k}</dt>
              <dd className="m-0 text-end">{v}</dd>
            </div>
          ))}
      </dl>
      {task.note && (
        <div>
          <h3 className="m-0 mb-1 text-[13px] font-medium">{t('tasksPage.note')}</h3>
          <p className="m-0 text-[13px] text-text-2">{task.note}</p>
        </div>
      )}
      {task.done_note && (
        <div>
          <h3 className="m-0 mb-1 text-[13px] font-medium">{t('tasks.doneNote')}</h3>
          <p className="m-0 text-[13px] text-text-2">{task.done_note}</p>
        </div>
      )}
      {task.has_photo && <TaskPhoto path={`/admin/tasks/${task.id}/photo`} alt={t('tasks.photoAlt')} className="max-h-64 w-full rounded-[var(--r-mini)] object-cover" />}

      {active ? (
        <div className="flex flex-col gap-3">
          <label className="flex flex-col gap-1.5 text-xs text-text-2">
            {t('tasksPage.assignee')}
            <Select value={task.assigned_to ? String(task.assigned_to.id) : NONE} onValueChange={reassign} disabled={update.isPending}>
              <SelectTrigger className="h-11 w-full rounded-[14px]" aria-label={t('tasks.reassign')}>
                <SelectValue placeholder={task.assigned_to ? personName(task.assigned_to, i18n.language) : t('tasks.unassigned')} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={NONE}>{t('tasks.unassigned')}</SelectItem>
                {!workers.data && task.assigned_to && <SelectItem value={String(task.assigned_to.id)}>{personName(task.assigned_to, i18n.language)}</SelectItem>}
                {workers.data?.map((w) => (
                  <SelectItem key={w.id} value={String(w.id)}>
                    {t('createTask.workerLoad', { name: personName(w, i18n.language), n: w.open_tasks + w.in_progress_tasks })}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </label>
          <div className="flex flex-col gap-1.5 text-xs text-text-2">
            {t('tasksPage.changePriority')}
            <Segmented
              options={PRIORITIES.map((p) => ({ value: p, label: t(`priority.${p}`) }))}
              value={task.priority}
              onChange={changePriority}
              ariaLabel={t('tasksPage.changePriority')}
              className="max-w-full overflow-x-auto"
            />
          </div>
          <ConfirmDialog
            trigger={
              <button type="button" className={cn(BTN_GHOST, 'border-[rgba(255,107,94,.45)] text-[var(--chip-urgent-fg)]')} disabled={update.isPending}>
                <Ban className="size-4" aria-hidden />
                {t('tasks.cancel')}
              </button>
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
        </div>
      ) : (
        <p className="m-0 text-xs text-text-2">{t('tasksPage.noActions')}</p>
      )}

      <Link to={`/admin/parks/${task.park_id}`} className="text-[13px] text-[var(--accent-lime)] no-underline hover:underline">
        {t('tasksPage.openPark')}
      </Link>
    </div>
  )
}
