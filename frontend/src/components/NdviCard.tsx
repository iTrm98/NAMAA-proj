import { Satellite } from 'lucide-react'
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import type { ApiScope } from '@/lib/scope'
import { useParkNdvi } from '@/hooks/useNdvi'
import { formatDate, formatNumber } from '@/lib/format'
import { ndviColor } from '@/lib/ndvi'

const DAY = 86_400_000
/** Two satellite values further apart than this are not joined by a line. */
const GAP_DAYS = 15

type Point = { ts: number; mean: number | null; pixels: number | null; date: string | null }


/** Park page: Sentinel-2 NDVI over the last 90 days, as points (a line only between close values). */
export function NdviCard({ parkId, scope }: { parkId: number; scope?: ApiScope }) {
  const { t } = useTranslation()
  const ndvi = useParkNdvi(parkId, scope, 90)

  const data = useMemo<Point[]>(() => {
    const out: Point[] = []
    for (const p of ndvi.data ?? []) {
      const ts = Date.parse(`${p.date}T00:00:00Z`)
      const prev = out[out.length - 1]
      // A null point between two values far apart breaks the line there.
      if (prev && prev.mean != null && ts - prev.ts > GAP_DAYS * DAY) out.push({ ts: prev.ts + DAY, mean: null, pixels: null, date: null })
      // Too-small rows carry no mean: they are never drawn.
      out.push({ ts, mean: p.too_small ? null : p.mean, pixels: p.pixels, date: p.date })
    }
    return out
  }, [ndvi.data])
  const values = (ndvi.data ?? []).filter((p) => !p.too_small && p.mean != null)
  const latest = ndvi.data?.[ndvi.data.length - 1]
  const now = Date.now()

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-2">
        <div className="flex flex-col gap-1">
          <CardTitle className="flex items-center gap-2">
            <Satellite className="size-4" />
            {t('ndvi.title')}
          </CardTitle>
          <CardDescription>{t('ndvi.source')}</CardDescription>
        </div>
        {latest?.too_small && (
          <div className="flex flex-col items-end text-end">
            <span className="flex items-center gap-1.5 text-sm font-medium text-muted-foreground">
              <span className="size-3 rounded-full" style={{ backgroundColor: ndviColor(null) }} />
              {t('ndvi.tooSmall')}
            </span>
            <span className="text-xs text-muted-foreground">
              {t('ndvi.latestImage')}: <bdi>{formatDate(latest.date, true)}</bdi>
            </span>
          </div>
        )}
        {latest && !latest.too_small && (
          <div className="flex flex-col items-end text-end">
            <span className="flex items-center gap-1.5 text-lg font-semibold tabular-nums">
              <span className="size-3 rounded-full" style={{ backgroundColor: ndviColor(latest.mean) }} />
              {formatNumber(latest.mean ?? 0, 2)}
            </span>
            <span className="text-xs text-muted-foreground">
              {t('ndvi.latestImage')}: <bdi>{formatDate(latest.date, true)}</bdi>
            </span>
          </div>
        )}
      </CardHeader>
      <CardContent>
        {ndvi.isPending ? (
          <Skeleton className="h-48 w-full" />
        ) : ndvi.isError ? (
          <p className="text-sm text-destructive">{t('common.error')}</p>
        ) : latest?.too_small && values.length === 0 ? (
          <p className="py-10 text-center text-sm text-muted-foreground">{t('ndvi.tooSmallBody')}</p>
        ) : values.length === 0 ? (
          <p className="py-10 text-center text-sm text-muted-foreground">{t('ndvi.noImage')}</p>
        ) : (
          <div className="h-48 w-full" dir="ltr">
            <ResponsiveContainer>
              <LineChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: -12 }}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-border" vertical={false} />
                <XAxis
                  dataKey="ts"
                  type="number"
                  scale="time"
                  domain={[now - 90 * DAY, now]}
                  tickFormatter={(ts: number) => formatDate(new Date(ts).toISOString(), true)}
                  tick={{ fontSize: 11 }}
                  className="fill-muted-foreground"
                  tickCount={6}
                />
                <YAxis domain={[0, (max: number) => Math.max(0.8, Math.ceil(max * 10) / 10)]} tick={{ fontSize: 11 }} tickFormatter={(v: number) => v.toFixed(1)} />
                <Tooltip
                  content={({ active, payload }) => {
                    const p = active ? (payload?.[0]?.payload as Point | undefined) : undefined
                    if (!p || p.mean == null) return null
                    return (
                      <div className="rounded-md border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground shadow">
                        <div className="font-medium">{formatDate(p.date)}</div>
                        <div>NDVI {formatNumber(p.mean, 3)}</div>
                        <div className="text-muted-foreground">{t('ndvi.pixels', { count: p.pixels ?? 0 })}</div>
                      </div>
                    )
                  }}
                />
                <Line
                  dataKey="mean"
                  stroke="#2a9d4b"
                  strokeWidth={1.5}
                  connectNulls={false}
                  isAnimationActive={false}
                  dot={(props: { cx?: number; cy?: number; payload?: Point; index?: number }) =>
                    props.payload?.mean == null || props.cx == null || props.cy == null ? (
                      <g key={props.index} />
                    ) : (
                      <circle key={props.index} cx={props.cx} cy={props.cy} r={4} fill={ndviColor(props.payload.mean)} stroke="white" strokeWidth={1} />
                    )
                  }
                  activeDot={{ r: 6 }}
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
