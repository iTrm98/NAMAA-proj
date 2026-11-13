import { ArrowLeft, BatteryMedium, CheckCircle2, ClipboardPlus, Droplets, Eye, Gauge, Leaf, Loader2, MapPinned, Power, ShieldAlert, Thermometer } from 'lucide-react'
import type { TFunction } from 'i18next'
import { AnimatePresence, motion } from 'motion/react'
import { lazy, Suspense, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { CameraCard } from '@/components/camera'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { CreateTaskDialog, type TaskSeed } from '@/components/CreateTaskDialog'
import { DataSources } from '@/components/DataSources'
import { Chip, type ChipTone, EmptyState, GlassCard, Kpi, Segmented, Skeleton, Toggle } from '@/components/ds'
import { NdviCard } from '@/components/NdviCard'
import { alertDetail, categoryFrame, DueBadge, EscalatedBadge, PriorityBadge, TaskStatusBadge, useCountdown } from '@/components/ops'
import { ReadingCharts } from '@/components/ReadingCharts'
import { SourceBadge, StatusPill } from '@/components/StatusPill'
import { Badge } from '@/components/ui/badge'
import { sortAlerts, sortTasks, useDemoStatus, useSwitchPump, useUpdatePark } from '@/hooks/useOps'
import { useDocumentTitle } from '@/hooks/useDocumentTitle'
import { parkName, usePark } from '@/hooks/useParks'
import { useParkTimeline } from '@/hooks/useTimeline'
import { ApiError } from '@/lib/api'
import { formatAgo, formatDateTime, formatNumber } from '@/lib/format'
import { personName } from '@/lib/names'
import { GeometryBadge, ParkAbout, VerifiedChip } from '@/components/ParkAbout'
import { useBasePath, useReadOnly } from '@/lib/scope'
import { cn } from '@/lib/utils'
import type { ParkDetail, Priority, ReadingRange, TimelineActor, TimelineStep } from '@/types'

// The outline editor (terra-draw, turf) loads only when an admin opens it.
const OutlineEditor = lazy(() => import('@/components/OutlineEditor').then((m) => ({ default: m.OutlineEditor })))

type Tab = 'live' | 'camera' | 'satellite' | 'history' | 'settings'
const PRIORITY_TONE: Record<Priority, ChipTone> = { urgent: 'urgent', high: 'high', normal: 'normal', low: 'muted' }
const BTN = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-full px-5 text-sm font-medium transition-colors disabled:opacity-50'
const BTN_PRIMARY = `${BTN} bg-[var(--accent-lime)] text-[var(--on-accent)] hover:brightness-95`
const BTN_GHOST = `${BTN} border border-[var(--glass-border-strong)] text-[var(--text)] hover:bg-white/10`

export default function ParkPage() {
  const { t, i18n } = useTranslation()
  const id = Number(useParams().id)
  const park = usePark(id)
  useDocumentTitle(park.data ? parkName(park.data, i18n.language) : null)
  const [params, setParams] = useSearchParams()
  const [seed, setSeed] = useState<TaskSeed | null>(null)
  const [editOutline, setEditOutline] = useState(false)
  const readOnly = useReadOnly()
  const base = useBasePath()

  if (park.isPending) {
    return (
      <div className="flex flex-col gap-5 p-1" aria-busy="true">
        <Skeleton h={20} w={120} />
        <Skeleton h={44} w={320} />
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} h={56} radius={18} />
          ))}
        </div>
        <Skeleton h={340} radius={26} />
      </div>
    )
  }

  if (park.isError) {
    const notFound = park.error instanceof ApiError && park.error.status === 404
    return (
      <EmptyState
        title={notFound ? t('park.notFound') : t('common.error')}
        action={
          <div className="flex gap-2">
            {!notFound && (
              <button type="button" className={BTN_GHOST} onClick={() => park.refetch()}>
                {t('common.retry')}
              </button>
            )}
            <Link to={base} className={BTN_GHOST}>
              {t('common.back')}
            </Link>
          </div>
        }
      />
    )
  }

  const p = park.data
  const requested = params.get('tab') as Tab | null
  const tabs: { value: Tab; label: string }[] = [
    { value: 'live', label: t('parkPage.tabLive') },
    ...(p.camera ? [{ value: 'camera' as const, label: t('parkPage.tabCamera') }] : []),
    { value: 'satellite', label: t('parkPage.tabSatellite') },
    { value: 'history', label: t('parkPage.tabHistory') },
    { value: 'settings', label: t('parkPage.tabSettings') },
  ]
  const tab: Tab = tabs.some((x) => x.value === requested) ? (requested as Tab) : 'live'
  const setTab = (v: Tab) => {
    const next = new URLSearchParams(params)
    if (v === 'live') next.delete('tab')
    else next.set('tab', v)
    setParams(next, { replace: true })
  }
  const firstAlert = sortAlerts(p.open_alerts)[0]
  const l = p.latest
  const ndvi = p.ndvi

  return (
    <div className="flex flex-col gap-5 p-1">
      <Link to={`${base}/parks`} className="inline-flex w-fit items-center gap-1.5 text-[13px] text-text-2 no-underline hover:text-[var(--text)]">
        <ArrowLeft className="size-4 rtl:-scale-x-100" aria-hidden />
        {t('parkPage.back')}
      </Link>

      <section className="flex flex-wrap items-center gap-x-7 gap-y-4">
        <div className="flex min-w-0 flex-col gap-2">
          <h1 className="m-0 text-[40px] leading-[1.05] font-light tracking-[-.01em]">{parkName(p, i18n.language)}</h1>
          <div className="flex flex-wrap items-center gap-1.5">
            <StatusPill status={p.status} />
            <SourceBadge source={p.source} />
            <GeometryBadge source={p.geometry_source} />
            <VerifiedChip status={p.verification_status} />
            <span className="text-xs text-text-2">
              {[p.district, t(`parkType.${p.park_type}`), p.area_m2 ? t('park.area', { area: formatNumber(p.area_m2, 0) }) : null].filter(Boolean).join(' · ')}
            </span>
            {readOnly && p.company && <Badge variant="outline">{p.company.name}</Badge>}
          </div>
          {p.ndvi_recomputing && (
            <p className="m-0 inline-flex items-center gap-1.5 text-xs text-[var(--info)]">
              <Loader2 className="size-3.5 animate-spin" />
              {t('outline.recomputing')}
            </p>
          )}
        </div>
        {l?.moisture_pct != null && (
          <Kpi
            icon={<Droplets />}
            value={l.moisture_pct}
            digits={1}
            suffix="%"
            label={t('park.moisture')}
            badge={{ tone: l.moisture_pct < p.moisture_min ? 'high' : 'muted', children: t('parkPage.minChip', { min: p.moisture_min }) }}
          />
        )}
        {l?.temperature != null && <Kpi icon={<Thermometer />} value={l.temperature} digits={1} suffix="°" label={t('park.temperature')} />}
        {l?.humidity != null && <Kpi icon={<Gauge />} value={l.humidity} digits={0} suffix="%" label={t('park.humidity')} />}
        {ndvi && !ndvi.too_small && ndvi.mean != null && <Kpi icon={<Leaf />} value={ndvi.mean} digits={2} label={t('parkPage.greenCover')} />}
        {!readOnly && (
          <button type="button" className={cn(BTN_PRIMARY, 'ms-auto')} onClick={() => setSeed({ park: p })}>
            <ClipboardPlus className="size-4" aria-hidden />
            {t('parkPage.sendWorker')}
          </button>
        )}
      </section>

      <Segmented options={tabs} value={tab} onChange={setTab} ariaLabel={t('parkPage.tabsLabel')} className="w-fit max-w-full overflow-x-auto" />

      {tab === 'live' && <LiveTab park={p} firstAlert={firstAlert} onCreate={readOnly ? undefined : setSeed} />}
      {tab === 'camera' && p.camera && (
        <div className="grid items-start gap-5 lg:grid-cols-[2fr_1fr]">
          <CameraCard parkId={p.id} />
          <DetectorCard />
        </div>
      )}
      {tab === 'satellite' && <NdviCard parkId={p.id} />}
      {tab === 'history' && <HistoryTab parkId={p.id} />}
      {tab === 'settings' && <SettingsTab park={p} readOnly={readOnly} onEditOutline={() => setEditOutline(true)} />}

      {!readOnly && <CreateTaskDialog seed={seed} onOpenChange={(open) => !open && setSeed(null)} />}
      {!readOnly && editOutline && (
        <Suspense fallback={null}>
          <OutlineEditor park={p} open={editOutline} onOpenChange={setEditOutline} />
        </Suspense>
      )}
    </div>
  )
}

