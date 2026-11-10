import { TriangleAlert } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { DataSourceInfo } from '@/types'

/**
 * "Outline: traced on satellite imagery by Turki, 10 Nov 2026 · Area: from the outline"
 * (Sprint 3.1, D1). An approximate (not verified) outline or area is shown in amber.
 */
export function DataSources({ sources }: { sources: { outline: DataSourceInfo; area: DataSourceInfo } }) {
  const { t } = useTranslation()

  const describe = (field: 'outline' | 'area', s: DataSourceInfo) => {
    const parts = [t(field === 'area' && s.type !== 'approximate' ? 'sources.areaFromOutline' : `sources.type.${s.type}`)]
    if (field === 'outline' && (s.type === 'official' || s.type === 'osm') && s.reference) parts[0] += ` (${s.reference})`
    if (s.by) parts[0] += ` ${t('sources.by', { name: s.by.name })}`
    if (s.at && s.type !== 'approximate') parts.push(formatDate(s.at))
    return parts.join(', ')
  }

  const items = (['outline', 'area'] as const).map((field) => ({ field, s: sources[field] }))
  return (
    <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
      <span className="font-medium">{t('sources.title')}:</span>
      {items.map(({ field, s }) => (
        <span key={field} className={cn('inline-flex items-center gap-1', s.type === 'approximate' && 'text-amber-600 dark:text-amber-400')}>
          {s.type === 'approximate' && <TriangleAlert className="size-3.5" />}
          {t(`sources.${field}`)}: {describe(field, s)}
        </span>
      ))}
    </p>
  )
}
