import { ArrowRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { parkName } from '@/hooks/useParks'
import { formatAgo, formatNumber } from '@/lib/format'
import { ndviColor } from '@/lib/ndvi'
import { cn } from '@/lib/utils'
import type { Park, ParkStatus } from '@/types'
import { Chip } from './Chip'
import { GlassCard } from './GlassCard'

const DOT: Record<ParkStatus, string> = {
  ok: 'bg-[var(--healthy)]',
  dry: 'bg-[var(--water)]',
  over: 'bg-[var(--info)]',
  offline: 'bg-[var(--awaiting)]',
  awaiting_install: 'border-[1.5px] border-[var(--awaiting)] bg-transparent',
  no_sensor: 'bg-[#D9D6CB]',
}

export interface ParkCardProps {
  park: Park
  /** Where "Open" goes (default /admin/parks/:id). */
  to?: string
  className?: string
}

/** Parks grid card: status dot, source chip, moisture, last reading, open alerts, open action. */
export function ParkCard({ park, to, className }: ParkCardProps) {
  const { t, i18n } = useTranslation()
  const awaiting = park.status === 'awaiting_install'
  return (
    <GlassCard as="article" radius="sm" padding={18} className={cn('flex min-w-0 flex-col gap-3.5', className)}>
      <div className="flex items-center justify-between gap-2">
        <div className="flex min-w-0 items-center gap-2.5">
          <span className={cn('size-3 shrink-0 rounded-full', DOT[park.status])} aria-hidden />
          <h2 className="m-0 truncate text-[17px] font-medium">{parkName(park, i18n.language)}</h2>
        </div>
        <Chip tone={park.source === 'simulated' ? 'high' : 'lime'}>{t(`source.${park.source}`)}</Chip>
      </div>
      <p className="m-0 text-xs text-text-2">
        {[awaiting ? t('parkCard.awaiting') : t(`status.${park.status}`), park.district].filter(Boolean).join(' · ')}
      </p>
      {park.status !== 'no_sensor' && (!awaiting || park.latest?.moisture_pct != null) && (
      <div className="grid grid-cols-2 gap-2.5 rounded-2xl bg-white/[.06] px-3.5 py-3">
        <div>
          <div className="text-xs text-text-2">{t('parkCard.moisture')}</div>
          <div className="text-lg font-medium tabular-nums">{park.latest?.moisture_pct != null ? `${formatNumber(park.latest.moisture_pct)}%` : '—'}</div>
        </div>
        <div>
          <div className="text-xs text-text-2">{t('parkCard.lastReading')}</div>
          <div className="text-[13px]">{formatAgo(park.last_reading_at)}</div>
        </div>
      </div>
      )}
      <GreenCover ndvi={park.ndvi} />
      <div className="flex items-center justify-between gap-2">
        {park.open_alerts_count > 0 ? <Chip tone="high">{t('admin.openAlerts', { count: park.open_alerts_count })}</Chip> : <span />}
        <Link to={to ?? `/admin/parks/${park.id}`} className="inline-flex items-center gap-1.5 text-[13px] text-[var(--accent-lime)] no-underline hover:text-[#e4ffa6]">
          {t('parkCard.open')}
          <ArrowRight className="size-4 rtl:-scale-x-100" />
        </Link>
      </div>
    </GlassCard>
  )
}

/** Satellite green cover: a bar and the value, or "Too small to measure"; nothing without a satellite value. */
function GreenCover({ ndvi }: { ndvi: Park['ndvi'] }) {
  const { t } = useTranslation()
  if (!ndvi) return null
  const measured = !ndvi.too_small && ndvi.mean != null
  return (
    <div className="flex flex-col gap-1.5">
      <div className="flex items-center justify-between text-xs text-text-2">
        <span>{t('parkCard.greenCover')}</span>
        <span className="tabular-nums text-[var(--text)]">{measured ? formatNumber(ndvi.mean, 2) : t('parkCard.tooSmall')}</span>
      </div>
      {measured && (
        <div className="h-1.5 overflow-hidden rounded-full bg-white/10" aria-hidden>
          <div className="h-full origin-left rounded-full rtl:origin-right" style={{ transform: `scaleX(${Math.min(1, Math.max(0.03, (ndvi.mean ?? 0) / 0.8))})`, background: ndviColor(ndvi.mean) }} />
        </div>
      )}
    </div>
  )
}
