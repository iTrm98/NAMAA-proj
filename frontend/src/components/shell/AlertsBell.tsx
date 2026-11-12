import { BellOff } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Chip, Drawer, EmptyState } from '@/components/ds'
import { parkName, useParks } from '@/hooks/useParks'
import { BellButton } from './TopBar'

/** Bell with the open-alert count; opens a drawer listing the parks that have open alerts. */
export function AlertsBell() {
  const { t, i18n } = useTranslation()
  const parks = useParks()
  const [open, setOpen] = useState(false)
  const withAlerts = (parks.data ?? []).filter((p) => p.open_alerts_count > 0)
  const total = withAlerts.reduce((n, p) => n + p.open_alerts_count, 0)

  return (
    <>
      <BellButton count={total} onClick={() => setOpen(true)} />
      <Drawer open={open} onOpenChange={setOpen} title={t('bell.title')}>
        {parks.isError && <EmptyState title={t('common.error')} />}
        {parks.data && withAlerts.length === 0 && <EmptyState icon={<BellOff />} title={t('bell.empty')} />}
        <ul className="m-0 flex list-none flex-col gap-2 p-0">
          {withAlerts.map((p) => (
            <li key={p.id}>
              <Link
                to={`/admin/parks/${p.id}`}
                onClick={() => setOpen(false)}
                className="flex min-h-11 items-center justify-between gap-2 rounded-[var(--r-mini)] border border-[var(--glass-border)] bg-[var(--glass-bg-strong)] px-3.5 py-3 text-[var(--text)] no-underline"
              >
                <span className="truncate text-sm font-medium">{parkName(p, i18n.language)}</span>
                <Chip tone="high">{t('admin.openAlerts', { count: p.open_alerts_count })}</Chip>
              </Link>
            </li>
          ))}
        </ul>
      </Drawer>
    </>
  )
}
