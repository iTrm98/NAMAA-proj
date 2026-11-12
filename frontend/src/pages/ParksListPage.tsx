import { Droplets, Leaf, MapPinned, Search, Wifi } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Chip, EmptyState, GlassCard, Kpi, ParkCard, Reveal, Segmented, Skeleton } from '@/components/ds'
import { StatusPill } from '@/components/StatusPill'
import { parkName, useParks } from '@/hooks/useParks'
import { formatAgo, formatNumber } from '@/lib/format'
import { useBasePath } from '@/lib/scope'
import { cn } from '@/lib/utils'
import type { Park } from '@/types'

type Filter = 'all' | 'attention' | 'real' | 'simulated'
type View = 'grid' | 'table'

const needsAttention = (p: Park) => p.status === 'dry' || p.status === 'over' || p.status === 'offline' || p.open_alerts_count > 0

/** /admin/parks and /municipality/parks: search, filter pills, Grid/Table (shared by both scopes). */
export function ParksListPage() {
  const { t, i18n } = useTranslation()
  const parks = useParks()
  const base = useBasePath()
  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState<Filter>('all')
  const [view, setView] = useState<View>('grid')

  const list = parks.data ?? []
  const shown = useMemo(() => {
    const q = query.trim().toLowerCase()
    return list.filter((p) => {
      if (filter === 'attention' && !needsAttention(p)) return false
      if (filter === 'real' && p.source !== 'real') return false
      if (filter === 'simulated' && p.source !== 'simulated') return false
      if (!q) return true
      return [p.name_ar, p.name_en, p.district ?? ''].some((v) => v.toLowerCase().includes(q))
    })
  }, [list, query, filter])

  const realCount = list.filter((p) => p.source === 'real').length
  const attention = list.filter(needsAttention).length
  const awaiting = list.filter((p) => p.status === 'awaiting_install').length
  const filters: { value: Filter; label: string }[] = [
    { value: 'all', label: t('parksPage.filterAll') },
    { value: 'attention', label: t('parksPage.filterAttention') },
    { value: 'real', label: t('parksPage.filterReal') },
    { value: 'simulated', label: t('parksPage.filterSimulated') },
  ]

  return (
    <div className="flex flex-col gap-5 p-1">
      <section className="flex flex-wrap items-center gap-7">
        <h1 className="m-0 text-[40px] leading-[1.05] font-light tracking-[-.01em]">{t('parksList.title')}</h1>
        {parks.data && (
          <>
            <Kpi icon={<MapPinned />} value={list.length} label={t('parksPage.kpiTotal')} badge={realCount > 0 ? { tone: 'lime', children: t('parksPage.kpiReal', { n: realCount }) } : undefined} />
            <Kpi icon={<Droplets />} value={attention} label={t('parksPage.kpiAttention')} />
            {awaiting > 0 && <Kpi icon={<Wifi />} value={awaiting} label={t('parksPage.kpiAwaiting')} />}
          </>
        )}
      </section>

      <section className="flex flex-wrap items-center gap-2.5">
        <label className="flex h-10 flex-[0_1_320px] items-center gap-2 rounded-full border border-[var(--glass-border)] bg-[var(--glass-bg-strong)] px-3.5 text-[13px] text-text-2">
          <Search className="size-4 shrink-0" aria-hidden />
          <span className="sr-only">{t('parksPage.searchLabel')}</span>
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t('parksPage.search')}
            className="min-w-0 flex-1 border-none bg-transparent text-[13px] text-[var(--text)] outline-none placeholder:text-text-2"
          />
        </label>
        <Segmented options={filters} value={filter} onChange={setFilter} ariaLabel={t('parksPage.filterLabel')} />
        <div className="flex-1" />
        <Segmented
          options={[
            { value: 'grid', label: t('parksPage.grid') },
            { value: 'table', label: t('parksPage.table') },
          ]}
          value={view}
          onChange={setView}
          ariaLabel={t('parksPage.viewLabel')}
        />
      </section>

      {parks.isPending && (
        <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))' }}>
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} h={230} radius={24} />
          ))}
        </div>
      )}
      {parks.isError && (
        <EmptyState
          title={t('common.error')}
          action={
            <button type="button" className="h-10 rounded-full border border-[var(--glass-border-strong)] px-[18px] text-[13px]" onClick={() => parks.refetch()}>
              {t('common.retry')}
            </button>
          }
        />
      )}
      {parks.data?.length === 0 && <EmptyState icon={<MapPinned />} title={t('parksList.empty')} />}
      {parks.data && list.length > 0 && shown.length === 0 && <EmptyState icon={<Search />} title={t('parksPage.noMatch')} />}
      {shown.length > 0 && view === 'grid' && (
        <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))' }}>
          {shown.map((p, i) => (
            <Reveal key={p.id} index={i} className="min-w-0">
              <ParkCard park={p} to={`${base}/parks/${p.id}`} className="h-full" />
            </Reveal>
          ))}
        </div>
      )}
      {shown.length > 0 && view === 'table' && <ParksTable parks={shown} base={base} language={i18n.language} />}
    </div>
  )
}

