import { ChevronLeft, MapPin } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { CategoryTag, categoryFrame, DueBadge, PriorityBadge, TaskStatusBadge, VerifyBadge } from '@/components/ops'
import { SourceBadge } from '@/components/StatusPill'
import { Skeleton } from '@/components/ui/skeleton'
import { isActive, sortTasks, useMyTasks } from '@/hooks/useOps'
import { parkName } from '@/hooks/useParks'
import { formatAgo } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { Task } from '@/types'

/** /worker: big cards (priority badge, category frame, time left), urgent first then oldest; finished tasks below. */
export default function MyTasksPage() {
  const { t } = useTranslation()
  const tasks = useMyTasks()

  if (tasks.isPending)
    return (
      <div className="flex flex-col gap-3">
        {[0, 1, 2].map((i) => (
          <Skeleton key={i} className="h-28 w-full rounded-xl" />
        ))}
      </div>
    )

  if (tasks.isError)
    return (
      <p className="text-sm text-destructive">
        {t('common.error')}{' '}
        <button className="min-h-11 underline" onClick={() => tasks.refetch()}>
          {t('common.retry')}
        </button>
      </p>
    )

  const active = sortTasks(tasks.data.filter((task) => isActive(task.status)))
  const finished = tasks.data.filter((task) => !isActive(task.status))

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-xl font-semibold">{t('worker.myTasks')}</h1>
      {active.length === 0 ? (
        <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">{t('worker.empty')}</p>
      ) : (
        <ul className="flex flex-col gap-3">
          {active.map((task) => (
            <TaskCard key={task.id} task={task} />
          ))}
        </ul>
      )}

      {finished.length > 0 && (
        <>
          <h2 className="mt-2 text-sm font-semibold text-muted-foreground">{t('worker.recent')}</h2>
          <ul className="flex flex-col gap-2">
            {finished.map((task) => (
              <TaskCard key={task.id} task={task} compact />
            ))}
          </ul>
        </>
      )}
    </div>
  )
}

function TaskCard({ task, compact }: { task: Task; compact?: boolean }) {
  const { i18n } = useTranslation()
  const urgent = task.priority === 'urgent' && isActive(task.status)

  return (
    <li>
      <Link
        to={`/worker/tasks/${task.id}`}
        className={cn(
          'flex min-h-11 items-center gap-3 rounded-xl border bg-card transition-colors hover:bg-muted/50 active:bg-muted',
          compact ? 'p-3' : 'p-4',
          categoryFrame(task.category),
          urgent && 'bg-red-500/5',
        )}
      >
        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
          <div className="flex flex-wrap items-center gap-1.5">
            <PriorityBadge priority={task.priority} className={urgent ? 'px-2 text-sm' : undefined} />
            <TaskStatusBadge status={task.status} />
            {task.category && <CategoryTag category={task.category} />}
            {task.status === 'done' && <VerifyBadge task={task} />}
          </div>
          <div className={cn('font-semibold', compact ? 'text-sm' : 'text-base')}>{task.title}</div>
          {task.park && (
            <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
              <MapPin className="size-3.5 shrink-0" />
              <span className="truncate">
                {parkName(task.park, i18n.language)}
                {task.park.district ? ` · ${task.park.district}` : ''}
              </span>
              <SourceBadge source={task.park.source} className="px-1 py-0 text-[0.6rem]" />
            </div>
          )}
          <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            <span>{formatAgo(task.created_at)}</span>
            <DueBadge task={task} />
          </div>
        </div>
        <ChevronLeft className="size-5 shrink-0 text-muted-foreground ltr:-scale-x-100" />
      </Link>
    </li>
  )
}
