import { BellRing, CheckCircle2, Clock, Droplets, Info, TrendingUp, TriangleAlert } from 'lucide-react'
import type { TFunction } from 'i18next'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { CATEGORY_META } from '@/components/ops'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { AlertCategory, Impact, ImpactTasks, ImpactWater, Priority } from '@/types'

export const IMPACT_DAYS = [7, 30] as const

/** "7 days / 30 days" switch. */
export function DaysSwitch({ days, onChange }: { days: number; onChange: (days: number) => void }) {
  const { t } = useTranslation()
  return (
    <div className="flex gap-1 rounded-lg bg-muted p-1" role="group" aria-label={t('impact.period')}>
      {IMPACT_DAYS.map((d) => (
        <button
          key={d}
          type="button"
          onClick={() => onChange(d)}
          aria-pressed={days === d}
          className={cn('rounded-md px-3 py-1 text-sm font-medium', days === d ? 'bg-background shadow-sm' : 'text-muted-foreground hover:text-foreground')}
        >
          {t('impact.lastDays', { count: d })}
        </button>
      ))}
    </div>
  )
}

function Kpi({ icon, label, value, hint, tone, info }: { icon: ReactNode; label: string; value: string; hint?: ReactNode; tone?: string; info?: ReactNode }) {
  return (
    <Card className="gap-1 py-4">
      <CardContent className="flex flex-col gap-1 px-4">
        <span className="flex items-center gap-1.5 text-xs text-muted-foreground [&_svg]:size-3.5">
          {icon}
          {label}
          {info && (
            <Tooltip>
              <TooltipTrigger asChild>
                <button type="button" className="ms-auto rounded-full hover:text-foreground" aria-label={typeof label === 'string' ? label : undefined}>
                  <Info />
                </button>
              </TooltipTrigger>
              <TooltipContent className="max-w-sm leading-relaxed">{info}</TooltipContent>
            </Tooltip>
          )}
        </span>
        <span className={cn('text-2xl font-semibold tabular-nums', tone)}>{value}</span>
        {hint && <span className="text-xs text-muted-foreground">{hint}</span>}
      </CardContent>
    </Card>
  )
}

export const pct = (value: number | null | undefined, digits = 0) => (value == null ? '—' : `${formatNumber(value, digits)} %`)
export const litres = (value: number | null | undefined) => (value == null ? '—' : `${formatNumber(value, value >= 100 ? 0 : 1)} L`)
export const pumpMinutes = (t: TFunction, value: number | null | undefined) =>
  value == null ? '—' : t('impact.pumpMinutes', { value: formatNumber(value, value >= 100 ? 0 : 1) })

/** Water saved % against the schedule, with the honest "how measured" line. */
export function waterHint(t: TFunction, water: ImpactWater) {
  if (!water.enough_data) {
    const needs = waterNeeds(t, water)
    const excluded = water.parks_not_enough_data ? t('impact.parksExcluded', { count: water.parks_not_enough_data }) : ''
    return [needs, excluded].filter(Boolean).join('. ')
  }
  if (water.parks_measured === 0) return t('impact.noWaterData')
  const line = t('impact.waterHint', {
    pump: pumpMinutes(t, water.pump_min),
    baseline: pumpMinutes(t, water.baseline_min),
    hours: formatNumber(water.measured_hours, water.measured_hours >= 10 ? 0 : 1),
  })
  const excluded = water.parks_not_enough_data ? `. ${t('impact.parksExcluded', { count: water.parks_not_enough_data })}` : ''
  return `${line}${excluded}`
}

/** What is still missing before a saving can be shown: cycles and measured hours. */
export function waterNeeds(t: TFunction, water: ImpactWater) {
  const cycles = Math.max(0, water.min_cycles - water.cycles)
  const hours = Math.max(0, Math.ceil(water.min_measured_hours - water.measured_hours))
  const parts: string[] = []
  if (cycles > 0) parts.push(t('impact.needCycles', { count: cycles }))
  if (hours > 0) parts.push(t('impact.needHours', { count: hours }))
  return parts.length ? t('impact.needs', { what: parts.join(` ${t('impact.and')} `) }) : ''
}

/** Tooltip: how the water saving is measured, and its assumptions. */
export function waterMethod(t: TFunction, water: ImpactWater) {
  return t('impact.waterMethod', { sessions: water.schedule.sessions_per_day, minutes: water.schedule.minutes_per_session })
}

/** Per-park cell: the saving, or why the park is not compared. */
export function waterSavedCell(t: TFunction, water: ImpactWater) {
  if (water.not_compared) return t(`impact.notCompared.${water.not_compared}`)
  return water.enough_data ? pct(water.saved_pct) : t('impact.notEnoughData')
}