// ---- Live ----

function LiveTab({ park, firstAlert, onCreate }: { park: ParkDetail; firstAlert: ParkDetail['open_alerts'][number] | undefined; onCreate?: (seed: TaskSeed) => void }) {
  const { t, i18n } = useTranslation()
  const demo = useDemoStatus()
  const [picked, setPicked] = useState<ReadingRange | null>(null)
  // Demo mode opens on the last hour, so the live story is visible at once.
  const range: ReadingRange = picked ?? (demo.data ? '1h' : '24h')
  const alerts = sortAlerts(park.open_alerts)
  const tasks = sortTasks(park.open_tasks)

  return (
    <div className="flex flex-col gap-5">
      <section className="flex flex-wrap gap-5">
        <GlassCard className="flex min-w-0 flex-[2_1_560px] flex-col gap-3">
          <div className="flex flex-wrap items-center justify-between gap-2.5">
            <div>
              <h2 className="m-0 text-[17px] font-medium">{t('parkPage.moistureTitle')}</h2>
              <span className="text-xs text-text-2">{t('park.thresholds', { min: park.moisture_min, max: park.moisture_max })} · {t('park.liveNote')}</span>
            </div>
            <Segmented
              options={[
                { value: '1h', label: t('parkPage.r1h') },
                { value: '24h', label: t('parkPage.r24h') },
                { value: '7d', label: t('parkPage.r7d') },
              ]}
              value={range}
              onChange={(v) => setPicked(v as ReadingRange)}
              ariaLabel={t('parkPage.rangeLabel')}
            />
          </div>
          <ReadingCharts park={park} range={range} />
        </GlassCard>

        <div className="flex min-w-0 flex-[1_1_340px] flex-col gap-5">
          {park.has_pump && <PumpCard park={park} />}
          {firstAlert ? (
            <AnimatePresence initial={false}>
              {alerts.map((alert) => (
                <motion.div
                  key={alert.id}
                  layout
                  initial={{ opacity: 0, y: -8 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, scale: 0.98 }}
                  transition={{ duration: 0.25, ease: 'easeOut' }}
                >
                  <AlertCard park={park} alert={alert} onCreate={onCreate} />
                </motion.div>
              ))}
            </AnimatePresence>
          ) : (
            <GlassCard className="flex items-center gap-3 text-sm text-text-2">
              <CheckCircle2 className="size-5 text-[var(--healthy)]" aria-hidden />
              {t('park.noAlerts')}
            </GlassCard>
          )}
        </div>
      </section>

      {tasks.length > 0 && (
        <GlassCard>
          <div className="mb-2 flex items-center justify-between">
            <h2 className="m-0 text-[17px] font-medium">{t('park.openTasks')}</h2>
          </div>
          <ul className="m-0 flex list-none flex-col divide-y divide-[var(--glass-border)] p-0">
            {tasks.map((task) => (
              <li key={task.id} className={cn('flex flex-wrap items-center justify-between gap-3 py-2.5 ps-2 text-sm', categoryFrame(task.category))}>
                <div className="flex min-w-0 items-center gap-2">
                  <PriorityBadge priority={task.priority} />
                  <EscalatedBadge task={task} />
                  <TaskTitle task={task} />
                </div>
                <div className="flex items-center gap-2 text-xs text-text-2">
                  <DueBadge task={task} />
                  <span>{task.assigned_to ? personName(task.assigned_to, i18n.language) : t('tasks.unassigned')}</span>
                  <TaskStatusBadge status={task.status} />
                  <span title={formatDateTime(task.created_at)}>{formatAgo(task.created_at)}</span>
                </div>
              </li>
            ))}
          </ul>
        </GlassCard>
      )}
    </div>
  )
}

