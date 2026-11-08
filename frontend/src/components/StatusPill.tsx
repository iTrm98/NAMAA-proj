import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import type { ParkStatus, Source } from '@/types'

/** Map/pill colours: ok green, dry orange, over blue, offline grey. */
export const STATUS_COLORS: Record<ParkStatus, string> = {
  ok: '#22c55e',
  dry: '#f97316',
  over: '#3b82f6',
  offline: '#9ca3af',
}

const PILL: Record<ParkStatus, string> = {
  ok: 'bg-status-ok/15 text-status-ok ring-status-ok/30',
  dry: 'bg-status-dry/15 text-status-dry ring-status-dry/30',
  over: 'bg-status-over/15 text-status-over ring-status-over/30',
  offline: 'bg-status-offline/15 text-status-offline ring-status-offline/30',
}

export function StatusPill({ status, className }: { status: ParkStatus; className?: string }) {
  const { t } = useTranslation()

  return (
    <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', PILL[status], className)}>
      <span className="size-1.5 rounded-full bg-current" aria-hidden />
      {t(`status.${status}`)}
    </span>
  )
}

/** The real / simulated label, shown wherever parks, nodes or readings appear. */
export function SourceBadge({ source, className }: { source: Source; className?: string }) {
  const { t } = useTranslation()

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-md px-1.5 py-0.5 text-[0.7rem] font-medium uppercase tracking-wide',
        source === 'simulated'
          ? 'bg-amber-500/15 text-amber-600 ring-1 ring-inset ring-amber-500/30 dark:text-amber-400'
          : 'bg-muted text-muted-foreground',
        className,
      )}
    >
      {t(`source.${source}`)}
    </span>
  )
}
