import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { AlertBreakdown, DaysSwitch, ImpactKpis, litres, pumpMinutes, SourceNote, verifiedText, waterSavedCell } from '@/components/impact'
import { SourceBadge } from '@/components/StatusPill'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useImpact } from '@/hooks/useImpact'
import { parkName } from '@/hooks/useParks'
import { formatNumber } from '@/lib/format'
import { ndviColor } from '@/lib/ndvi'

/** /admin/impact: the company's figures for the last 7 or 30 days, and a row per park. */
export default function ImpactPage() {
  const { t, i18n } = useTranslation()
  const [days, setDays] = useState(30)
  const impact = useImpact(days)
  const data = impact.data

  return (
    <div className="flex flex-col gap-5 p-4 lg:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">{t('impact.title')}</h1>
          <p className="text-sm text-muted-foreground">{t('impact.intro')}</p>
        </div>
        <DaysSwitch days={days} onChange={setDays} />
      </div>

      {impact.isError && <p className="text-sm text-destructive">{t('common.error')}</p>}
      <ImpactKpis impact={data} />
      {data && <SourceNote impact={data} />}

      {data && (
        <Card>
          <CardHeader>
            <CardTitle>{t('impact.alertsTitle')}</CardTitle>
          </CardHeader>
          <CardContent>
            <AlertBreakdown impact={data} />
          </CardContent>
        </Card>
      )}

      {data && (
        <Card>
          <CardHeader>
            <CardTitle>{t('impact.perPark')}</CardTitle>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead className="text-xs text-muted-foreground">
                <tr className="border-b text-start">
                  <th className="py-2 text-start font-medium">{t('impact.park')}</th>
                  <th className="py-2 text-end font-medium">{t('impact.waterUsed')}</th>
                  <th className="py-2 text-end font-medium">{t('impact.waterSaved')}</th>
                  <th className="py-2 text-end font-medium">{t('impact.alerts')}</th>
                  <th className="py-2 text-end font-medium">{t('impact.tasksDone')}</th>
                  <th className="py-2 text-end font-medium">{t('impact.verified')}</th>
                  <th className="py-2 text-end font-medium">{t('impact.medianResolve')}</th>
                  <th className="py-2 text-end font-medium">NDVI</th>
                </tr>
              </thead>
              <tbody>
                {data.parks.map((p) => (
                  <tr key={p.id} className="border-b last:border-0">
                    <td className="py-2">
                      <Link to={`/admin/parks/${p.id}`} className="flex items-center gap-2 hover:underline">
                        {parkName(p, i18n.language)}
                        <SourceBadge source={p.source} className="px-1 py-0 text-[0.6rem]" />
                      </Link>
                    </td>
                    <td className="py-2 text-end tabular-nums">{p.water.parks_measured ? pumpMinutes(t, p.water.pump_min) : '—'}</td>
                    <td className="py-2 text-end tabular-nums" title={p.water.parks_measured ? `${litres(p.water.used_l)} / ${litres(p.water.baseline_l)}` : undefined}>
                      {waterSavedCell(t, p.water)}
                    </td>
                    <td className="py-2 text-end tabular-nums">{p.alerts}</td>
                    <td className="py-2 text-end tabular-nums">{p.tasks.done}</td>
                    <td className="py-2 text-end tabular-nums">{verifiedText(p.tasks)}</td>
                    <td className="py-2 text-end tabular-nums">{p.tasks.median_resolve_min == null ? '—' : t('impact.minutes', { count: p.tasks.median_resolve_min })}</td>
                    <td className="py-2 text-end tabular-nums">
                      {p.ndvi?.too_small ? (
                        <span className="text-xs text-muted-foreground">{t('ndvi.tooSmall')}</span>
                      ) : p.ndvi && p.ndvi.mean != null ? (
                        <span className="inline-flex items-center gap-1.5">
                          <span className="size-2.5 rounded-full" style={{ backgroundColor: ndviColor(p.ndvi.mean) }} />
                          {formatNumber(p.ndvi.mean, 2)}
                        </span>
                      ) : (
                        '—'
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>
      )}
    </div>
  )
}