function TaskTitle({ task }: { task: ParkDetail['open_tasks'][number] }) {
  const { t, i18n } = useTranslation()
  return <span className="truncate font-medium">{task.title_key ? t(task.title_key, { park: i18n.language === 'ar' ? (task.title_park?.name_ar ?? '') : (task.title_park?.name_en ?? '') }) : task.title}</span>
}

/** Pump: state, last change and reason, safety limit, automatic irrigation, On/Off with confirmation. */
function PumpCard({ park }: { park: ParkDetail }) {
  const { t } = useTranslation()
  const pump = park.pump
  const demo = useDemoStatus()
  const switchPump = useSwitchPump(park.id)
  const readOnly = useReadOnly()
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
    <GlassCard className="flex flex-col gap-3">
      <div className="flex items-center justify-between gap-2">
        <h2 className="m-0 flex items-center gap-2 text-[17px] font-medium">
          <Power className="size-4 text-text-2" aria-hidden />
          {t('pump.title')}
        </h2>
        <Chip tone={target ? 'ok' : 'muted'}>{target ? t('pump.on') : t('pump.off')}</Chip>
      </div>
      <dl className="m-0 flex flex-col text-[13px]">
        {pump?.changed_at && (
          <div className="flex items-start justify-between gap-3 border-b border-[var(--glass-border)] py-2">
            <dt className="text-text-2">{t('pump.lastChange')}</dt>
            <dd className="m-0 text-end" title={formatDateTime(pump.changed_at)}>
              {formatAgo(pump.changed_at)} · {who}
              {pump.reason && ` · ${t(`pumpReason.${pump.reason}`)}`}
            </dd>
          </div>
        )}
        {pump && (
          <div className="flex items-start justify-between gap-3 border-b border-[var(--glass-border)] py-2">
            <dt className="text-text-2">{t('parkPage.safetyLimit')}</dt>
            <dd className="m-0 text-end">{t('pump.maxRun', { n: Math.round(pump.max_run_seconds / 60) })}</dd>
          </div>
        )}
        {pump?.reported_on != null && (
          <div className="flex items-start justify-between gap-3 py-2">
            <dt className="text-text-2">{t('pump.reported')}</dt>
            <dd className="m-0 text-end">{pump.reported_on ? t('pump.on') : t('pump.off')}</dd>
          </div>
        )}
      </dl>
      {target && countdown && (
        <p className="m-0 flex items-center gap-1.5 text-xs text-[var(--clean)]">
          <ShieldAlert className="size-3.5" aria-hidden />
          {t('pump.cutoffIn', { time: countdown })}
        </p>
      )}
      <div className="flex items-center justify-between gap-3">
        <span>
          <span className="block text-[13px]">{t('pump.autoTitle')}</span>
          <span className="block text-xs text-text-2">{t('pump.autoHelp', { mid: Math.round((park.moisture_min + park.moisture_max) / 2) })}</span>
        </span>
        <Toggle checked={park.auto_irrigation} onChange={setAuto} label={t('pump.autoTitle')} disabled={readOnly || updatePark.isPending} />
      </div>
      {park.auto_irrigation && target && pump?.reason === 'rule' && (
        <p className="m-0 flex items-center gap-1.5 text-xs text-[var(--water)]">
          <Droplets className="size-3.5" aria-hidden />
          {t('pump.autoRunning')}
        </p>
      )}
      {!readOnly && (
        <ConfirmDialog
          trigger={
            <button type="button" className={target ? BTN_GHOST : BTN_PRIMARY} disabled={switchPump.isPending}>
              {target ? t('pump.turnOff') : t('pump.turnOn')}
            </button>
          }
          title={target ? t('pump.confirmOffTitle') : t('pump.confirmOnTitle')}
          body={t('pump.confirmBody', { n: demo.data ? 5 : 30 })}
          onConfirm={() => setPump(!target)}
        />
      )}
    </GlassCard>
  )
}

