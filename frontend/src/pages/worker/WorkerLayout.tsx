import { useTranslation } from 'react-i18next'
import { Outlet } from 'react-router'
import { LanguageToggle, LiveDot, SoundButton } from '@/components/AppHeader'
import { PageTransition } from '@/components/ds'
import { AccountMenu } from '@/components/shell/TopBar'
import { useAuth } from '@/lib/auth'
import { personName } from '@/lib/names'

/** /worker: phone header (greeting, Live, language, account), no top nav; one column at 390 px. */
export default function WorkerLayout() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const first = (user ? personName(user, i18n.language) : '').trim().split(/\s+/)[0]

  return (
    <div className="flex min-h-svh flex-col">
      <header className="mx-auto flex w-full max-w-xl items-center justify-between gap-3 px-4 pt-5 pb-2">
        <div className="min-w-0">
          <h1 className="m-0 truncate text-[22px] leading-tight font-light">{t('worker.greeting', { name: first })}</h1>
          {user?.company && <p dir="auto" className="m-0 text-start text-xs leading-snug text-text-2">{user.company.name}</p>}
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <LiveDot />
          <LanguageToggle />
          <AccountMenu />
        </div>
      </header>
      <div className="mx-auto w-full max-w-xl px-4 empty:hidden">
        <SoundButton />
      </div>
      <main className="mx-auto w-full max-w-xl flex-1 px-4 py-4">
        <PageTransition>
          <Outlet />
        </PageTransition>
      </main>
    </div>
  )
}
