import { AnimatePresence, motion } from 'motion/react'
import { Activity, BellRing, CircleCheck, Clock, MapPinned, Plus } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Area, AreaChart, CartesianGrid, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Link } from 'react-router'
import { CreateTaskDialog, type TaskSeed } from '@/components/CreateTaskDialog'
import { Chip, EmptyState, GlassCard, Kpi, MiniCard, Segmented, Skeleton, useDir } from '@/components/ds'
import type { ChipTone } from '@/components/ds'
import { OverviewMap } from '@/components/OverviewMap'
import { CREATE_TASK_EVENT } from '@/components/shell/QuickActions'
import { useOverview } from '@/hooks/useOverview'
import { parkName, useParkReadings, useParks } from '@/hooks/useParks'
import { formatAgo, formatNumber } from '@/lib/format'
import { isAutoWatering } from '@/lib/irrigation'
import { personName, taskTitle } from '@/lib/names'
import { useReadOnly } from '@/lib/scope'
import type { AlertCategory, Overview, Park, Priority, ReadingRange } from '@/types'

const PRIORITY_TONE: Record<Priority, ChipTone> = { urgent: 'urgent', high: 'high', normal: 'normal', low: 'muted' }
const CATEGORY_COLOR: Record<AlertCategory, string> = { plant: 'var(--healthy)', water: 'var(--info)', cleanliness: 'var(--clean)', devices: 'var(--awaiting)' }

/** /admin: the landing page. KPIs, "right now", the live map, what needs attention, moisture of the most urgent park, alerts by category. */
export default function OverviewPage() {
  const { t } = useTranslation()
  const readOnly = useReadOnly()
  const overview = useOverview()
  const parks = useParks()
  const [seed, setSeed] = useState<TaskSeed | null>(null)
  const [selectedId, setSelectedId] = useState<number | null>(null)

  if (overview.isPending || parks.isPending) return <OverviewSkeleton />
  if (overview.isError || parks.isError)
    return (
      <EmptyState
        title={t('common.error')}
        action={
          <button
            type="button"
            className="h-10 rounded-full border border-[var(--glass-border-strong)] px-5 text-sm"
            onClick={() => {
              void overview.refetch()
              void parks.refetch()
            }}
          >
            {t('common.retry')}
          </button>
        }
      />
    )

  const o = overview.data
  const priorities = (['urgent', 'high', 'normal', 'low'] as const).filter((p) => o.kpis.open_alerts.by_priority[p] > 0)
  const topPriority = priorities[0]

  return (
    <div className="flex min-w-0 flex-col gap-5 pb-8">
      <section className="flex flex-wrap items-center gap-x-7 gap-y-4">
        <h1 className="m-0 text-[40px] leading-[1.05] font-light tracking-[-.01em]">{t('overview.title')}</h1>
        <Kpi
          icon={<MapPinned />}
          value={o.kpis.parks.total}
          label={t('overview.kpiParks')}
          badge={o.kpis.parks.real > 0 ? { tone: 'lime', children: t('overview.nReal', { count: o.kpis.parks.real }) } : undefined}
        />
        <Kpi
          icon={<BellRing />}
          value={o.kpis.open_alerts.total}
          label={t('overview.kpiAlerts')}
          badge={topPriority ? { tone: PRIORITY_TONE[topPriority], children: `${o.kpis.open_alerts.by_priority[topPriority]} ${t(`priority.${topPriority}`)}` } : undefined}
        />
        {o.kpis.fixes.verified_pct != null ? (
          <Kpi
            icon={<CircleCheck />}
            value={o.kpis.fixes.verified_pct}
            suffix="%"
            label={t('overview.kpiVerified')}
            badge={{ tone: 'muted', children: t('overview.ofDecided', { done: o.kpis.fixes.verified, total: o.kpis.fixes.verified + o.kpis.fixes.not_verified }) }}
          />
        ) : (
          <Kpi icon={<CircleCheck />} value={t('overview.notEnough')} label={t('overview.kpiVerified')} />
        )}
        {o.kpis.median_fix_minutes != null ? (
          <Kpi icon={<Clock />} value={o.kpis.median_fix_minutes} suffix={` ${t('overview.minShort')}`} label={t('overview.kpiMedian')} />
        ) : (
          <Kpi icon={<Clock />} value={t('overview.notEnough')} label={t('overview.kpiMedian')} />
        )}
        <div className="flex-1" />
        {!readOnly && (
          <button
            type="button"
            onClick={() => window.dispatchEvent(new Event(CREATE_TASK_EVENT))}
            className="flex h-11 items-center gap-2 rounded-full bg-[var(--accent-lime)] px-5 text-sm font-medium text-[var(--on-accent)]"
          >
            <Plus className="size-4" />
            {t('overview.createTask')}
          </button>
        )}
      </section>

      <RightNow right={o.right_now} />
      {o.demo_stand && <DemoStandCard stand={o.demo_stand} />}

      <section className="flex flex-wrap items-stretch gap-5 min-[1280px]:h-[clamp(560px,66vh,760px)]">
        <OverviewMap parks={parks.data} selectedId={selectedId} onSelect={setSelectedId} className="h-[460px] min-[1280px]:h-full" />
        <NeedsAttention overview={o} parks={parks.data} readOnly={readOnly} onSend={setSeed} />
      </section>

      <section className="flex flex-wrap gap-5">
        <MoistureCard overview={o} parks={parks.data} selectedId={selectedId} />
        <CategoriesCard />
      </section>

      <footer className="text-xs text-text-2">{t('overview.footer')}</footer>
      {!readOnly && <CreateTaskDialog seed={seed} onOpenChange={(open) => !open && setSeed(null)} />}
    </div>
  )
}

