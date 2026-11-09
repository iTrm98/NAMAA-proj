import { ArrowLeft, BatteryMedium, ClipboardPlus, Droplets, Eye, Gauge, Power, ShieldAlert, Thermometer, Timer, Waves } from 'lucide-react'
import { type ReactNode, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { CreateTaskDialog, type TaskSeed } from '@/components/CreateTaskDialog'
import { alertDetail, CategoryTag, categoryFrame, DueBadge, EscalatedBadge, PriorityBadge, TaskStatusBadge, useCountdown } from '@/components/ops'
import { ReadingCharts } from '@/components/ReadingCharts'
import { SourceBadge, StatusPill } from '@/components/StatusPill'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { sortAlerts, sortTasks, useDemoStatus, useSwitchPump, useUpdatePark } from '@/hooks/useOps'
import { parkName, usePark } from '@/hooks/useParks'
import { ApiError } from '@/lib/api'
import { formatAgo, formatDateTime, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { ParkDetail, ReadingRange } from '@/types'

export default function ParkPage() {
  const { t, i18n } = useTranslation()
  const id = Number(useParams().id)
  const park = usePark(id)
  const demo = useDemoStatus()
  const [picked, setPicked] = useState<ReadingRange | null>(null)
  // Demo mode opens on the last hour, so the live story is visible at once.
  const range: ReadingRange = picked ?? (demo.data ? '1h' : '24h')
  const [seed, setSeed] = useState<TaskSeed | null>(null)

  if (park.isPending) {
    return (
      <div className="flex flex-col gap-4 p-6">
        <Skeleton className="h-10 w-72" />
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
          {[0, 1, 2, 3, 4, 5].map((i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
        <Skeleton className="h-72" />
      </div>
    )
  }

  if (park.isError) {
    const notFound = park.error instanceof ApiError && park.error.status === 404
    return (
      <div className="flex flex-col items-start gap-3 p-6">
        <p className={cn('text-sm', notFound ? 'text-muted-foreground' : 'text-destructive')}>
          {notFound ? t('park.notFound') : t('common.error')}
        </p>
        <Button asChild variant="outline" size="sm">
          <Link to="/admin">{t('common.back')}</Link>
        </Button>
      </div>
    )
  }

  const p = park.data

  return (
    <div className="flex flex-col gap-5 p-4 lg:p-6">
      <div className="flex flex-wrap items-center gap-3">
        <Button asChild variant="ghost" size="icon" aria-label={t('common.back')} title={t('common.back')}>
          <Link to="/admin">
            <ArrowLeft className="rtl:-scale-x-100" />
          </Link>
        </Button>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-semibold">{parkName(p, i18n.language)}</h1>
            <StatusPill status={p.status} />
            <SourceBadge source={p.source} />
          </div>
          <p className="text-sm text-muted-foreground">
            {[p.district, t(`parkType.${p.park_type}`), p.area_m2 ? t('park.area', { area: formatNumber(p.area_m2, 0) }) : null]
              .filter(Boolean)
              .join(' · ')}
          </p>
        </div>
        <div className="ms-auto flex items-center gap-3">
          <span className="text-xs text-muted-foreground">{t('park.liveNote')}</span>
          <Button size="sm" variant="outline" onClick={() => setSeed({ park: p })}>
            <ClipboardPlus />
            {t('park.createTask')}
          </Button>
        </div>
      </div>

      <StatCards park={p} />

      {p.has_pump && <PumpCard park={p} />}

      <Card>
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
          <div>
            <CardTitle>{t('park.chartMoisture')}</CardTitle>
            <CardDescription>{t('park.thresholds', { min: p.moisture_min, max: p.moisture_max })}</CardDescription>
          </div>
          <Tabs value={range} onValueChange={(v) => setPicked(v as ReadingRange)}>
            <TabsList>
              <TabsTrigger value="1h">{t('park.range1h')}</TabsTrigger>
              <TabsTrigger value="24h">{t('park.range24h')}</TabsTrigger>
              <TabsTrigger value="7d">{t('park.range7d')}</TabsTrigger>
            </TabsList>
          </Tabs>
        </CardHeader>
        <CardContent>
          <ReadingCharts park={p} range={range} />
        </CardContent>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <NodesCard park={p} />
        <AlertsCard park={p} onCreate={setSeed} />
        <TasksCard park={p} />
      </div>

      <CreateTaskDialog seed={seed} onOpenChange={(open) => !open && setSeed(null)} />
    </div>
  )
}

/** Pump: state, who/why, safety countdown, On/Off with confirmation, and the auto-irrigation switch. */
function PumpCard({ park }: { park: ParkDetail }) {
  const { t } = useTranslation()
  const pump = park.pump
  const demo = useDemoStatus()
  const switchPump = useSwitchPump(park.id)
  const updatePark = useUpdatePark(park.id)
  const target = pump?.target ?? park.pump_target ?? false
  const countdown = useCountdown(target ? pump?.cutoff_at : null)
  const who = pump?.by?.name ?? t('pump.system')

  function setPump(on: boolean) {
    switchPump.mutate(on, {
      onSuccess: () => toast.success(t('pump.switched')),
      onError: () => toast.error(t('common.error')),
    })
  }

  function setAuto(on: boolean) {
    updatePark.mutate(
      { auto_irrigation: on },
      {
        onSuccess: () => toast.success(on ? t('pump.autoOn') : t('pump.autoOff')),
        onError: () => toast.error(t('common.error')),
      },
    )
  }

  return (
    <Card>
      <CardContent className="grid gap-5 md:grid-cols-[1fr_auto_1fr]">
        <div className="flex flex-col gap-3">
          <div className="flex items-center gap-3">
            <span className={cn('flex size-10 items-center justify-center rounded-full', target ? 'bg-status-over/15 text-status-over' : 'bg-muted text-muted-foreground')}>
              <Power className="size-5" />
            </span>
            <div>
              <div className="text-sm text-muted-foreground">{t('pump.title')}</div>
              <div className="text-lg font-semibold">{target ? t('pump.on') : t('pump.off')}</div>
            </div>
            <ConfirmDialog
              trigger={
                <Button className="ms-auto" variant={target ? 'outline' : 'default'} disabled={switchPump.isPending}>
                  {target ? t('pump.turnOff') : t('pump.turnOn')}
                </Button>
              }
              title={target ? t('pump.confirmOffTitle') : t('pump.confirmOnTitle')}
              body={t('pump.confirmBody', { n: demo.data ? 5 : 30 })}
              onConfirm={() => setPump(!target)}
            />
          </div>
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-xs">
            <dt className="text-muted-foreground">{t('pump.reported')}</dt>
            <dd>{pump?.reported_on == null ? t('pump.unknown') : pump.reported_on ? t('pump.on') : t('pump.off')}</dd>
            {pump?.changed_at && (
              <>
                <dt className="text-muted-foreground">{t('pump.lastChange')}</dt>
                <dd title={formatDateTime(pump.changed_at)}>
                  {formatAgo(pump.changed_at)} · {who}
                  {pump.reason && ` · ${t(`pumpReason.${pump.reason}`)}`}
                </dd>
              </>
            )}
          </dl>
          {target && countdown ? (
            <p className="flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-400">
              <ShieldAlert className="size-3.5" />
              {t('pump.cutoffIn', { time: countdown })}
            </p>
          ) : pump ? (
            <p className="text-xs text-muted-foreground">{t('pump.maxRun', { n: Math.round(pump.max_run_seconds / 60) })}</p>
          ) : null}
        </div>

        <div className="hidden w-px bg-border md:block" />

        <div className="flex flex-col gap-2">
          <div className="flex items-center justify-between gap-3">
            <label htmlFor="auto-irrigation" className="font-medium">
              {t('pump.autoTitle')}
            </label>
            <Switch id="auto-irrigation" checked={park.auto_irrigation} disabled={updatePark.isPending} onCheckedChange={setAuto} />
          </div>
          <p className="text-xs text-muted-foreground">{t('pump.autoHelp', { mid: Math.round((park.moisture_min + park.moisture_max) / 2) })}</p>
          {park.auto_irrigation && target && pump?.reason === 'rule' && (
            <p className="flex items-center gap-1.5 text-xs text-status-over">
              <Droplets className="size-3.5" />
              {t('pump.autoRunning')}
            </p>
          )}
        </div>
      </CardContent>
    </Card>
  )
}

function TasksCard({ park }: { park: ParkDetail }) {
  const { t } = useTranslation()
  const tasks = sortTasks(park.open_tasks)

  return (
    <Card className="lg:col-span-2">
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle>{t('park.openTasks')}</CardTitle>
        <Button asChild variant="link" size="sm">
          <Link to={`/admin/tasks?park=${park.id}`}>{t('nav.tasks')}</Link>
        </Button>
      </CardHeader>
      <CardContent>
        {tasks.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('park.noTasks')}</p>
        ) : (
          <ul className="flex flex-col divide-y">
            {tasks.map((task) => (
              <li
                key={task.id}
                className={cn('flex flex-wrap items-center justify-between gap-3 py-2.5 ps-2 text-sm', categoryFrame(task.category))}
              >
                <div className="flex min-w-0 items-center gap-2">
                  <PriorityBadge priority={task.priority} />
                  <EscalatedBadge task={task} />
                  <span className="truncate font-medium">{task.title}</span>
                  {task.category && <CategoryTag category={task.category} />}
                </div>
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                  <DueBadge task={task} />
                  <span>{task.assigned_to?.name ?? t('tasks.unassigned')}</span>
                  <TaskStatusBadge status={task.status} />
                  <span title={formatDateTime(task.created_at)}>{formatAgo(task.created_at)}</span>
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

function StatCard({ icon, label, value, hint }: { icon: ReactNode; label: string; value: string; hint?: ReactNode }) {
  return (
    <Card className="gap-1 py-4">
      <CardContent className="flex flex-col gap-1 px-4">
        <div className="flex items-center gap-1.5 text-xs text-muted-foreground [&_svg]:size-3.5">
          {icon}
          {label}
        </div>
        <div className="text-xl font-semibold tabular-nums">{value}</div>
        {hint && <div className="text-xs text-muted-foreground">{hint}</div>}
      </CardContent>
    </Card>
  )
}

function StatCards({ park }: { park: ParkDetail }) {
  const { t } = useTranslation()
  const l = park.latest
  const unit = (v: number | null | undefined, u: string, digits = 1) => (v == null ? '—' : `${formatNumber(v, digits)} ${u}`)
  const moistureHint =
    l?.moisture_pct == null ? undefined : l.moisture_pct < park.moisture_min ? t('status.dry') : l.moisture_pct > park.moisture_max ? t('status.over') : t('status.ok')

  const battery = l?.battery_pct
  return (
    <div className={cn('grid grid-cols-2 gap-3 sm:grid-cols-3', battery == null ? 'xl:grid-cols-6' : 'xl:grid-cols-7')}>
      <StatCard icon={<Droplets />} label={t('park.moisture')} value={unit(l?.moisture_pct, '%')} hint={moistureHint} />
      <StatCard icon={<Thermometer />} label={t('park.temperature')} value={unit(l?.temperature, '°C')} />
      <StatCard icon={<Gauge />} label={t('park.humidity')} value={unit(l?.humidity, '%')} />
      <StatCard icon={<Waves />} label={t('park.flow')} value={unit(l?.flow, 'L/min', 2)} />
      <StatCard
        icon={<Power />}
        label={t('park.pump')}
        value={!park.has_pump ? t('park.noPump') : l?.pump_on == null ? '—' : l.pump_on ? t('park.pumpOn') : t('park.pumpOff')}
      />
      {battery != null && <StatCard icon={<BatteryMedium />} label={t('park.battery')} value={unit(battery, '%', 0)} hint={t('park.simulated')} />}
      <StatCard
        icon={<Timer />}
        label={t('park.lastReading')}
        value={park.last_reading_at ? formatAgo(park.last_reading_at) : t('common.never')}
        hint={park.last_reading_at ? formatDateTime(park.last_reading_at) : undefined}
      />
    </div>
  )
}

function NodesCard({ park }: { park: ParkDetail }) {
  const { t } = useTranslation()

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('park.nodes')}</CardTitle>
      </CardHeader>
      <CardContent>
        <ul className="flex flex-col divide-y">
          {park.nodes.map((node) => (
            <li key={node.id} className="flex items-center justify-between gap-3 py-2.5 text-sm">
              <div className="flex min-w-0 items-center gap-2">
                <span
                  className={cn('size-2 shrink-0 rounded-full', node.online ? 'bg-status-ok' : 'bg-status-offline')}
                  title={node.online ? t('park.online') : t('park.offline')}
                />
                <span className="truncate font-medium">{node.name}</span>
                <Badge variant="outline">{t(`nodeType.${node.type}`)}</Badge>
                <SourceBadge source={node.source} />
              </div>
              <div className="flex flex-col items-end gap-0.5 text-end text-xs text-muted-foreground">
                <span title={formatDateTime(node.last_seen_at)}>
                  {t('park.lastSeen')}: {node.last_seen_at ? formatAgo(node.last_seen_at) : t('common.never')}
                </span>
                {node.battery_pct != null && (
                  <span className="inline-flex items-center gap-1 [&_svg]:size-3.5">
                    <BatteryMedium />
                    {formatNumber(node.battery_pct, 0)} % · {t('park.simulated')}
                  </span>
                )}
                {node.expected_flow_l_min != null && <span>{t('park.expectedFlow', { flow: formatNumber(node.expected_flow_l_min, 1) })}</span>}
              </div>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  )
}

function AlertsCard({ park, onCreate }: { park: ParkDetail; onCreate: (seed: TaskSeed) => void }) {
  const { t } = useTranslation()
  const demo = useDemoStatus()
  const alertsWithTask = new Set(park.open_tasks.map((task) => task.alert_id))
  const alerts = sortAlerts(park.open_alerts)

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('park.openAlerts')}</CardTitle>
      </CardHeader>
      <CardContent>
        {park.open_alerts.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('park.noAlerts')}</p>
        ) : (
          <ul className="flex flex-col divide-y">
            {alerts.map((alert) => (
              <li
                key={alert.id}
                className={cn('flex flex-wrap items-center justify-between gap-3 py-2.5 ps-2 text-sm', categoryFrame(alert.category))}
              >
                <div className="flex min-w-0 flex-col gap-0.5">
                  <div className="flex items-center gap-2">
                    <PriorityBadge priority={alert.priority} />
                    <span className="font-medium">{t(`alertType.${alert.type}`)}</span>
                    <CategoryTag category={alert.category} />
                  </div>
                  <span className="text-xs text-muted-foreground">{alertDetail(t, alert.type, alert.data)}</span>
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-xs text-muted-foreground" title={formatDateTime(alert.created_at)}>
                    {formatAgo(alert.created_at)}
                  </span>
                  {!alertsWithTask.has(alert.id) && !alert.task_ready && (
                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                      <Eye className="size-3.5" />
                      {t('alerts.watching', { n: demo.data ? 2 : 30 })}
                    </span>
                  )}
                  {!alertsWithTask.has(alert.id) && alert.task_ready && (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => onCreate({ park, alert: { id: alert.id, type: alert.type, priority: alert.priority } })}
                    >
                      <ClipboardPlus />
                      {t('alerts.createTask')}
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
