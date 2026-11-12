import { Bell, Building2, MapPinned, Satellite, ShieldCheck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { EmptyState, GlassCard, Kpi, Skeleton } from '@/components/ds'
import type { ChipTone } from '@/components/ds'
import { OverviewMap } from '@/components/OverviewMap'
import { useImpact } from '@/hooks/useImpact'
import { useOverview } from '@/hooks/useOverview'
import { useParks } from '@/hooks/useParks'
import { formatNumber } from '@/lib/format'
import type { Priority } from '@/types'

const PRIORITIES: Priority[] = ['urgent', 'high', 'normal', 'low']
const PRIORITY_TONE: Record<Priority, ChipTone> = { urgent: 'urgent', high: 'high', normal: 'normal', low: 'muted' }

/** /municipality: the city view. KPIs, one map with every operator's parks, operators compared, contract response times (read-only). */
export default function OverviewPage() {
  const { t } = useTranslation()
  const overview = useOverview()
  const parks = useParks()
  const impact = useImpact(30)

  if (overview.isPending || parks.isPending) return <CitySkeleton />
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
  const companies = o.by_company ?? []
  const top = PRIORITIES.find((p) => o.kpis.open_alerts.by_priority[p] > 0)
  const watched = parks.data.filter((p) => p.ndvi && !p.ndvi.too_small && p.ndvi.mean != null).length
  const impactBy = (id: number) => impact.data?.by_company?.find((c) => c.company.id === id)

  return (
    <div className="flex min-w-0 flex-col gap-5 pb-8">
      <section className="flex flex-wrap items-center gap-x-7 gap-y-4">
        <h1 className="m-0 text-[40px] leading-[1.05] font-light tracking-[-.01em]">{t('city.title')}</h1>
        <Kpi icon={<MapPinned />} value={o.kpis.parks.total} label={t('city.parksOnNamaa')} badge={{ tone: 'muted', children: t('city.companiesCount', { count: companies.length }) }} />
        <Kpi
          icon={<Bell />}
          value={o.kpis.open_alerts.total}
          label={t('city.openAlerts')}
          badge={top ? { tone: PRIORITY_TONE[top], children: `${o.kpis.open_alerts.by_priority[top]} ${t(`priority.${top}`)}` } : undefined}
        />
        <Kpi
          icon={<ShieldCheck />}
          value={o.kpis.fixes.verified_pct == null ? t('overview.notEnough') : o.kpis.fixes.verified_pct}
          suffix={o.kpis.fixes.verified_pct == null ? undefined : '%'}
          label={t('city.verifiedFixes')}
        />
        <Kpi icon={<Satellite />} value={t('city.ofTotal', { done: formatNumber(watched, 0), total: formatNumber(parks.data.length, 0) })} label={t('city.satellite')} />
      </section>

      <section className="flex flex-wrap gap-5">
        <OverviewMap parks={parks.data} />
        <GlassCard as="section" className="flex min-w-0 flex-[1_1_380px] flex-col gap-3">
          <div className="flex flex-col gap-0.5">
            <h2 className="m-0 text-[17px] font-medium">{t('city.compared')}</h2>
            <span className="text-xs text-text-2">{t('city.comparedHint')}</span>
          </div>
          {companies.length === 0 ? (
            <EmptyState icon={<Building2 />} title={t('companies.empty')} />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[420px] text-[13px]">
                <thead className="text-xs text-text-2">
                  <tr className="border-b border-[var(--glass-border)]">
                    <th className="py-2 text-start font-normal">{t('municipality.company')}</th>
                    <th className="py-2 text-end font-normal">{t('municipality.parks')}</th>
                    <th className="py-2 text-end font-normal">{t('city.openAlertsShort')}</th>
                    <th className="py-2 text-end font-normal">{t('impact.overdue')}</th>
                    <th className="py-2 text-end font-normal">{t('impact.verified')}</th>
                    <th className="py-2 text-end font-normal">{t('impactPage.medianFix')}</th>
                  </tr>
                </thead>
                <tbody>
                  {companies.map((c) => {
                    const i = impactBy(c.company.id)
                    const decided = i ? i.tasks.verified + i.tasks.not_verified : 0
                    return (
                      <tr key={c.company.id} className="border-b border-[var(--glass-border)] last:border-0">
                        <td className="py-2.5 font-medium">{c.company.name}</td>
                        <td className="py-2.5 text-end tabular-nums">{c.parks}</td>
                        <td className="py-2.5 text-end tabular-nums">{c.open_alerts}</td>
                        <td className="py-2.5 text-end tabular-nums">{i ? i.tasks.overdue : '—'}</td>
                        {!i || decided === 0 ? (
                          <td colSpan={2} className="py-2.5 text-end text-text-2">
                            {impact.isPending ? '…' : t('city.noTasks')}
                          </td>
                        ) : (
                          <>
                            <td className="py-2.5 text-end tabular-nums">{i.tasks.verified_rate == null ? '—' : `${formatNumber(i.tasks.verified_rate * 100, 0)} %`}</td>
                            <td className="py-2.5 text-end tabular-nums">{i.tasks.median_resolve_min == null ? '—' : t('impact.minutes', { count: i.tasks.median_resolve_min })}</td>
                          </>
                        )}
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
          <div className="flex flex-col gap-1 rounded-2xl bg-white/[.04] p-3.5">
            <span className="text-[13px] font-medium">{t('city.contractTitle')}</span>
            <span className="text-xs leading-relaxed text-text-2">
              {PRIORITIES.map((p) => `${t(`priority.${p}`)} ${t(`impactPage.within.${p}`)}`).join(' · ')}. {t('city.contractBody')}
            </span>
          </div>
        </GlassCard>
      </section>

      <p className="m-0 text-xs text-text-2">{t('city.footer')}</p>
    </div>
  )
}

function CitySkeleton() {
  return (
    <div className="flex flex-col gap-5" aria-busy="true">
      <div className="flex flex-wrap gap-6">
        <Skeleton w={220} h={84} radius={14} />
        {[0, 1, 2, 3].map((i) => (
          <Skeleton key={i} w={170} h={48} radius={24} />
        ))}
      </div>
      <div className="flex flex-wrap gap-5">
        <Skeleton h={500} radius={26} className="min-w-0 flex-[2_1_620px]" />
        <Skeleton h={500} radius={26} className="min-w-0 flex-[1_1_380px]" />
      </div>
    </div>
  )
}
