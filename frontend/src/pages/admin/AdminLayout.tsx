import { Map as MapIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { NavLink, Outlet } from 'react-router'
import { AppHeader } from '@/components/AppHeader'
import { SourceBadge, StatusPill } from '@/components/StatusPill'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { parkName, useParks } from '@/hooks/useParks'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/** /admin: header, park list on the start side, main area (map or park page). */
export default function AdminLayout() {
  return (
    <div className="flex h-svh flex-col">
      <AppHeader />
      <div className="flex min-h-0 flex-1">
        <ParkSidebar />
        <main className="min-w-0 flex-1 overflow-y-auto">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

function ParkSidebar() {
  const { t, i18n } = useTranslation()
  const parks = useParks()

  return (
    <aside className="hidden w-72 shrink-0 flex-col border-e bg-muted/30 md:flex">
      <div className="flex items-center justify-between px-4 pt-4 pb-2">
        <h2 className="text-sm font-semibold text-muted-foreground">{t('admin.parks')}</h2>
        <NavLink to="/admin" end>
          {({ isActive }) => (
            <Button variant={isActive ? 'secondary' : 'ghost'} size="icon" aria-label={t('admin.parks')} title={t('admin.parks')}>
              <MapIcon />
            </Button>
          )}
        </NavLink>
      </div>

      <nav className="flex-1 overflow-y-auto px-2 pb-4">
        {parks.isPending && (
          <div className="flex flex-col gap-2 p-2">
            {[0, 1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-16 w-full" />
            ))}
          </div>
        )}
        {parks.isError && (
          <div className="p-3 text-sm text-destructive">
            {t('common.error')}{' '}
            <button className="underline" onClick={() => parks.refetch()}>
              {t('common.retry')}
            </button>
          </div>
        )}
        {parks.data?.length === 0 && <p className="p-3 text-sm text-muted-foreground">{t('admin.noParks')}</p>}

        <ul className="flex flex-col gap-1">
          {parks.data?.map((park) => (
            <li key={park.id}>
              <NavLink
                to={`/admin/parks/${park.id}`}
                className={({ isActive }) =>
                  cn('flex flex-col gap-1.5 rounded-lg px-3 py-2.5 text-sm transition-colors hover:bg-muted', isActive && 'bg-muted')
                }
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="truncate font-medium">{parkName(park, i18n.language)}</span>
                  <StatusPill status={park.status} />
                </div>
                <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                  <span className="flex items-center gap-1.5">
                    <SourceBadge source={park.source} />
                    {park.latest?.moisture_pct != null && <span>{formatNumber(park.latest.moisture_pct)} %</span>}
                  </span>
                  {park.open_alerts_count > 0 && (
                    <span className="text-status-dry">{t('admin.openAlerts', { count: park.open_alerts_count })}</span>
                  )}
                </div>
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </aside>
  )
}