function AlertCard({ park, alert, onCreate }: { park: ParkDetail; alert: ParkDetail['open_alerts'][number]; onCreate?: (seed: TaskSeed) => void }) {
  const { t } = useTranslation()
  const demo = useDemoStatus()
  const hasTask = park.open_tasks.some((task) => task.alert_id === alert.id)
  return (
    <GlassCard className={cn('flex flex-col gap-2.5', alert.priority === 'urgent' ? 'border-[rgba(255,107,94,.4)]' : 'border-[rgba(255,154,98,.35)]')}>
      <div className="flex flex-wrap gap-1.5">
        <Chip tone={PRIORITY_TONE[alert.priority]}>{t(`priority.${alert.priority}`)}</Chip>
        <Chip tone="muted">{t(`category.${alert.category}`)}</Chip>
      </div>
      <h3 className="m-0 text-base font-medium">{t(`alertType.${alert.type}`)}</h3>
      <span className="text-xs text-text-2">
        {t('parkPage.alertRaised', { ago: formatAgo(alert.created_at) })}
        {alertDetail(t, alert.type, alert.data) && ` · ${alertDetail(t, alert.type, alert.data)}`}
      </span>
      {onCreate && !hasTask && alert.task_ready && (
        <button type="button" className={BTN_GHOST} onClick={() => onCreate({ park, alert: { id: alert.id, type: alert.type, priority: alert.priority } })}>
          <ClipboardPlus className="size-4" aria-hidden />
          {t('alerts.createTask')}
        </button>
      )}
      {!hasTask && !alert.task_ready && (
        <span className="flex items-center gap-1 text-xs text-text-2">
          <Eye className="size-3.5" aria-hidden />
          {t('alerts.watching', { n: demo.data ? 2 : 30 })}
        </span>
      )}
    </GlassCard>
  )
}

