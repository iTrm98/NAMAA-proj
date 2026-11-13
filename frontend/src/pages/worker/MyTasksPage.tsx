import { CircleCheck, ClipboardList } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Chip, type ChipTone, EmptyState, GlassCard, Skeleton } from '@/components/ds'
import { isActive, sortTasks, useMyTask, useMyTasks } from '@/hooks/useOps'
import { parkName } from '@/hooks/useParks'
import { formatAgo, formatNumber } from '@/lib/format'
import { taskTitle } from '@/lib/names'
import { wateringOf } from '@/lib/watering'
import { cn } from '@/lib/utils'
import type { Task, TaskPriority } from '@/types'

const PRIORITY_TONE: Record<TaskPriority, ChipTone> = { urgent: 'urgent', high: 'high', normal: 'normal', low: 'muted' }

/** /worker: the active task as a big card (live watering progress, Continue), other active tasks, recently finished with their verification. */
export default function MyTasksPage() {
  const { t } = useTranslation()
  const tasks = useMyTasks()

  if (tasks.isPending)
    return (
      <div className="flex flex-col gap-3" aria-busy="true">
        <Skeleton h={34} w={160} />
        <Skeleton h={210} radius={22} />
        <Skeleton h={84} radius={22} />
      </div>
    )

  if (tasks.isError)
    return (
      <EmptyState
        title={t('common.error')}
        action={
          <button type="button" className="min-h-11 rounded-full border border-[var(--glass-border-strong)] px-5 text-sm" onClick={() => tasks.refetch()}>
            {t('common.retry')}
          </button>
        }
      />
    )

  const active = sortTasks(tasks.data.filter((task) => isActive(task.status)))
  const finished = tasks.data.filter((task) => !isActive(task.status))
  const [main, ...others] = active

  return (
    <div className="flex flex-col gap-4">
      <h1 className="m-0 text-[28px] font-light">{t('worker.myTasks')}</h1>
      {main ? <ActiveCard task={main} /> : <EmptyState icon={<ClipboardList />} title={t('worker.empty')} />}
      {others.map((task) => (
        <SmallCard key={task.id} task={task} />
      ))}

      {finished.length > 0 && (
        <>
          <h2 className="m-0 mt-1 text-xs font-normal text-text-2">{t('worker.recent')}</h2>
          {finished.map((task) => (
            <SmallCard key={task.id} task={task} />
          ))}
        </>
      )}
    </div>
  )
}

/** The task to do now: chips, title, park, moisture bar toward the target, Continue. */
function ActiveCard({ task }: { task: Task }) {
  const { t, i18n } = useTranslation()
  const detail = useMyTask(task.id)
  const water = detail.data ? wateringOf(detail.data) : null
  const started = task.status === 'in_progress'

  return (
    <Link
      to={`/worker/tasks/${task.id}`}
      className={cn(
        'flex flex-col gap-2 rounded-[var(--r-worker)] border bg-white/[.06] p-[18px] text-[var(--text)] no-underline',
        task.priority === 'urgent' ? 'border-[rgba(255,107,94,.45)]' : 'border-[rgba(255,154,98,.40)]',
      )}
    >
      <div className="flex flex-wrap gap-1.5">
        <Chip tone={PRIORITY_TONE[task.priority]}>{t(`priority.${task.priority}`)}</Chip>
        <Chip tone="muted">{t(`taskStatus.${task.status}`)}</Chip>
        {task.category && <Chip tone="muted">{t(`category.${task.category}`)}</Chip>}
        {task.park?.site_type === 'demo_stand' && <Chip tone="muted">{t('taskCard.stand')}</Chip>}
      </div>
      <span className="text-lg font-medium">{taskTitle(t, i18n.language, task)}</span>
      {task.park && (
        <span className="text-xs text-text-2">
          {parkName(task.park, i18n.language)}
          {task.park.district ? ` · ${task.park.district}` : ''}
          {task.alert ? ` · ${t(`alertType.${task.alert.type}`)}` : ''}
        </span>
      )}
      {water && (
        <div className="mt-1 flex flex-col gap-1.5">
          <Bar value={water.current ?? 0} reached={water.reached} />
          <div className="flex justify-between text-xs text-text-2">
            <span>{t('worker.moistureShort', { value: water.current == null ? '—' : formatNumber(water.current) })}</span>
            <span>{t('worker.waterUntil', { target: formatNumber(water.target) })}</span>
          </div>
        </div>
      )}
      <span className="mt-1.5 flex h-12 items-center justify-center rounded-full bg-[var(--accent-lime)] text-[15px] font-medium text-[var(--on-accent)]">
        {started ? t('worker.continue') : t('worker.start')}
      </span>
    </Link>
  )
}

export function Bar({ value, reached }: { value: number; reached?: boolean }) {
  return (
    <div className="h-2 overflow-hidden rounded-full bg-white/10" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(value)}>
      <div
        className="h-full origin-left rounded-full rtl:origin-right"
        style={{ background: reached ? 'var(--healthy)' : 'var(--accent-lime)', transform: `scaleX(${Math.min(100, Math.max(0, value)) / 100})`, transition: 'transform .3s ease-out' }}
      />
    </div>
  )
}

function SmallCard({ task }: { task: Task }) {
  const { t, i18n } = useTranslation()
  const done = task.status === 'done'
  return (
    <GlassCard as="article" radius="worker" padding={16} className="relative">
      <Link to={`/worker/tasks/${task.id}`} className="flex flex-col gap-2 text-[var(--text)] no-underline">
        <div className="flex flex-wrap gap-1.5">
          <Chip tone={PRIORITY_TONE[task.priority]}>{t(`priority.${task.priority}`)}</Chip>
          {done ? (
            task.verified === true ? (
              <Chip tone="ok">
                <CircleCheck className="me-1 size-3" aria-hidden />
                {t('verify.verified')}
              </Chip>
            ) : task.verified === false ? (
              <Chip tone="urgent">{t('verify.notVerified')}</Chip>
            ) : (
              <Chip tone="muted">{t('verify.waiting')}</Chip>
            )
          ) : (
            <Chip tone="muted">{t(`taskStatus.${task.status}`)}</Chip>
          )}
        </div>
        <span className="text-[15px] font-medium">{taskTitle(t, i18n.language, task)}</span>
        <span className="text-xs text-text-2">
          {task.park ? `${parkName(task.park, i18n.language)} · ` : ''}
          {done && task.verified && task.time_to_resolve_minutes != null ? t('worker.verifiedIn', { n: task.time_to_resolve_minutes }) : formatAgo(task.created_at)}
        </span>
      </Link>
    </GlassCard>
  )
}
