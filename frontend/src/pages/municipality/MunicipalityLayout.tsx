import { useTranslation } from 'react-i18next'
import { Outlet, useLocation } from 'react-router'
import { PageTransition, PillNav } from '@/components/ds'
import { TopBar } from '@/components/shell/TopBar'
import { ScopeContext } from '@/lib/scope'

/**
 * /municipality: read-only across every company (City, Companies, Parks). The admin map and
 * park page are reused under the 'municipality' scope (its API, no write controls).
 */
export default function MunicipalityLayout() {
  const { t } = useTranslation()
  const { pathname } = useLocation()
  const activeTo = pathname.startsWith('/municipality/companies')
    ? '/municipality/companies'
    : pathname.startsWith('/municipality/parks')
      ? '/municipality/parks'
      : '/municipality'

  return (
    <ScopeContext.Provider value="municipality">
      <div className="flex h-svh flex-col">
        <TopBar
          home="/municipality"
          readOnly
          nav={
            <PillNav
              ariaLabel={t('nav.main')}
              activeTo={activeTo}
              items={[
                { to: '/municipality', label: t('nav.city') },
                { to: '/municipality/companies', label: t('nav.companies') },
                { to: '/municipality/parks', label: t('nav.parks') },
              ]}
            />
          }
        />
        <main className="min-h-0 flex-1 overflow-y-auto px-6 pb-5 max-md:px-3">
          <PageTransition className="h-full">
            <Outlet />
          </PageTransition>
        </main>
      </div>
    </ScopeContext.Provider>
  )
}
