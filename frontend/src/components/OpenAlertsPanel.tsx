import { BellRing, CheckCircle2, ClipboardPlus, Droplets, Eye } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { FrameImage } from '@/components/camera'
import { CreateTaskDialog, type TaskSeed } from '@/components/CreateTaskDialog'
import { alertDetail, CategoryTag, categoryFrame, DueBadge, PriorityBadge, TaskStatusBadge, VerifyBadge } from '@/components/ops'
import { SourceBadge } from '@/components/StatusPill'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useDemoStatus, useOpenAlerts } from '@/hooks/useOps'
import { parkName } from '@/hooks/useParks'
import { formatAgo, formatDateTime } from '@/lib/format'
import { useBasePath, useReadOnly } from '@/lib/scope'
import { cn } from '@/lib/utils'
import type { Alert } from '@/types'

/**
 * Dashboard panel: open alerts by priority (urgent first) then oldest. Each
 * card: priority badge, category icon + frame colour, the numbers in plain
 * words, and "Create task", "Watching" (offline, not task-ready yet) or its
 * task's state.
 */
export function OpenAlertsPanel({ className }: { className?: string }) {
  const { t } = useTranslation()
  const alerts = useOpenAlerts()
  const [seed, setSeed] = useState<TaskSeed | null>(null)
  const readOnly = useReadOnly()

  return (
    <section className={cn('flex min-h-0 flex-col', className)} aria-labelledby="open-alerts-title">
      <div className="flex items-center gap-2 px-4 pt-4 pb-2">
        <BellRing className="size-4 text-muted-foreground" />
        <h2 id="open-alerts-title" className="text-sm font-semibold">
          {t('alerts.title')}
        </h2>
        {alerts.data && alerts.data.length > 0 && <Badge variant="secondary">{alerts.data.length}</Badge>}
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto px-3 pb-4">
        {alerts.isPending && (
          <div className="flex flex-col gap-2 p-1">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-24 w-full" />
            ))}
          </div>
        )}
        {alerts.isError && (
          <p className="p-2 text-sm text-destructive">
            {t('common.error')}{' '}
            <button className="underline" onClick={() => alerts.refetch()}>
              {t('common.retry')}
            </button>
          </p>
        )}
        {alerts.data?.length === 0 && (
          <p className="flex items-center gap-2 p-2 text-sm text-muted-foreground">
            <CheckCircle2 className="size-4 text-status-ok" />
            {t('alerts.empty')}
          </p>
        )}
        <ul className="flex flex-col gap-2">
          {alerts.data?.map((alert) => (
            <AlertItem key={alert.id} alert={alert} onCreate={readOnly ? undefined : setSeed} />
          ))}
        </ul>
      </div>

      {!readOnly && <CreateTaskDialog seed={seed} onOpenChange={(open) => !open && setSeed(null)} />}
    </section>
  )
}

function AlertItem({ alert, onCreate }: { alert: Alert; onCreate?: (seed: TaskSeed) => void }) {
  const base = useBasePath()
  const { t, i18n } = useTranslation()
  const demo = useDemoStatus()
  const urgent = alert.priority === 'urgent'
  const detail = alertDetail(t, alert.type, alert.data)
  const autoRunning = (alert.type === 'dry' || alert.type === 'heat_stress') && alert.data?.auto_irrigation === true
  const park = alert.park ?? { id: alert.park_id, name_ar: '', name_en: '', source: 'real' as const, auto_irrigation: false }

  return (
    <li
      className={cn(
        'flex flex-col gap-2 rounded-lg border bg-card p-3 text-sm',
        categoryFrame(alert.category),
        urgent && 'bg-red-500/5',
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="flex min-w-0 flex-col gap-1">
          <div className="flex flex-wrap items-center gap-1.5">
            <PriorityBadge priority={alert.priority} />
            <span className="font-medium">{t(`alertType.${alert.type}`)}</span>
          </div>
          <CategoryTag category={alert.category} />
          <Link to={`${base}/parks/${alert.park_id}`} className="flex items-center gap-1.5 truncate text-muted-foreground hover:text-foreground hover:underline">
            {parkName(park, i18n.language)}
            <SourceBadge source={park.source} className="px-1 py-0 text-[0.6rem]" />
            {alert.park?.company && <span className="truncate text-xs">· {alert.park.company.name}</span>}
          </Link>
        </div>
        <span className="shrink-0 text-xs text-muted-foreground" title={formatDateTime(alert.created_at)}>
          {formatAgo(alert.created_at)}
        </span>
      </div>

      {detail && <p className="text-xs text-muted-foreground">{detail}</p>}
      {alert.detection && <FrameImage detection={alert.detection} labels={false} className="w-48" />}

      {autoRunning ? (
        <p className="flex items-center gap-1.5 text-xs text-status-over">
          <Droplets className="size-3.5" />
          {t('alerts.autoRunning')}
        </p>
      ) : alert.task ? (
        <div className="flex flex-wrap items-center gap-2 text-xs">
          <TaskStatusBadge status={alert.task.status} />
          <span className="text-muted-foreground">{alert.task.assigned_to?.name ?? t('tasks.unassigned')}</span>
          <DueBadge task={alert.task} />
          {alert.task.status === 'done' && <VerifyBadge task={{ status: 'done', verified: alert.task.verified, verify_reason: null }} />}
        </div>
      ) : !alert.task_ready ? (
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
          <Eye className="size-3.5" />
          {t('alerts.watching', { n: demo.data ? 2 : 30 })}
        </p>
      ) : !onCreate ? null : (
        <Button
          size="sm"
          variant={urgent || alert.priority === 'high' ? 'default' : 'outline'}
          className="self-start"
          onClick={() => onCreate({ park, alert: { id: alert.id, type: alert.type, priority: alert.priority } })}
        >
          <ClipboardPlus />
          {t('alerts.createTask')}
        </Button>
      )}
    </li>
  )
}