/** The demo stand is a live bench, never a park: a small card with its name, online state and latest reading. */
function DemoStandCard({ stand }: { stand: NonNullable<Overview['demo_stand']> }) {
  const { t, i18n } = useTranslation()
  const latest = stand.latest
  const values = latest
    ? [
        latest.moisture_pct != null ? `${t('overview.stand.moisture')} ${formatNumber(latest.moisture_pct, 0)}%` : null,
        latest.temperature_c != null ? `${t('overview.stand.temperature')} ${formatNumber(latest.temperature_c, 1)}°` : null,
        latest.humidity_pct != null ? `${t('overview.stand.humidity')} ${formatNumber(latest.humidity_pct, 0)}%` : null,
      ].filter(Boolean)
    : []
  return (
    <GlassCard padding={14} className="flex flex-wrap items-center gap-x-4 gap-y-2" aria-label={t('overview.stand.title')}>
      <span className={`size-2.5 shrink-0 rounded-full ${stand.online ? 'bg-[var(--healthy)]' : 'border-[1.5px] border-[var(--awaiting)]'}`} aria-hidden />
      <div className="flex min-w-0 flex-col">
        <span className="text-[15px] font-medium">{t('overview.stand.title')}</span>
        <span className="text-xs text-text-2">{i18n.language === 'ar' ? stand.name_ar : stand.name_en}</span>
      </div>
      <Chip tone={stand.online ? 'ok' : 'muted'}>{t(stand.online ? 'overview.stand.online' : 'overview.stand.offline')}</Chip>
      <span className="text-sm tabular-nums text-text-2">
        {latest && values.length ? `${t('overview.stand.latest')}: ${values.join(' · ')} · ${formatAgo(latest.at)}` : t('overview.stand.none')}
      </span>
    </GlassCard>
  )
}

function RightNow({ right }: { right: Overview['right_now'] }) {
  const { t } = useTranslation()
  const items: { key: string; text: string; color?: string; ring?: boolean }[] = [
    right.parks_needing_water > 0
      ? { key: 'water', text: t('overview.needWater', { count: right.parks_needing_water }), color: 'var(--water)' }
      : { key: 'water', text: t('overview.noWaterNeeded'), color: 'var(--healthy)' },
    right.open_leaks > 0 ? { key: 'leak', text: t('overview.leaks', { count: right.open_leaks }), color: 'var(--urgent)' } : { key: 'leak', text: t('overview.noLeaks'), color: 'var(--healthy)' },
    right.open_litter > 0 ? { key: 'litter', text: t('overview.litter', { count: right.open_litter }), color: 'var(--clean)' } : { key: 'litter', text: t('overview.noLitter'), color: 'var(--healthy)' },
  ]
  if (right.sensors_awaiting_installation > 0) items.push({ key: 'await', text: t('overview.awaitingSensors', { count: right.sensors_awaiting_installation }), ring: true })

  return (
    <section aria-label={t('overview.rightNow')} className="flex flex-wrap gap-2.5">
      {items.map((i) => (
        <span key={i.key} className="flex items-center gap-2 rounded-full border border-[var(--glass-border)] bg-[var(--glass-bg)] px-3.5 py-2 text-[13px]">
          <span
            className="size-2 rounded-full border-[1.5px] border-transparent"
            style={i.ring ? { borderColor: 'var(--awaiting)' } : { background: i.color }}
            aria-hidden
          />
          {i.text}
        </span>
      ))}
    </section>
  )
}