// ---- Camera ----

/** Measured scores of the litter model, copied from ml/MODEL_CARD.md (public test set, IoU 0.5). */
const DETECTOR = { precision: 0.627, recall: 0.412, map50: 0.471, threshold: 0.1775, cpuMs: 77 }

function DetectorCard() {
  const { t } = useTranslation()
  const rows: [string, string][] = [
    [t('parkPage.precision'), formatNumber(DETECTOR.precision * 100, 0) + '%'],
    [t('parkPage.recall'), formatNumber(DETECTOR.recall * 100, 0) + '%'],
    [t('parkPage.map50'), formatNumber(DETECTOR.map50, 2)],
    [t('parkPage.threshold'), String(DETECTOR.threshold)],
    [t('parkPage.speed'), t('parkPage.ms', { n: DETECTOR.cpuMs })],
  ]
  return (
    <GlassCard className="flex flex-col gap-3">
      <div>
        <h2 className="m-0 text-[17px] font-medium">{t('parkPage.detector')}</h2>
        <span className="text-xs text-text-2" dir="ltr">
          {t('parkPage.detectorModel')}
        </span>
      </div>
      <dl className="m-0 flex flex-col text-[13px]">
        {rows.map(([k, v]) => (
          <div key={k} className="flex items-center justify-between gap-3 border-b border-[var(--glass-border)] py-2 last:border-0">
            <dt className="text-text-2">{k}</dt>
            <dd className="m-0 tabular-nums">{v}</dd>
          </div>
        ))}
      </dl>
      <p className="m-0 text-xs text-text-2">{t('parkPage.measuredNote')}</p>
    </GlassCard>
  )
}

// ---- History ----

function actorName(a: TimelineActor | null, language: string, fallback: string) {
  return a ? personName(a, language) : fallback
}

function stepLabel(t: TFunction, step: TimelineStep, language: string) {
  const d = step.data ?? {}
  if (step.key === 'task_assigned') {
    const to = d.assigned_to as TimelineActor | undefined
    return t('timeline.task_assigned', { name: to ? personName(to, language) : '' })
  }
  if (step.key === 'verified') return t('timeline.verified', { minutes: d.minutes ?? '' })
  return t(`timeline.${step.key}`)
}

