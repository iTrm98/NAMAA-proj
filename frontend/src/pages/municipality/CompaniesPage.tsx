import { Building2 } from 'lucide-react'
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Chip, EmptyState, GlassCard, Skeleton } from '@/components/ds'
import { useParks } from '@/hooks/useParks'

/** /municipality/companies: the companies behind the parks, with park and alert counts (read-only). */
export default function CompaniesPage() {
  const { t } = useTranslation()
  const parks = useParks()
  const companies = useMemo(() => {
    const map = new Map<string, { name: string; parks: number; alerts: number }>()
    for (const p of parks.data ?? []) {
      const name = p.company?.name ?? '—'
      const row = map.get(name) ?? { name, parks: 0, alerts: 0 }
      row.parks += 1
      row.alerts += p.open_alerts_count
      map.set(name, row)
    }
    return [...map.values()]
  }, [parks.data])

  return (
    <div className="flex flex-col gap-5 p-1">
      <h1 className="m-0 text-[40px] leading-[1.05] font-light tracking-[-.01em]">{t('companies.title')}</h1>
      {parks.isPending && (
        <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))' }}>
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} h={110} radius={26} />
          ))}
        </div>
      )}
      {parks.isError && <EmptyState title={t('common.error')} />}
      {parks.data && companies.length === 0 && <EmptyState icon={<Building2 />} title={t('companies.empty')} />}
      <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))' }}>
        {companies.map((c) => (
          <GlassCard key={c.name} as="article" padding={18} className="flex flex-col gap-3">
            <h2 className="m-0 text-[17px] font-medium">{c.name}</h2>
            <div className="flex flex-wrap gap-1.5">
              <Chip tone="muted">{t('companies.parkCount', { count: c.parks })}</Chip>
              {c.alerts > 0 && <Chip tone="high">{t('admin.openAlerts', { count: c.alerts })}</Chip>}
            </div>
          </GlassCard>
        ))}
      </div>
    </div>
  )
}
