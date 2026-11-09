import { memo, type ReactNode, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { CartesianGrid, Line, LineChart, ReferenceArea, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { useNow } from '@/components/ops'
import { Skeleton } from '@/components/ui/skeleton'
import { useParkReadings } from '@/hooks/useParks'
import i18n from '@/i18n'
import { formatNumber } from '@/lib/format'
import type { ParkDetail, ReadingBucket, ReadingRange } from '@/types'

const COLORS = { moisture: '#38bdf8', temperature: '#f97316', humidity: '#a78bfa', battery: '#22c55e', flow: '#06b6d4', min: '#f97316', max: '#3b82f6', gap: '#71717a' }

/** Window length and tick spacing per range. Ticks sit on round Makkah times. */
const RANGE: Record<ReadingRange, { ms: number; tick: number }> = {
  '1h': { ms: 3_600_000, tick: 10 * 60_000 },
  '24h': { ms: 86_400_000, tick: 3 * 3_600_000 },
  '7d': { ms: 7 * 86_400_000, tick: 86_400_000 },
}

const RIYADH_OFFSET = 3 * 3_600_000 // Asia/Riyadh is UTC+3 all year (no daylight saving)
const GAP_BUCKETS = 3 // a gap longer than this many buckets gets a "No data" band

type Point = ReadingBucket & { ts: number }
type SeriesKey = 'moisture_pct' | 'temperature' | 'humidity' | 'battery_pct' | 'flow'

function ticksFor(from: number, to: number, step: number): number[] {
  const ticks: number[] = []
  for (let t = Math.ceil((from + RIYADH_OFFSET) / step) * step - RIYADH_OFFSET; t <= to; t += step) ticks.push(t)
  return ticks
}

const KEYS: SeriesKey[] = ['moisture_pct', 'temperature', 'humidity', 'battery_pct', 'flow']

/**
 * One empty bucket between two full ones is sampling jitter (a reading landed
 * just past a bucket edge), not an outage: draw the line through it with the
 * mean of its neighbours. Two or more empty buckets stay a gap.
 */
function bridgeSingleHoles(data: Point[]): Point[] {
  return data.map((p, i) => {
    const prev = data[i - 1]
    const next = data[i + 1]
    if (p.readings !== 0 || !prev || !next || prev.readings === 0 || next.readings === 0) return p
    const bridged = { ...p }
    for (const key of KEYS) {
      const a = prev[key]
      const b = next[key]
      bridged[key] = a != null && b != null ? (a + b) / 2 : null
    }
    return bridged
  })
}

/** Runs of empty buckets longer than GAP_BUCKETS, as [start, end] times. */
function gapsOf(data: Point[], bucketMs: number): [number, number][] {
  const gaps: [number, number][] = []
  let start: number | null = null
  let count = 0
  const close = (end: number) => {
    if (start !== null && count > GAP_BUCKETS) gaps.push([start, end])
    start = null
    count = 0
  }
  for (const p of data) {
    if (p.readings === 0) {
      if (start === null) start = p.ts
      count++
    } else {
      close(p.ts)
    }
  }
  if (data.length) close(data[data.length - 1].ts + bucketMs)
  return gaps
}

function useFormatters(range: ReadingRange) {
  const language = i18n.language
  return useMemo(() => {
    const locale = language === 'ar' ? 'ar-SA-u-ca-gregory-nu-latn' : 'en-GB'
    const base = { timeZone: 'Asia/Riyadh', hourCycle: 'h23' } as const
    const tick = new Intl.DateTimeFormat(locale, range === '7d' ? { ...base, weekday: 'short', day: 'numeric' } : { ...base, hour: '2-digit', minute: '2-digit' })
    const full = new Intl.DateTimeFormat(locale, {
      ...base,
      ...(range === '1h' ? {} : { weekday: 'short', day: 'numeric', month: 'short' }),
      hour: '2-digit',
      minute: '2-digit',
      ...(range === '1h' ? { second: '2-digit' } : {}),
    })
    return { tick: (v: number) => tick.format(v), full: (v: number) => full.format(v) }
  }, [language, range])
}

/** The park page charts: one shared crosshair, real time axis, gaps shown as gaps. */
export function ReadingCharts({ park, range }: { park: ParkDetail; range: ReadingRange }) {
  const { t } = useTranslation()
  const series = useParkReadings(park.id, range)
  const now = useNow(range === '1h' ? 5_000 : 60_000)
  const fmt = useFormatters(range)

  const raw = series.data?.data
  const bucketMs = (series.data?.bucket_seconds ?? 60) * 1000
  const data = useMemo<Point[]>(() => bridgeSingleHoles((raw ?? []).map((b) => ({ ...b, ts: new Date(b.t).getTime() }))), [raw])
  const gaps = useMemo(() => gapsOf(data, bucketMs), [data, bucketMs])
  const has = useMemo(
    () => ({
      battery: data.some((p) => p.battery_pct != null),
      flow: park.has_pump || data.some((p) => p.flow != null && p.flow > 0),
    }),
    [data, park.has_pump],
  )

  // Minute precision is enough for the axis; it keeps the domain stable between renders.
  const to = Math.ceil(now / 60_000) * 60_000
  const from = to - RANGE[range].ms
  const ticks = useMemo(() => ticksFor(from, to, RANGE[range].tick), [from, to, range])

  if (series.isPending) return <Skeleton className="h-64" />
  if (series.isError) return <p className="text-sm text-destructive">{t('common.error')}</p>
  if (!data.some((p) => p.readings > 0)) return <p className="py-10 text-center text-sm text-muted-foreground">{t('park.noData')}</p>

  const common = { data, from, to, ticks, gaps, fmt, syncId: `park-${park.id}`, gapLabel: t('park.noDataBand') }

  return (
    <div className="flex flex-col gap-6">
      <Chart {...common} dataKey="moisture_pct" color={COLORS.moisture} label={t('park.moisture')} unit="%" domain={[0, 100]}>
        <ReferenceLine
          y={park.moisture_max}
          stroke={COLORS.max}
          strokeDasharray="5 4"
          ifOverflow="extendDomain"
          label={{ value: `${t('park.max')} ${park.moisture_max}`, position: 'insideBottomLeft', fill: COLORS.max, fontSize: 11, offset: 6 }}
        />
        <ReferenceLine
          y={park.moisture_min}
          stroke={COLORS.min}
          strokeDasharray="5 4"
          ifOverflow="extendDomain"
          label={{ value: `${t('park.min')} ${park.moisture_min}`, position: 'insideTopLeft', fill: COLORS.min, fontSize: 11, offset: 6 }}
        />
      </Chart>
      <div className="grid gap-6 lg:grid-cols-2">
        <ChartBlock title={t('park.chartTemperature')}>
          <Chart {...common} dataKey="temperature" color={COLORS.temperature} label={t('park.temperature')} unit="°C" height={200} pad={2} />
        </ChartBlock>
        <ChartBlock title={t('park.chartHumidity')}>
          <Chart {...common} dataKey="humidity" color={COLORS.humidity} label={t('park.humidity')} unit="%" domain={[0, 100]} height={200} />
        </ChartBlock>
        {has.flow && (
          <ChartBlock title={t('park.chartFlow')}>
            <Chart {...common} dataKey="flow" color={COLORS.flow} label={t('park.flow')} unit="L/min" height={200} pad={0.5} digits={2} min0 />
          </ChartBlock>
        )}
        {has.battery && (
          <ChartBlock title={t('park.chartBattery')} badge={t('park.simulated')}>
            <Chart {...common} dataKey="battery_pct" color={COLORS.battery} label={t('park.battery')} unit="%" domain={[0, 100]} height={200} />
          </ChartBlock>
        )}
      </div>
    </div>
  )
}

function ChartBlock({ title, badge, children }: { title: string; badge?: string; children: ReactNode }) {
  return (
    <div>
      <h3 className="mb-2 flex items-center gap-2 text-sm font-medium">
        {title}
        {badge && <span className="rounded border border-dashed px-1.5 text-xs font-normal text-muted-foreground">{badge}</span>}
      </h3>
      {children}
    </div>
  )
}

interface ChartProps {
  data: Point[]
  from: number
  to: number
  ticks: number[]
  gaps: [number, number][]
  fmt: { tick: (v: number) => string; full: (v: number) => string }
  syncId: string
  gapLabel: string
  dataKey: SeriesKey
  color: string
  label: string
  unit: string
  domain?: [number, number]
  pad?: number
  digits?: number
  min0?: boolean
  height?: number
  children?: ReactNode
}

const Chart = memo(function Chart({ data, from, to, ticks, gaps, fmt, syncId, gapLabel, dataKey, color, label, unit, domain, pad = 1, digits = 1, min0, height = 260, children }: ChartProps) {
  const yDomain = useMemo(
    () =>
      domain ??
      (([min, max]: readonly [number, number]): [number, number] => [
        min0 ? Math.max(0, Math.floor(min - pad)) : Math.floor(min - pad),
        Math.ceil(max + pad),
      ]),
    [domain, pad, min0],
  )
  const tooltip = useMemo(
    () =>
      function ChartTooltip({ active, payload, label: at }: { active?: boolean; payload?: readonly { value?: unknown }[]; label?: unknown }) {
        if (!active || !payload?.length) return null
        const value = payload[0].value
        return (
          // The chart is LTR; the tooltip text follows the page language.
          <div dir={i18n.dir()} className="rounded-md border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground shadow-md">
            <div className="text-muted-foreground tabular-nums">{fmt.full(Number(at))}</div>
            <div className="flex items-center gap-1.5 font-medium">
              <span className="size-2 rounded-full" style={{ background: color }} />
              <span>{label}:</span>
              <bdi dir="ltr" className="tabular-nums">{value == null ? '—' : `${formatNumber(Number(value), digits)} ${unit}`}</bdi>
            </div>
          </div>
        )
      },
    [fmt, color, label, unit, digits],
  )

  return (
    // Time runs left to right in both languages.
    <div dir="ltr" style={{ height }}>
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data} syncId={syncId} syncMethod="value" margin={{ top: 10, right: 16, bottom: 0, left: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="currentColor" className="text-border" vertical={false} />
          {gaps.map(([x1, x2]) => (
            <ReferenceArea
              key={x1}
              x1={Math.max(x1, from)}
              x2={Math.min(x2, to)}
              ifOverflow="hidden"
              fill={COLORS.gap}
              fillOpacity={0.1}
              label={{ value: gapLabel, position: 'insideTop', fill: COLORS.gap, fontSize: 11 }}
            />
          ))}
          <XAxis
            dataKey="ts"
            type="number"
            scale="time"
            domain={[from, to]}
            allowDataOverflow
            ticks={ticks}
            tickFormatter={fmt.tick}
            tick={{ fontSize: 11, fill: 'currentColor' }}
            className="text-muted-foreground"
          />
          <YAxis domain={yDomain} width={40} tick={{ fontSize: 11, fill: 'currentColor' }} className="text-muted-foreground" tickFormatter={(v: number) => formatNumber(v, 0)} allowDecimals={false} />
          <Tooltip content={tooltip} cursor={{ stroke: 'currentColor', strokeOpacity: 0.35, strokeWidth: 1 }} isAnimationActive={false} />
          {children}
          <Line
            type="monotoneX"
            dataKey={dataKey}
            stroke={color}
            strokeWidth={2}
            dot={false}
            activeDot={{ r: 4, strokeWidth: 0 }}
            connectNulls={false}
            isAnimationActive={false}
          />
        </LineChart>
      </ResponsiveContainer>
    </div>
  )
})
