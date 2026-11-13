import { Map as MapIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { NavLink, Outlet, useLocation } from 'react-router'
import { PageTransition, PillNav, Skeleton } from '@/components/ds'
import { AlertsBell } from '@/components/shell/AlertsBell'
import { QuickActions } from '@/components/shell/QuickActions'
import { TopBar } from '@/components/shell/TopBar'
import { SchedulerBanner } from '@/components/SchedulerBanner'
import { SourceBadge, StatusPill } from '@/components/StatusPill'
import { Button } from '@/components/ui/button'
import { useDemoStatus } from '@/hooks/useOps'
import { parkName, useParks } from '@/hooks/useParks'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

const WIDE = ['/admin/tasks', '/admin/demo', '/admin/impact', '/admin/hardware', '/admin/park-setup']

/** /admin: top bar (logo, PillNav, Live, language, bell, account), quick-action rail, park list (map and park pages), main area. */
export default function AdminLayout() {
  const { pathname } = useLocation()
  const wide = WIDE.some((p) => pathname.startsWith(p)) || ['/admin', '/admin/'].includes(pathname) || pathname.startsWith('/admin/parks')

  return (
    <div className="flex h-svh flex-col">
      <TopBar home="/admin" nav={<AdminNav />} extras={<AlertsBell />} />
      <SchedulerBanner />
      <div className="flex min-h-0 flex-1 gap-5 px-6 pb-5 max-md:px-3">
        <QuickActions className="self-start max-md:hidden" />
        {!wide && <ParkSidebar />}
        <main className="min-w-0 flex-1 overflow-y-auto">
          <PageTransition className="h-full">
            <Outlet />
          </PageTransition>
        </main>
      </div>
    </div>
  )
}

function AdminNav() {
  const { t } = useTranslation()
  const demo = useDemoStatus()
  const { pathname } = useLocation()

  const activeTo =
    pathname === '/admin' || pathname === '/admin/'
      ? '/admin'
      : pathname.startsWith('/admin/parks')
        ? '/admin/parks'
        : pathname.startsWith('/admin/tasks')
          ? '/admin/tasks'
          : pathname.startsWith('/admin/impact')
            ? '/admin/impact'
            : pathname.startsWith('/admin/hardware')
              ? '/admin/hardware'
              : pathname.startsWith('/admin/park-setup')
                ? '/admin/park-setup'
              : pathname.startsWith('/admin/demo')
                ? '/admin/demo'
                : undefined

  const tools = [{ to: '/admin/hardware', label: t('nav.hardware') }, { to: '/admin/park-setup', label: t('nav.parkSetup') }, ...(demo.data ? [{ to: '/admin/demo', label: t('nav.demo') }] : [])]

  return (
    <PillNav
      ariaLabel={t('nav.main')}
      activeTo={activeTo}
      items={[
        { to: '/admin', label: t('nav.overview') },
        { to: '/admin/parks', label: t('nav.parks') },
        { to: '/admin/tasks', label: t('nav.tasks') },
        { to: '/admin/impact', label: t('nav.impact') },
        { to: 'tools', label: t('nav.tools'), menu: tools },
      ]}
    />
  )
}

function ParkSidebar() {
  const { t, i18n } = useTranslation()
  const parks = useParks()

  return (
    <aside className="hidden w-72 shrink-0 flex-col rounded-[var(--r-card-sm)] border border-[var(--glass-border)] bg-[var(--glass-bg-column)] md:flex">
      <div className="flex items-center justify-between px-4 pt-4 pb-2">
        <h2 className="text-sm font-medium text-text-2">{t('admin.parks')}</h2>
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
              <Skeleton key={i} h={64} radius={12} />
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
                  cn('flex flex-col gap-1.5 rounded-xl px-3 py-2.5 text-sm transition-colors hover:bg-white/10', isActive && 'bg-white/10')
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
                  {park.open_alerts_count > 0 && <span className="text-status-dry">{t('admin.openAlerts', { count: park.open_alerts_count })}</span>}
                </div>
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </aside>
  )
}
