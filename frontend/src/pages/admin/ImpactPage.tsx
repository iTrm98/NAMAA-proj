import { Activity } from 'lucide-react'
import { motion } from 'motion/react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Chip, EmptyState, GlassCard, Segmented, Skeleton } from '@/components/ds'
import { litres, pct, pumpMinutes, waterMethod, waterNeeds, waterSavedCell } from '@/components/impact'
import { useImpact } from '@/hooks/useImpact'
import { parkName, useParks } from '@/hooks/useParks'
import { formatNumber } from '@/lib/format'
import { ndviColor } from '@/lib/ndvi'
import type { Priority } from '@/types'

const PRIORITIES: Priority[] = ['urgent', 'high', 'normal', 'low']
const PRIORITY_COLOR: Record<Priority, string> = { urgent: 'var(--urgent)', high: 'var(--water)', normal: 'var(--info)', low: 'var(--text-muted)' }
const ROWS = ['finding', 'watering', 'proof', 'green', 'litter'] as const

/** /admin/impact: what changed in the company's parks. Every number comes from GET /admin/impact. */
export default function ImpactPage() {
  const { t, i18n } = useTranslation()
  const [days, setDays] = useState(30)
  const impact = useImpact(days)
  const parks = useParks()
  const data = impact.data

  return (
    <div className="flex min-w-0 flex-col gap-5 pb-8">
      <section className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex flex-col gap-1.5">
          <h1 className="m-0 text-[40px] leading-[1.05] font-light tracking-[-.01em]">{t('impact.title')}</h1>
          <span className="text-sm text-text-2">{t('impactPage.subtitle')}</span>
        </div>
        <Segmented
          options={[7, 30].map((d) => ({ value: String(d), label: t('impact.lastDays', { count: d }) }))}
          value={String(days)}
          onChange={(v) => setDays(Number(v))}
          ariaLabel={t('impact.period')}
        />
      </section>

      {impact.isPending && <ImpactSkeleton />}
      {impact.isError && !data && (
        <EmptyState
          icon={<Activity />}
          title={t('common.error')}
          action={
            <button type="button" className="h-10 rounded-full border border-[var(--glass-border-strong)] px-5 text-sm" onClick={() => void impact.refetch()}>
              {t('common.retry')}
            </button>
          }
        />
      )}

      {data && (
        <>
          <Headlines data={data} />

          <GlassCard as="section" className="flex flex-col gap-4">
            <h2 className="m-0 text-[17px] font-medium">{t('impactPage.compareTitle')}</h2>
            <div className="grid grid-cols-[minmax(110px,1fr)_2fr_2fr] gap-x-5 gap-y-3 text-[13px] max-md:grid-cols-1">
              <span className="max-md:hidden" />
              <span className="text-xs tracking-wide text-text-2 uppercase max-md:hidden">{t('impactPage.before')}</span>
              <span className="text-xs tracking-wide text-[var(--accent-lime)] uppercase max-md:hidden">{t('impactPage.withNamaa')}</span>
              {ROWS.map((r) => (
                <div key={r} className="contents max-md:flex max-md:flex-col max-md:gap-1 max-md:border-t max-md:border-[var(--glass-border)] max-md:pt-3">
                  <span className="font-medium">{t(`impactPage.rows.${r}.label`)}</span>
                  <span className="text-text-2">{t(`impactPage.rows.${r}.before`)}</span>
                  <span>{t(`impactPage.rows.${r}.now`)}</span>
                </div>
              ))}
            </div>
          </GlassCard>

          <section className="grid grid-cols-[minmax(280px,1fr)_2fr] gap-5 max-lg:grid-cols-1">
            <GlassCard className="flex flex-col gap-4">
              <h2 className="m-0 text-[17px] font-medium">{t('impactPage.priorityTitle')}</h2>
              {data.alerts.total === 0 ? (
                <span className="text-sm text-text-2">{t('overview.noAlerts30')}</span>
              ) : (
                (() => {
                  const max = Math.max(1, ...PRIORITIES.map((p) => data.alerts.by_priority[p] ?? 0))
                  return PRIORITIES.map((p) => {
                    const n = data.alerts.by_priority[p] ?? 0
                    return (
                      <div key={p}>
                        <div className="mb-1.5 flex justify-between text-[13px]">
                          <span>
                            {t(`priority.${p}`)} <span className="text-text-2">· {t(`impactPage.within.${p}`)}</span>
                          </span>
                          <span className="tabular-nums">{formatNumber(n, 0)}</span>
                        </div>
                        <div className="h-2 overflow-hidden rounded-full bg-white/[.08]">
                          <motion.div
                            key={`${p}-${days}`}
                            className="h-full origin-left rounded-full rtl:origin-right"
                            style={{ background: PRIORITY_COLOR[p], width: `${(n / max) * 100}%` }}
                            initial={{ scaleX: 0 }}
                            animate={{ scaleX: 1 }}
                            transition={{ duration: 0.4, ease: 'easeOut' }}
                          />
                        </div>
                      </div>
                    )
                  })
                })()
              )}
              <span className="text-xs text-text-2">{t('impactPage.overdueNote', { overdue: data.tasks.overdue, escalated: data.tasks.escalated })}</span>
            </GlassCard>

            <GlassCard className="flex min-w-0 flex-col gap-3">
              <h2 className="m-0 text-[17px] font-medium">{t('impact.perPark')}</h2>
              {data.parks.length === 0 ? (
                <EmptyState title={t('impactPage.noParks')} />
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[620px] text-[13px]">
                    <thead className="text-xs text-text-2">
                      <tr className="border-b border-[var(--glass-border)]">
                        <th className="py-2 text-start font-normal">{t('impact.park')}</th>
                        <th className="py-2 text-end font-normal">{t('impact.alerts')}</th>
                        <th className="py-2 text-end font-normal">{t('impact.tasksDone')}</th>
                        <th className="py-2 text-end font-normal">{t('impact.verified')}</th>
                        <th className="py-2 text-end font-normal">{t('impactPage.medianFix')}</th>
                        {data.parks.some((p) => p.water.enough_data) && <th className="py-2 text-end font-normal">{t('impact.waterSaved')}</th>}
                        <th className="py-2 text-end font-normal">{t('impactPage.greenCover')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.parks.map((p) => {
                        const awaiting = parks.data?.find((x) => x.id === p.id)?.status === 'awaiting_install'
                        const cols = data.parks.some((x) => x.water.enough_data) ? 4 : 3
                        return (
                          <tr key={p.id} className="border-b border-[var(--glass-border)] last:border-0">
                            <td className="py-2.5">
                              <Link to={`/admin/parks/${p.id}`} className="flex items-center gap-2 hover:underline">
                                {parkName(p, i18n.language)}
                                <Chip tone={p.source === 'real' ? 'muted' : 'normal'}>{t(`source.${p.source}`)}</Chip>
                              </Link>
                            </td>
                            {awaiting ? (
                              <td colSpan={cols + 1} className="py-2.5 text-end text-text-2">
                                {t('impactPage.awaiting')}
                              </td>
                            ) : (
                              <>
                                <td className="py-2.5 text-end tabular-nums">{p.alerts}</td>
                                <td className="py-2.5 text-end tabular-nums">{p.tasks.done}</td>
                                <td className="py-2.5 text-end tabular-nums">{p.tasks.verified_rate == null ? '—' : pct(p.tasks.verified_rate * 100)}</td>
                                <td className="py-2.5 text-end tabular-nums">{p.tasks.median_resolve_min == null ? '—' : t('impact.minutes', { count: p.tasks.median_resolve_min })}</td>
                                {data.parks.some((x) => x.water.enough_data) && (
                                  <td className="py-2.5 text-end tabular-nums" title={p.water.parks_measured ? `${pumpMinutes(t, p.water.pump_min)} · ${litres(p.water.used_l)}` : undefined}>
                                    {waterSavedCell(t, p.water)}
                                  </td>
                                )}
                              </>
                            )}
                            <td className="py-2.5 text-end tabular-nums">
                              {p.ndvi?.too_small ? (
                                <span className="text-xs text-text-2">{t('impactPage.tooSmall')}</span>
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
                        )
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </GlassCard>
          </section>

          <p className="m-0 text-xs text-text-2">
            {t('impact.sourceNote', { real: data.source_mix.real, simulated: data.source_mix.simulated })} {t('impactPage.footer')}
          </p>
        </>
      )}
    </div>
  )
}

function Headlines({ data }: { data: NonNullable<ReturnType<typeof useImpact>['data']> }) {
  const { t } = useTranslation()
  const { tasks, water } = data
  const decided = tasks.verified + tasks.not_verified

  return (
    <section className="grid grid-cols-3 gap-5 max-lg:grid-cols-1">
      <GlassCard className="flex flex-col gap-2">
        <span className="text-[13px] text-text-2">{t('impactPage.verifiedTitle')}</span>
        {decided === 0 ? (
          <b className="text-[22px] font-light text-text-2">{t('impactPage.noneDecided')}</b>
        ) : (
          <div className="flex items-baseline gap-3">
            <b className="text-[44px] leading-none font-light tabular-nums">{pct(tasks.verified_rate == null ? null : tasks.verified_rate * 100)}</b>
            <span className="text-[13px] text-text-2">{t('impactPage.verifiedOf', { verified: tasks.verified, total: decided })}</span>
          </div>
        )}
        <span className="text-xs leading-relaxed text-text-2">{t('impactPage.verifiedBody')}</span>
      </GlassCard>

      <GlassCard className="flex flex-col gap-2">
        <span className="text-[13px] text-text-2">{t('impactPage.medianTitle')}</span>
        {tasks.median_resolve_min == null ? (
          <b className="text-[22px] font-light text-text-2">{t('impactPage.noneDecided')}</b>
        ) : (
          <b className="text-[44px] leading-none font-light tabular-nums">{t('impact.minutes', { count: tasks.median_resolve_min })}</b>
        )}
        <span className="text-xs leading-relaxed text-text-2">{t('impactPage.medianBody')}</span>
      </GlassCard>

      <GlassCard className="flex flex-col gap-2" title={waterMethod(t, water)}>
        <span className="text-[13px] text-text-2">{t('impactPage.waterTitle')}</span>
        {water.enough_data && water.saved_pct != null ? (
          <b className="text-[44px] leading-none font-light tabular-nums">{pct(water.saved_pct)}</b>
        ) : (
          <b className="text-[22px] font-light text-text-2">{t('impactPage.collecting')}</b>
        )}
        <span className="text-xs leading-relaxed text-text-2">
          {t('impactPage.waterBody', { sessions: water.schedule.sessions_per_day, minutes: water.schedule.minutes_per_session, hours: water.min_measured_hours })}
          {!water.enough_data && waterNeeds(t, water) ? ` ${waterNeeds(t, water)}.` : ''}
        </span>
      </GlassCard>
    </section>
  )
}

function ImpactSkeleton() {
  return (
    <div className="flex flex-col gap-5" aria-busy="true">
      <div className="grid grid-cols-3 gap-5 max-lg:grid-cols-1">
        {[0, 1, 2].map((i) => (
          <Skeleton key={i} h={150} radius={26} />
        ))}
      </div>
      <Skeleton h={260} radius={26} />
      <Skeleton h={300} radius={26} />
    </div>
  )
}
