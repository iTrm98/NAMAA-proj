import { TriangleAlert } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useSystemStatus } from '@/hooks/useOps'

/** Red banner on every admin page while the scheduler heartbeat is stale; clears on its own. */
export function SchedulerBanner() {
  const { t } = useTranslation()
  const status = useSystemStatus()
  if (!status.data?.scheduler.stalled) return null
  return (
    <div role="alert" className="flex items-start gap-2 bg-destructive px-4 py-2 text-sm font-medium text-white">
      <TriangleAlert className="mt-0.5 size-4 shrink-0" />
      <span>
        {t('scheduler.stalled')} <span className="font-normal opacity-90">{t('scheduler.stalledBody')}</span>
      </span>
    </div>
  )
}