function HistoryTab({ parkId }: { parkId: number }) {
  const { t, i18n } = useTranslation()
  const timeline = useParkTimeline(parkId)

  if (timeline.isPending) return <Skeleton h={320} radius={26} />
  if (timeline.isError)
    return (
      <EmptyState
        title={t('common.error')}
        action={
          <button type="button" className={BTN_GHOST} onClick={() => timeline.refetch()}>
            {t('common.retry')}
          </button>
        }
      />
    )
  const data = timeline.data
  if (!data) return <GlassCard><EmptyState icon={<CheckCircle2 />} title={t('parkPage.historyEmpty')} /></GlassCard>

  return (
    <GlassCard className="flex max-w-3xl flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="m-0 text-[17px] font-medium">{t('parkPage.historyTitle')}</h2>
          <span className="text-xs text-text-2">{t(`alertType.${data.alert.type}`)} · {t('parkPage.historySub')}</span>
        </div>
        {data.duration_minutes != null && (
          <div className="flex flex-col items-end text-end">
            <b className="text-[26px] font-medium tabular-nums text-[var(--accent-lime)]">{t('common.minutes', { n: data.duration_minutes })}</b>
            <span className="text-xs text-text-2">{t('parkPage.duration')}</span>
          </div>
        )}
      </div>
      <ol className="m-0 flex list-none flex-col p-0">
        {data.steps.map((step, i) => {
          const last = i === data.steps.length - 1
          const good = step.key === 'verified'
          const bad = step.key === 'not_verified' || step.key === 'task_cancelled'
          return (
            <li key={`${step.key}-${step.at}-${i}`} className="relative flex gap-3 pb-4 last:pb-0">
              {!last && <span className="absolute inset-y-3.5 start-[5px] w-px bg-[var(--glass-border-strong)]" aria-hidden />}
              <span
                className={cn('relative mt-1.5 size-[11px] shrink-0 rounded-full border-2', good ? 'border-[var(--healthy)] bg-[var(--healthy)]' : bad ? 'border-[var(--urgent)]' : 'border-[var(--text-muted)] bg-[var(--bg)]')}
                aria-hidden
              />
              <div className="min-w-0">
                <div className={cn('text-sm', good ? 'font-medium text-[var(--healthy)]' : 'text-[var(--text)]')}>{stepLabel(t, step, i18n.language)}</div>
                <div className="text-xs text-text-2" title={formatDateTime(step.at)}>
                  {formatDateTime(step.at)}
                  {step.key !== 'verified' && step.key !== 'alert_raised' && ` · ${t('parkPage.by', { name: actorName(step.actor, i18n.language, t('parkPage.system')) })}`}
                </div>
                {step.key === 'task_done' && typeof step.data?.note === 'string' && step.data.note && <div className="mt-0.5 text-xs text-text-2">{step.data.note}</div>}
              </div>
            </li>
          )
        })}
      </ol>
    </GlassCard>
  )
}

// ---- Settings ----

function SettingsTab({ park, readOnly, onEditOutline }: { park: ParkDetail; readOnly: boolean; onEditOutline: () => void }) {
  const { t } = useTranslation()
  return (
    <div className="grid items-start gap-5 lg:grid-cols-2">
      <ParkAbout park={park} readOnly={readOnly} />

      <GlassCard className="flex flex-col gap-3">
        <h2 className="m-0 text-[17px] font-medium">{t('parkPage.settingsRange')}</h2>
        <p className="m-0 text-2xl font-medium tabular-nums">{t('parkPage.rangeValue', { min: park.moisture_min, max: park.moisture_max })}</p>
        <span className="text-xs text-text-2">{t('pump.autoHelp', { mid: Math.round((park.moisture_min + park.moisture_max) / 2) })}</span>
      </GlassCard>

      <GlassCard className="flex flex-col gap-3">
        <h2 className="m-0 text-[17px] font-medium">{t('parkPage.settingsOutline')}</h2>
        {park.sources && <DataSources sources={park.sources} />}
        {readOnly ? (
          <span className="text-xs text-text-2">{t('parkPage.settingsReadOnly')}</span>
        ) : (
          <button type="button" className={cn(BTN_GHOST, 'w-fit')} onClick={onEditOutline}>
            <MapPinned className="size-4" aria-hidden />
            {t('outline.edit')}
          </button>
        )}
      </GlassCard>

      <GlassCard className="flex flex-col gap-1 lg:col-span-2">
        <h2 className="m-0 mb-1 text-[17px] font-medium">{t('park.nodes')}</h2>
        <ul className="m-0 flex list-none flex-col divide-y divide-[var(--glass-border)] p-0">
          {park.nodes.map((node) => (
            <li key={node.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5 text-sm">
              <div className="flex min-w-0 items-center gap-2">
                <span
                  className={cn('size-2.5 shrink-0 rounded-full', node.online && node.status !== 'awaiting_install' ? 'bg-[var(--healthy)]' : 'border-[1.5px] border-[var(--awaiting)]')}
                  title={node.status === 'awaiting_install' ? t('status.awaiting_install') : node.online ? t('park.online') : t('park.offline')}
                />
                <span className="truncate font-medium">{node.name}</span>
                <Chip tone="muted">{t(`nodeType.${node.type}`)}</Chip>
                <Chip tone={node.source === 'simulated' ? 'high' : 'lime'}>{t(`source.${node.source}`)}</Chip>
              </div>
              <div className="flex flex-col items-end gap-0.5 text-end text-xs text-text-2">
                <span title={formatDateTime(node.last_seen_at)}>
                  {node.status === 'awaiting_install' ? t('status.awaiting_install') : `${t('park.lastSeen')}: ${node.last_seen_at ? formatAgo(node.last_seen_at) : t('common.never')}`}
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
      </GlassCard>
    </div>
  )
}
