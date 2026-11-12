import { Info } from 'lucide-react'
import type { TFunction } from 'i18next'
import { useTranslation } from 'react-i18next'
import { formatNumber } from '@/lib/format'
import type { Impact, ImpactTasks, ImpactWater } from '@/types'

export const IMPACT_DAYS = [7, 30] as const
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