/** The KPI cards: water, alerts, tasks done, verified, median time, escalated / overdue. */
export function ImpactKpis({ impact }: { impact: Impact | undefined }) {
  const { t } = useTranslation()
  if (!impact)
    return (
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
        {[0, 1, 2, 3, 4, 5].map((i) => (
          <Skeleton key={i} className="h-28" />
        ))}
      </div>
    )
  const { water, alerts, tasks } = impact

  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
      <Kpi
        icon={<Droplets />}
        label={t('impact.waterSaved')}
        value={water.enough_data ? pct(water.saved_pct) : t('impact.notEnoughData')}
        tone={!water.enough_data ? 'text-base leading-8 text-muted-foreground' : water.saved_pct != null && water.saved_pct > 0 ? 'text-status-ok' : undefined}
        hint={waterHint(t, water)}
        info={waterMethod(t, water)}
      />
      <Kpi
        icon={<BellRing />}
        label={t('impact.alerts')}
        value={formatNumber(alerts.total, 0)}
        hint={t('impact.alertsHint', { urgent: alerts.by_priority.urgent ?? 0, high: alerts.by_priority.high ?? 0 })}
      />
      <Kpi icon={<CheckCircle2 />} label={t('impact.tasksDone')} value={formatNumber(tasks.done, 0)} hint={t('impact.pendingHint', { count: tasks.pending })} />
      <Kpi icon={<TrendingUp />} label={t('impact.verified')} value={pct(tasks.verified_rate == null ? null : tasks.verified_rate * 100)} hint={t('impact.verifiedHint', { verified: tasks.verified, failed: tasks.not_verified })} />
      <Kpi icon={<Clock />} label={t('impact.medianResolve')} value={tasks.median_resolve_min == null ? '—' : t('impact.minutes', { count: tasks.median_resolve_min })} hint={t('impact.medianHint')} />
      <Kpi
        icon={<TriangleAlert />}
        label={t('impact.escalated')}
        value={formatNumber(tasks.escalated, 0)}
        tone={tasks.overdue > 0 ? 'text-status-dry' : undefined}
        hint={t('impact.overdueHint', { count: tasks.overdue })}
      />
    </div>
  )
}

/** Alerts by category and by priority, as small bars. */
export function AlertBreakdown({ impact }: { impact: Impact }) {
  const { t } = useTranslation()
  const categories = Object.entries(impact.alerts.by_category) as [AlertCategory, number][]
  const priorities = (['urgent', 'high', 'normal', 'low'] as Priority[]).map((p) => [p, impact.alerts.by_priority[p] ?? 0] as const)
  const max = Math.max(1, ...categories.map(([, n]) => n), ...priorities.map(([, n]) => n))

  const Bar = ({ label, n, color }: { label: ReactNode; n: number; color: string }) => (
    <li className="grid grid-cols-[8rem_1fr_2rem] items-center gap-2 text-sm">
      <span className="truncate text-muted-foreground">{label}</span>
      <span className="h-2 rounded-full bg-muted">
        <span className={cn('block h-full rounded-full', color)} style={{ width: `${(n / max) * 100}%` }} />
      </span>
      <span className="text-end tabular-nums">{n}</span>
    </li>
  )

  return (
    <div className="grid gap-6 md:grid-cols-2">
      <div className="flex flex-col gap-2">
        <h3 className="text-sm font-medium">{t('impact.byCategory')}</h3>
        <ul className="flex flex-col gap-1.5">
          {categories.map(([c, n]) => (
            <Bar key={c} label={<span className={cn('flex items-center gap-1.5 [&_svg]:size-3.5', CATEGORY_META[c].text)}>{CATEGORY_META[c].icon}{t(`category.${c}`)}</span>} n={n} color={CATEGORY_META[c].bar} />
          ))}
        </ul>
      </div>
      <div className="flex flex-col gap-2">
        <h3 className="text-sm font-medium">{t('impact.byPriority')}</h3>
        <ul className="flex flex-col gap-1.5">
          {priorities.map(([p, n]) => (
            <Bar key={p} label={t(`priority.${p}`)} n={n} color={p === 'urgent' ? 'bg-red-500' : p === 'high' ? 'bg-orange-500' : p === 'normal' ? 'bg-sky-500' : 'bg-zinc-400'} />
          ))}
        </ul>
      </div>
    </div>
  )
}

/** "2 real parks, 3 simulated: figures of simulated parks come from the simulator." */
export function SourceNote({ impact }: { impact: Impact }) {
  const { t } = useTranslation()
  return (
    <p className="flex items-start gap-2 rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground">
      <Info className="mt-0.5 size-3.5 shrink-0" />
      <span>
        {t('impact.sourceNote', { real: impact.source_mix.real, simulated: impact.source_mix.simulated })} {t('impact.baselineNote')}
      </span>
    </p>
  )
}

export function verifiedText(tasks: ImpactTasks) {
  return tasks.verified_rate == null ? '—' : pct(tasks.verified_rate * 100)
}