function NeedsAttention({ overview, parks, readOnly, onSend }: { overview: Overview; parks: Park[]; readOnly: boolean; onSend: (seed: TaskSeed) => void }) {
  const { t, i18n } = useTranslation()
  const dir = useDir()
  const { alerts, tasks_in_progress: tasks, setup } = overview.needs_attention
  const counts = overview.needs_attention.counts ?? { alerts: overview.kpis.open_alerts.total, tasks_in_progress: tasks.length, setup: setup.length }
  const total = counts.alerts + counts.tasks_in_progress + counts.setup
  const empty = alerts.length + tasks.length + setup.length === 0
  const breakdown = [
    counts.alerts > 0 ? t('overview.countAlerts', { count: counts.alerts }) : null,
    counts.tasks_in_progress > 0 ? t('overview.countTasks', { count: counts.tasks_in_progress }) : null,
    counts.setup > 0 ? t('overview.countSetup', { count: counts.setup }) : null,
  ]
    .filter(Boolean)
    .join(' · ')
  const enter = { opacity: 0, x: 24 * dir }

  return (
    <GlassCard className="flex max-h-[560px] min-h-0 min-w-0 flex-[2_1_340px] flex-col gap-3.5 min-[1280px]:h-full min-[1280px]:max-h-none" aria-labelledby="needs-title" as="section">
      <div className="flex flex-col gap-0.5">
        <div className="flex items-center gap-2.5">
          <h2 id="needs-title" className="m-0 text-[17px] font-medium">
            {t('overview.needsAttention')}
          </h2>
          {total > 0 && <span className="rounded-full bg-white/10 px-2 py-0.5 text-[11px] tabular-nums">{formatNumber(total, 0)}</span>}
        </div>
        {breakdown && <span className="text-xs text-text-2">{breakdown}</span>}
      </div>
      <div className="flex min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto">
        {empty && <EmptyState icon={<CircleCheck />} title={t('overview.allGood')} hint={t('overview.allGoodHint')} />}
        <AnimatePresence initial={false} mode="popLayout">
          {alerts.map((a) => {
            const name = parkName(a.park, i18n.language)
            return (
              <motion.div key={`a${a.id}`} layout initial={enter} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0 }} transition={{ duration: 0.25 }}>
                <MiniCard
                  variant={a.priority === 'urgent' ? 'urgent' : 'default'}
                  chips={[{ tone: PRIORITY_TONE[a.priority], children: t(`priority.${a.priority}`) }, { tone: 'muted', children: t(`category.${a.category}`) }]}
                  title={`${t(`alertType.${a.type}`)} · ${name}`}
                  meta={`${t(`source.${a.park.source}`)} · ${formatAgo(a.created_at)}`}
                  action={
                    a.task ? (
                      <span className="text-xs text-text-2">
                        {t(`taskStatus.${a.task.status}`)}
                        {a.task.assigned_to ? ` · ${personName(a.task.assigned_to, i18n.language)}` : ''}
                      </span>
                    ) : (a.type === 'dry' || a.type === 'heat_stress') && parks.some((p) => p.id === a.park.id && isAutoWatering(p)) ? (
                      <span className="text-xs text-[var(--water)]">{t('overview.wateringAuto')}</span>
                    ) : !readOnly && a.task_ready ? (
                      <button
                        type="button"
                        className="h-9 rounded-full border border-[var(--glass-border-strong)] px-4 text-[13px] hover:bg-white/10"
                        onClick={() => onSend({ park: a.park, alert: { id: a.id, type: a.type, priority: a.priority } })}
                      >
                        {t('overview.sendWorker')}
                      </button>
                    ) : (
                      <span className="text-xs text-text-2">{t('overview.watching')}</span>
                    )
                  }
                />
              </motion.div>
            )
          })}
          {tasks.map((task) => (
            <motion.div key={`t${task.id}`} layout initial={enter} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0 }} transition={{ duration: 0.25 }}>
              <MiniCard
                chips={[{ tone: 'normal', children: t('taskStatus.in_progress') }, { tone: PRIORITY_TONE[task.priority], children: t(`priority.${task.priority}`) }]}
                title={taskTitle(t, i18n.language, { ...task, park: task.park })}
                meta={[task.assigned_to ? personName(task.assigned_to, i18n.language) : t('overview.unassigned'), task.watering ? t('overview.wateringMeta', { from: formatNumber(task.watering.current_pct), to: formatNumber(task.watering.target_pct) }) : null]
                  .filter(Boolean)
                  .join(' · ')}
                figure={task.watering ? `${formatNumber(task.watering.progress_pct, 0)}%` : undefined}
                progress={task.watering?.progress_pct}
                action={
                  <Link to={`/admin/tasks`} className="text-[13px] text-[var(--accent-lime)] no-underline hover:underline">
                    {t('overview.openTasks')}
                  </Link>
                }
              />
            </motion.div>
          ))}
          {setup.map((s) => (
            <motion.div key={`s${s.type}${s.node?.id ?? s.park.id}`} layout initial={enter} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0 }} transition={{ duration: 0.25 }}>
              <MiniCard
                variant="setup"
                chips={[{ tone: 'muted', children: t('overview.setup') }]}
                title={s.type === 'awaiting_installation' ? t('overview.setupAwaiting', { node: s.node?.name ?? '' }) : t('overview.setupOutline')}
                meta={parkName(s.park, i18n.language)}
                action={
                  <Link
                    to={s.type === 'awaiting_installation' ? '/admin/hardware' : `/admin/parks/${s.park.id}`}
                    className="text-[13px] text-[var(--accent-lime)] no-underline hover:underline"
                  >
                    {s.type === 'awaiting_installation' ? t('nav.hardware') : t('overview.openPark')}
                  </Link>
                }
              />
            </motion.div>
          ))}
        </AnimatePresence>
      </div>
    </GlassCard>
  )
}

