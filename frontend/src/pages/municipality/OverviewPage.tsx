import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { DaysSwitch, ImpactKpis, pumpMinutes, SourceNote, verifiedText, waterSavedCell } from '@/components/impact'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useImpact } from '@/hooks/useImpact'
import ParkMap from '@/pages/admin/ParkMap'

/** /municipality: KPI cards, a row per company, then the map (status / NDVI) with every company's open alerts. */
export default function OverviewPage() {
  const { t } = useTranslation()
  const [days, setDays] = useState(30)
  const impact = useImpact(days)
  const data = impact.data

  return (
    <div className="flex flex-col">
      <div className="flex flex-col gap-4 p-4 lg:p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold">{t('municipality.title')}</h1>
            <p className="text-sm text-muted-foreground">{t('municipality.intro')}</p>
          </div>
          <DaysSwitch days={days} onChange={setDays} />
        </div>
        {impact.isError && <p className="text-sm text-destructive">{t('common.error')}</p>}
        <ImpactKpis impact={data} />
        {data && <SourceNote impact={data} />}

        {data?.by_company && (
          <Card>
            <CardHeader>
              <CardTitle>{t('municipality.byCompany')}</CardTitle>
            </CardHeader>
            <CardContent className="overflow-x-auto">
              <table className="w-full min-w-[720px] text-sm">
                <thead className="text-xs text-muted-foreground">
                  <tr className="border-b">
                    <th className="py-2 text-start font-medium">{t('municipality.company')}</th>
                    <th className="py-2 text-end font-medium">{t('municipality.parks')}</th>
                    <th className="py-2 text-end font-medium">{t('impact.alerts')}</th>
                    <th className="py-2 text-end font-medium">{t('priority.urgent')}</th>
                    <th className="py-2 text-end font-medium">{t('impact.tasksDone')}</th>
                    <th className="py-2 text-end font-medium">{t('impact.verified')}</th>
                    <th className="py-2 text-end font-medium">{t('impact.medianResolve')}</th>
                    <th className="py-2 text-end font-medium">{t('impact.overdue')}</th>
                    <th className="py-2 text-end font-medium">{t('impact.waterUsed')}</th>
                    <th className="py-2 text-end font-medium">{t('impact.waterSaved')}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.by_company.map((c) => (
                    <tr key={c.company.id} className="border-b last:border-0">
                      <td className="py-2 font-medium">{c.company.name}</td>
                      <td className="py-2 text-end tabular-nums" title={t('impact.sourceNote', { real: c.source_mix.real, simulated: c.source_mix.simulated })}>
                        {c.parks}
                        {c.source_mix.simulated > 0 && <span className="text-xs text-muted-foreground"> ({t('municipality.simulatedShort', { count: c.source_mix.simulated })})</span>}
                      </td>
                      <td className="py-2 text-end tabular-nums">{c.alerts}</td>
                      <td className="py-2 text-end tabular-nums">{c.alerts_urgent}</td>
                      <td className="py-2 text-end tabular-nums">{c.tasks.done}</td>
                      <td className="py-2 text-end tabular-nums">{verifiedText(c.tasks)}</td>
                      <td className="py-2 text-end tabular-nums">{c.tasks.median_resolve_min == null ? '—' : t('impact.minutes', { count: c.tasks.median_resolve_min })}</td>
                      <td className="py-2 text-end tabular-nums">{c.tasks.overdue}</td>
                      <td className="py-2 text-end tabular-nums">{c.water.parks_measured ? pumpMinutes(t, c.water.pump_min) : '—'}</td>
                      <td className="py-2 text-end tabular-nums">{waterSavedCell(t, c.water)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}
      </div>
      <div className="h-[640px] border-t">
        <ParkMap />
      </div>
    </div>
  )
}
