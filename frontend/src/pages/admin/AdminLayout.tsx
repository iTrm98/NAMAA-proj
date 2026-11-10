import { ChartColumn, ClipboardList, Cpu, FlaskConical, Map as MapIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, NavLink, Outlet, useLocation } from 'react-router'
import { AppHeader } from '@/components/AppHeader'
import { SchedulerBanner } from '@/components/SchedulerBanner'
import { SourceBadge, StatusPill } from '@/components/StatusPill'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useDemoStatus } from '@/hooks/useOps'
import { parkName, useParks } from '@/hooks/useParks'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/** /admin: header with Map · Tasks (· Demo), park list on the start side (map and park pages), main area. */
export default function AdminLayout() {
  const { pathname } = useLocation()
  const wide = pathname.startsWith('/admin/tasks') || pathname.startsWith('/admin/demo') || pathname.startsWith('/admin/impact') || pathname.startsWith('/admin/hardware')

  return (
    <div className="flex h-svh flex-col">
      <AppHeader nav={<AdminNav />} />
      <SchedulerBanner />
      <div className="flex min-h-0 flex-1">
        {!wide && <ParkSidebar />}
        <main className="min-w-0 flex-1 overflow-y-auto">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

function NavItem({ to, active, icon, label }: { to: string; active: boolean; icon: ReactNode; label: string }) {
  return (
    <Button asChild variant={active ? 'secondary' : 'ghost'} size="sm">
      <Link to={to} aria-current={active ? 'page' : undefined}>
        {icon}
        <span className="hidden sm:inline">{label}</span>
      </Link>
    </Button>
  )
}

function AdminNav() {
  const { t } = useTranslation()
  const demo = useDemoStatus()
  const { pathname } = useLocation()

  return (
    <nav className="ms-2 flex items-center gap-1">
      <NavItem to="/admin" active={pathname === '/admin' || pathname.startsWith('/admin/parks')} icon={<MapIcon />} label={t('nav.map')} />
      <NavItem to="/admin/tasks" active={pathname.startsWith('/admin/tasks')} icon={<ClipboardList />} label={t('nav.tasks')} />
      <NavItem to="/admin/impact" active={pathname.startsWith('/admin/impact')} icon={<ChartColumn />} label={t('nav.impact')} />
      <NavItem to="/admin/hardware" active={pathname.startsWith('/admin/hardware')} icon={<Cpu />} label={t('nav.hardware')} />
      {demo.data && <NavItem to="/admin/demo" active={pathname.startsWith('/admin/demo')} icon={<FlaskConical />} label={t('nav.demo')} />}
    </nav>
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