const RANGES: ReadingRange[] = ['1h', '24h', '7d']
const MINUTE = 60_000

/** One tick per day (7D), every 4 hours (24H) or every 10 minutes (1H), on round local times. */
function axisTicks(range: ReadingRange, min: number, max: number): number[] {
  const out: number[] = []
  if (range === '7d') {
    const d = new Date(min)
    d.setHours(24, 0, 0, 0)
    for (let ts = d.getTime(); ts <= max; ) {
      out.push(ts)
      const next = new Date(ts)
      next.setDate(next.getDate() + 1)
      ts = next.getTime()
    }
    return out
  }
  const step = range === '24h' ? 4 * 60 * MINUTE : 10 * MINUTE
  const offset = new Date(min).getTimezoneOffset() * MINUTE
  for (let ts = Math.ceil((min - offset) / step) * step + offset; ts <= max; ts += step) out.push(ts)
  return out
}

/** Readings with a null point wherever data is missing, so the line breaks instead of bridging a gap. */
function withGaps(rows: { ts: number; v: number | null }[]): { ts: number; v: number | null }[] {
  if (rows.length < 3) return rows
  const deltas = rows.slice(1).map((r, i) => r.ts - rows[i].ts).sort((a, b) => a - b)
  const limit = Math.max(deltas[Math.floor(deltas.length / 2)] * 2.5, MINUTE)
  const out: { ts: number; v: number | null }[] = []
  rows.forEach((r, i) => {
    if (i > 0 && r.ts - rows[i - 1].ts > limit) out.push({ ts: rows[i - 1].ts + limit / 2, v: null })
    out.push(r)
  })
  return out
}

