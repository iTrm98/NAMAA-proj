import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation } from 'react-router'

/** Browser tab title: "Overview · NAMAA" (or the default "NAMAA · نماء" without a page); follows the language. */
export function useDocumentTitle(page?: string | null) {
  const { t, i18n } = useTranslation()
  useEffect(() => {
    document.title = page ? t('app.titleFormat', { page }) : t('app.titleDefault')
  }, [page, t, i18n.language])
}

const ROUTE_TITLES: Record<string, string> = {
  '/login': 'login.title',
  '/admin': 'nav.overview',
  '/admin/map': 'nav.map',
  '/admin/parks': 'nav.parks',
  '/admin/tasks': 'nav.tasks',
  '/admin/demo': 'nav.demo',
  '/admin/hardware': 'nav.hardware',
  '/admin/impact': 'nav.impact',
  '/worker': 'worker.myTasks',
  '/municipality': 'nav.city',
  '/municipality/companies': 'nav.companies',
  '/municipality/parks': 'nav.parks',
}

/** Sets the title of every list-level route; detail pages (a park, a task) set their own with useDocumentTitle. */
export function RouteTitle() {
  const { t } = useTranslation()
  const { pathname } = useLocation()
  const key = ROUTE_TITLES[pathname.replace(/\/+$/, '') || '/']
  useDocumentTitle(key ? t(key) : null)
  return null
}
