import { ArrowLeft, Droplets, Gauge, Power, Thermometer, Timer, Waves } from 'lucide-react'
import { type ReactNode, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { CartesianGrid, Line, LineChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { SourceBadge, StatusPill } from '@/components/StatusPill'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { parkName, usePark, useParkReadings } from '@/hooks/useParks'
import { ApiError } from '@/lib/api'
import { formatAgo, formatDateTime, formatNumber, formatTime } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { ParkDetail, ReadingBucket, ReadingRange } from '@/types'

export default function ParkPage() {
  const { t, i18n } = useTranslation()
  const id = Number(useParams().id)
  const park = usePark(id)
  const [range, setRange] = useState<ReadingRange>('24h')

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
        <span className="ms-auto text-xs text-muted-foreground">{t('park.refreshNote')}</span>
      </div>

      <StatCards park={p} />

      <Card>
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
          <div>
            <CardTitle>{t('park.chartMoisture')}</CardTitle>
            <CardDescription>{t('park.thresholds', { min: p.moisture_min, max: p.moisture_max })}</CardDescription>
          </div>
          <Tabs value={range} onValueChange={(v) => setRange(v as ReadingRange)}>
            <TabsList>
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
        <AlertsCard park={p} />
      </div>
    </div>
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

  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
      <StatCard icon={<Droplets />} label={t('park.moisture')} value={unit(l?.moisture_pct, '%')} hint={moistureHint} />
      <StatCard icon={<Thermometer />} label={t('park.temperature')} value={unit(l?.temperature, '°C')} />
      <StatCard icon={<Gauge />} label={t('park.humidity')} value={unit(l?.humidity, '%')} />
      <StatCard icon={<Waves />} label={t('park.flow')} value={unit(l?.flow, 'L/min', 2)} />
      <StatCard
        icon={<Power />}
        label={t('park.pump')}
        value={!park.has_pump ? t('park.noPump') : l?.pump_on == null ? '—' : l.pump_on ? t('park.pumpOn') : t('park.pumpOff')}
      />
      <StatCard
        icon={<Timer />}
        label={t('park.lastReading')}
        value={park.last_reading_at ? formatAgo(park.last_reading_at) : t('common.never')}
        hint={park.last_reading_at ? formatDateTime(park.last_reading_at) : undefined}
      />
    </div>
  )
}

const COLORS = { moisture: '#38bdf8', temperature: '#f97316', humidity: '#a78bfa', min: '#f97316', max: '#3b82f6' }

function ReadingCharts({ park, range }: { park: ParkDetail; range: ReadingRange }) {
  const { t } = useTranslation()
  const series = useParkReadings(park.id, range)

  if (series.isPending) return <Skeleton className="h-64" />
  if (series.isError) return <p className="text-sm text-destructive">{t('common.error')}</p>

  const data = series.data.data.map((b) => ({ ...b, ts: new Date(b.t).getTime() }))
  if (data.length === 0) return <p className="py-10 text-center text-sm text-muted-foreground">{t('park.noData')}</p>

  return (
    <div className="flex flex-col gap-6">
      <Chart data={data} dataKey="moisture_pct" color={COLORS.moisture} range={range} domain={[0, 100]} unit="%">
        <ReferenceLine y={park.moisture_min} stroke={COLORS.min} strokeDasharray="4 4" label={{ value: `${t('park.min')} ${park.moisture_min}`, position: 'insideBottomRight', fill: COLORS.min, fontSize: 11 }} />
        <ReferenceLine y={park.moisture_max} stroke={COLORS.max} strokeDasharray="4 4" label={{ value: `${t('park.max')} ${park.moisture_max}`, position: 'insideTopRight', fill: COLORS.max, fontSize: 11 }} />
      </Chart>
      <div className="grid gap-6 lg:grid-cols-2">
        <div>
          <h3 className="mb-2 text-sm font-medium">{t('park.chartTemperature')}</h3>
          <Chart data={data} dataKey="temperature" color={COLORS.temperature} range={range} unit="°C" height={200} />
        </div>
        <div>
          <h3 className="mb-2 text-sm font-medium">{t('park.chartHumidity')}</h3>
          <Chart data={data} dataKey="humidity" color={COLORS.humidity} range={range} domain={[0, 100]} unit="%" height={200} />
        </div>
      </div>
    </div>
  )
}

function Chart({
  data,
  dataKey,
  color,
  range,
  unit,
  domain,
  height = 260,
  children,
}: {
  data: (ReadingBucket & { ts: number })[]
  dataKey: 'moisture_pct' | 'temperature' | 'humidity'
  color: string
  range: ReadingRange
  unit: string
  domain?: [number, number]
  height?: number
  children?: ReactNode
}) {
  return (
    // Time runs left to right in both languages.
    <div dir="ltr" style={{ height }}>
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data} margin={{ top: 8, right: 12, bottom: 0, left: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="currentColor" className="text-border" vertical={false} />
          <XAxis
            dataKey="ts"
            type="number"
            scale="time"
            domain={['dataMin', 'dataMax']}
            tickFormatter={(v: number) => formatTime(new Date(v).toISOString(), range === '7d')}
            tick={{ fontSize: 11, fill: 'currentColor' }}
            className="text-muted-foreground"
            minTickGap={40}
          />
          <YAxis
            domain={domain ?? ['auto', 'auto']}
            width={40}
            tick={{ fontSize: 11, fill: 'currentColor' }}
            className="text-muted-foreground"
            tickFormatter={(v: number) => formatNumber(v, 0)}
          />
          <Tooltip
            labelFormatter={(v) => formatDateTime(new Date(Number(v)).toISOString())}
            formatter={(v) => [`${formatNumber(Number(v))} ${unit}`, '']}
            contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--popover-foreground)' }}
          />
          {children}
          <Line type="monotone" dataKey={dataKey} stroke={color} strokeWidth={2} dot={false} connectNulls isAnimationActive={false} />
        </LineChart>
      </ResponsiveContainer>
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
              <div className="text-end text-xs text-muted-foreground" title={formatDateTime(node.last_seen_at)}>
                {t('park.lastSeen')}: {node.last_seen_at ? formatAgo(node.last_seen_at) : t('common.never')}
              </div>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  )
}

function AlertsCard({ park }: { park: ParkDetail }) {
  const { t } = useTranslation()

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
            {park.open_alerts.map((alert) => (
              <li key={alert.id} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                <div className="flex items-center gap-2">
                  <Badge variant={alert.severity === 'critical' ? 'destructive' : 'secondary'}>{t(`severity.${alert.severity}`)}</Badge>
                  <span className="font-medium">{t(`alertType.${alert.type}`)}</span>
                </div>
                <span className="text-xs text-muted-foreground" title={formatDateTime(alert.created_at)}>
                  {formatAgo(alert.created_at)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