/** Soil moisture of the park picked on the map or in the list; with none, the park with the most urgent alert. Minimum and watering-target lines. */
function MoistureCard({ overview, parks, selectedId }: { overview: Overview; parks: Park[]; selectedId: number | null }) {
  const { t, i18n } = useTranslation()
  const [range, setRange] = useState<ReadingRange>('24h')
  const [picked, setPicked] = useState<number | null>(null)

  // A new pick on the map wins over the list.
  useEffect(() => setPicked(null), [selectedId])

  const urgent = useMemo(() => {
    const byId = (id: number) => parks.find((p) => p.id === id)
    const wet = overview.needs_attention.alerts.find((a) => (a.type === 'dry' || a.type === 'heat_stress') && byId(a.park.id))
    const any = overview.needs_attention.alerts.find((a) => byId(a.park.id))
    const hit = wet ?? any
    return { park: hit ? byId(hit.park.id) : undefined, hasAlert: hit != null }
  }, [overview, parks])

  const chosen = parks.find((p) => p.id === (picked ?? selectedId))
  const park = chosen ?? urgent.park ?? parks.find((p) => p.latest?.moisture_pct != null) ?? parks[0]
  const noSensor = park?.status === 'awaiting_install'

  const readings = useParkReadings(park && !noSensor ? park.id : NaN, range)
  const target = useMemo(() => {
    const w = overview.needs_attention.tasks_in_progress.find((x) => x.park.id === park?.id)?.watering
    return w?.target_pct ?? (park ? (park.moisture_min + park.moisture_max) / 2 : null)
  }, [overview, park])

  const points = useMemo(
    () => withGaps((readings.data?.data ?? []).map((b) => ({ ts: new Date(b.t).getTime(), v: b.moisture_pct }))),
    [readings.data],
  )
  const hasValues = points.some((p) => p.v != null)
  const ticks = useMemo(() => (points.length ? axisTicks(range, points[0].ts, points[points.length - 1].ts) : []), [points, range])
  const now = park?.latest?.moisture_pct ?? null
  const locale = i18n.language === 'ar' ? 'ar-u-nu-latn' : 'en-GB'
  const fmt = (ts: number) =>
    new Date(ts).toLocaleString(locale, range === '7d' ? { weekday: 'short' } : { hour: '2-digit', minute: '2-digit', hour12: false })
  const fmtTip = (ts: number) =>
    new Date(ts).toLocaleString(locale, range === '7d' ? { weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false } : { hour: '2-digit', minute: '2-digit', hour12: false })
  const name = park ? parkName(park, i18n.language) : ''
  const title = !park ? t('overview.moistureTitle') : chosen || !urgent.hasAlert ? t('overview.chartFor', { name }) : t('overview.mostUrgent', { name })

  return (
    <GlassCard as="section" className="flex min-w-0 flex-[2_1_560px] flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2.5">
        <div className="min-w-0">
          <h2 className="m-0 text-[17px] font-medium">{title}</h2>
          {park && <span className="text-xs text-text-2">{t(`overview.range_${range}`)}</span>}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {parks.length > 1 && (
            <select
              aria-label={t('overview.pickPark')}
              value={park?.id ?? ''}
              onChange={(e) => setPicked(Number(e.target.value))}
              className="h-9 max-w-[200px] rounded-full border border-[var(--glass-border-strong)] bg-[var(--glass-bg)] px-3 text-[13px] text-[var(--text)]"
            >
              {parks.map((p) => (
                <option key={p.id} value={p.id} className="bg-[var(--solid-over-map)]">
                  {parkName(p, i18n.language)}
                </option>
              ))}
            </select>
          )}
          <Segmented ariaLabel={t('overview.range')} value={range} onChange={setRange} options={RANGES.map((r) => ({ value: r, label: t(`overview.r_${r}`) }))} />
        </div>
      </div>
      {!park ? (
        <EmptyState title={t('overview.noParkForChart')} />
      ) : noSensor ? (
        <EmptyState title={t('overview.noSensorYet')} hint={t('overview.noSensorHint')} />
      ) : (
        <>
          <div className="flex flex-wrap items-baseline gap-2.5">
            <span className="text-4xl tabular-nums">{now != null ? `${formatNumber(now)}%` : '—'}</span>
            <span className="text-[13px] text-text-2">
              {t('overview.moistureLegend', { min: formatNumber(park.moisture_min), target: target != null ? formatNumber(target) : '—' })}
            </span>
          </div>
          <div className="h-[190px]" dir="ltr">
            {readings.isPending ? (
              <Skeleton h={190} radius={14} />
            ) : readings.isError ? (
              <EmptyState title={t('common.error')} />
            ) : !hasValues ? (
              <EmptyState title={t('park.noData')} />
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={points} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                  <defs>
                    <linearGradient id="ov-fill" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0" stopColor="#FF9A62" stopOpacity={0.32} />
                      <stop offset="1" stopColor="#FF9A62" stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid vertical={false} stroke="rgba(255,255,255,.08)" />
                  <XAxis dataKey="ts" type="number" scale="time" domain={['dataMin', 'dataMax']} ticks={ticks} interval={0} tickFormatter={fmt} tick={{ fill: '#A9A597', fontSize: 11 }} axisLine={false} tickLine={false} />
                  <YAxis domain={[0, 100]} width={32} tick={{ fill: '#A9A597', fontSize: 11 }} axisLine={false} tickLine={false} />
                  <Tooltip
                    contentStyle={{ background: 'rgba(16,18,14,.92)', border: '1px solid rgba(255,255,255,.14)', borderRadius: 12, color: '#EEECE3' }}
                    labelFormatter={(ts) => fmtTip(Number(ts))}
                    formatter={(v) => [`${formatNumber(Number(v))}%`, t('park.moisture')]}
                  />
                  <ReferenceLine y={park.moisture_min} stroke="#C9C5B8" strokeOpacity={0.7} strokeDasharray="8 6" />
                  {target != null && <ReferenceLine y={target} stroke="#C8F25A" strokeOpacity={0.7} strokeDasharray="2 6" strokeWidth={2} />}
                  <Area type="monotone" dataKey="v" stroke="#FF9A62" strokeWidth={2} fill="url(#ov-fill)" connectNulls={false} isAnimationActive={false} />
                </AreaChart>
              </ResponsiveContainer>
            )}
          </div>
        </>
      )}
    </GlassCard>
  )
}