/** Table view: a column that no row fills is not rendered (no "—" columns). */
function ParksTable({ parks, base, language }: { parks: Park[]; base: string; language: string }) {
  const { t } = useTranslation()
  const hasDistrict = parks.some((p) => p.district)
  const hasMoisture = parks.some((p) => p.latest?.moisture_pct != null)
  const hasReading = parks.some((p) => p.last_reading_at)
  const hasCover = parks.some((p) => p.ndvi)
  const hasAlerts = parks.some((p) => p.open_alerts_count > 0)
  const th = 'px-4 py-3 text-start text-xs font-medium text-text-2'
  return (
    <GlassCard radius="sm" padding={0} className="overflow-x-auto">
      <table className="w-full min-w-[640px] border-collapse text-[13px]">
        <thead>
          <tr className="border-b border-[var(--glass-border)]">
            <th className={th}>{t('parksPage.colPark')}</th>
            {hasDistrict && <th className={th}>{t('parksPage.colDistrict')}</th>}
            <th className={th}>{t('parksPage.colStatus')}</th>
            {hasMoisture && <th className={th}>{t('parksPage.colMoisture')}</th>}
            {hasReading && <th className={th}>{t('parksPage.colLastReading')}</th>}
            {hasCover && <th className={th}>{t('parksPage.colCover')}</th>}
            <th className={th}>{t('parksPage.colKind')}</th>
            {hasAlerts && <th className={th}>{t('parksPage.colAlerts')}</th>}
          </tr>
        </thead>
        <tbody>
          {parks.map((p) => (
            <tr key={p.id} className="border-b border-[var(--glass-border)] last:border-0 hover:bg-white/[.04]">
              <td className="px-4 py-3">
                <Link to={`${base}/parks/${p.id}`} className="font-medium text-[var(--text)] no-underline hover:text-[var(--accent-lime)]">
                  {parkName(p, language)}
                </Link>
              </td>
              {hasDistrict && <td className="px-4 py-3 text-text-2">{p.district}</td>}
              <td className="px-4 py-3">
                <StatusPill status={p.status} />
              </td>
              {hasMoisture && <td className="px-4 py-3 tabular-nums">{p.latest?.moisture_pct != null && `${formatNumber(p.latest.moisture_pct)}%`}</td>}
              {hasReading && <td className="px-4 py-3 text-text-2">{p.last_reading_at && formatAgo(p.last_reading_at)}</td>}
              {hasCover && (
                <td className={cn('px-4 py-3 tabular-nums', p.ndvi?.too_small && 'text-text-2')}>
                  {p.ndvi && (p.ndvi.too_small || p.ndvi.mean == null ? (
                    t('parkCard.tooSmall')
                  ) : (
                    <span className="inline-flex items-center gap-1.5">
                      <Leaf className="size-3.5 text-[var(--healthy)]" aria-hidden />
                      {formatNumber(p.ndvi.mean, 2)}
                    </span>
                  ))}
                </td>
              )}
              <td className="px-4 py-3">
                <Chip tone={p.source === 'simulated' ? 'high' : 'lime'}>{t(`source.${p.source}`)}</Chip>
              </td>
              {hasAlerts && <td className="px-4 py-3">{p.open_alerts_count > 0 && <Chip tone="high">{t('admin.openAlerts', { count: p.open_alerts_count })}</Chip>}</td>}
            </tr>
          ))}
        </tbody>
      </table>
    </GlassCard>
  )
}

export default ParksListPage
