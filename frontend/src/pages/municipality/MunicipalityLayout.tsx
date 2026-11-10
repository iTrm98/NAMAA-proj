import { LayoutDashboard } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, Outlet, useLocation } from 'react-router'
import { AppHeader } from '@/components/AppHeader'
import { Button } from '@/components/ui/button'
import { ScopeContext } from '@/lib/scope'

/**
 * /municipality: read-only across every company. The admin map and park page
 * are reused under the 'municipality' scope (its API, no write controls).
 */
export default function MunicipalityLayout() {
  const { t } = useTranslation()
  const { pathname } = useLocation()
  const home = pathname === '/municipality' || pathname === '/municipality/'

  return (
    <ScopeContext.Provider value="municipality">
      <div className="flex h-svh flex-col">
        <AppHeader
          nav={
            <nav className="ms-2 flex items-center gap-1">
              <Button asChild variant={home ? 'secondary' : 'ghost'} size="sm">
                <Link to="/municipality" aria-current={home ? 'page' : undefined}>
                  <LayoutDashboard />
                  <span className="hidden sm:inline">{t('municipality.overview')}</span>
                </Link>
              </Button>
              <span className="ms-2 hidden rounded border border-dashed px-1.5 py-0.5 text-xs text-muted-foreground md:inline">{t('municipality.readOnly')}</span>
            </nav>
          }
        />
        <main className="min-h-0 flex-1 overflow-y-auto">
          <Outlet />
        </main>
      </div>
    </ScopeContext.Provider>
  )
}