/** Alerts of the last 30 days by category (devices awaiting installation never raise alerts). */
function CategoriesCard() {
  const { t } = useTranslation()
  const overview = useOverview()
  const cats = overview.data?.alerts_by_category
  const rows = (['plant', 'water', 'cleanliness', 'devices'] as const).map((c) => ({ c, n: cats?.[c] ?? 0 }))
  const total = rows.reduce((a, r) => a + r.n, 0)
  const max = Math.max(1, ...rows.map((r) => r.n))

  return (
    <GlassCard as="section" className="flex min-w-0 flex-[1_1_300px] flex-col gap-3.5">
      <div className="flex items-center justify-between">
        <h2 className="m-0 text-[17px] font-medium">{t('overview.byCategory')}</h2>
        <span className="text-xs text-text-2">{t('overview.last30')}</span>
      </div>
      {overview.isPending ? (
        [0, 1, 2].map((i) => <Skeleton key={i} h={34} radius={10} />)
      ) : overview.isError ? (
        <EmptyState icon={<Activity />} title={t('common.error')} />
      ) : total === 0 ? (
        <EmptyState icon={<CircleCheck />} title={t('overview.noAlerts30')} />
      ) : (
        rows
          .filter((r) => r.n > 0)
          .map((r) => (
            <div key={r.c}>
              <div className="mb-1.5 flex justify-between text-[13px]">
                <span>{t(`category.${r.c}`)}</span>
                <span className="tabular-nums">{formatNumber(r.n, 0)}</span>
              </div>
              <div className="h-2 overflow-hidden rounded-full bg-white/[.08]">
                <motion.div
                  className="h-full origin-left rounded-full rtl:origin-right"
                  style={{ background: CATEGORY_COLOR[r.c], width: `${(r.n / max) * 100}%` }}
                  initial={{ scaleX: 0 }}
                  animate={{ scaleX: 1 }}
                  transition={{ duration: 0.4, ease: 'easeOut' }}
                />
              </div>
            </div>
          ))
      )}
      <span className="text-xs text-text-2">{t('overview.notCountedNote')}</span>
    </GlassCard>
  )
}

function OverviewSkeleton() {
  return (
    <div className="flex flex-col gap-5" aria-busy="true">
      <div className="flex flex-wrap gap-6">
        <Skeleton w={220} h={84} radius={14} />
        {[0, 1, 2, 3].map((i) => (
          <Skeleton key={i} w={150} h={48} radius={24} />
        ))}
      </div>
      <Skeleton h={40} radius={20} />
      <div className="flex flex-wrap gap-5">
        <Skeleton h={600} radius={26} className="min-w-0 flex-[5_1_620px]" />
        <Skeleton h={600} radius={26} className="min-w-0 flex-[2_1_340px]" />
      </div>
    </div>
  )
}
