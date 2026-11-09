import { useQueryClient } from '@tanstack/react-query'
import { Languages, LogOut, Moon, Sprout, Sun, Volume2 } from 'lucide-react'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Button } from '@/components/ui/button'
import { setLanguage } from '@/i18n'
import { api } from '@/lib/api'
import { auth, useAuth } from '@/lib/auth'
import { disconnectEcho, useLiveStatus } from '@/lib/echo'
import { unlockSound, useSoundEnabled } from '@/lib/notify'
import { cn } from '@/lib/utils'
import { setTheme, useTheme } from '@/lib/theme'

export function LanguageToggle() {
  const { t, i18n } = useTranslation()

  return (
    <Button
      variant="ghost"
      size="sm"
      onClick={() => setLanguage(i18n.language === 'ar' ? 'en' : 'ar')}
      aria-label={t('header.languageLabel')}
      title={t('header.languageLabel')}
    >
      <Languages />
      <span lang={i18n.language === 'ar' ? 'en' : 'ar'}>{t('header.language')}</span>
    </Button>
  )
}

export function ThemeToggle() {
  const { t } = useTranslation()
  const theme = useTheme()
  const label = theme === 'dark' ? t('header.themeToLight') : t('header.themeToDark')

  return (
    <Button variant="ghost" size="icon" onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')} aria-label={label} title={label}>
      {theme === 'dark' ? <Sun /> : <Moon />}
    </Button>
  )
}

/** Green "Live" when the Reverb connection is up, amber while (re)connecting. */
export function LiveDot() {
  const { t } = useTranslation()
  const status = useLiveStatus()
  if (status === 'off') return null
  const live = status === 'live'

  return (
    <span
      className="flex items-center gap-1.5 rounded-full px-2 py-1 text-xs text-muted-foreground"
      role="status"
      title={live ? t('live.liveHint') : t('live.reconnectingHint')}
    >
      <span className={cn('relative flex size-2', live ? 'text-status-ok' : 'text-amber-500')}>
        {live && <span className="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-60" />}
        <span className="relative inline-flex size-2 rounded-full bg-current" />
      </span>
      <span className="hidden sm:inline">{live ? t('live.live') : t('live.reconnecting')}</span>
    </span>
  )
}

/** One-time button: browsers only allow sound after a tap. */
export function SoundButton() {
  const { t } = useTranslation()
  const enabled = useSoundEnabled()
  if (enabled) return null

  return (
    <Button variant="outline" size="sm" className="min-h-11" onClick={() => void unlockSound()}>
      <Volume2 />
      {t('worker.enableSound')}
    </Button>
  )
}

export function AppHeader({ nav, actions }: { nav?: ReactNode; actions?: ReactNode }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  async function logout() {
    try {
      await api('/auth/logout', { method: 'POST' })
    } catch {
      // the token may already be invalid: sign out locally anyway
    }
    auth.signOut()
    disconnectEcho()
    queryClient.clear()
    navigate('/login', { replace: true })
  }

  return (
    <header className="flex h-14 shrink-0 items-center gap-3 border-b bg-background/95 px-4 backdrop-blur">
      <div className="flex items-center gap-2">
        <span className="flex size-8 items-center justify-center rounded-lg bg-primary/15 text-primary">
          <Sprout className="size-5" />
        </span>
        <div className="leading-tight">
          <div className="font-semibold">{t('app.name')}</div>
          <div className="hidden text-xs text-muted-foreground sm:block">{t('app.tagline')}</div>
        </div>
      </div>

      {nav}

      <div className="ms-auto flex items-center gap-1">
        {actions}
        <LiveDot />
        {user && (
          <div className="me-2 hidden text-end leading-tight md:block">
            <div className="text-sm font-medium">{user.name}</div>
            <div className="text-xs text-muted-foreground">
              {t(`header.roles.${user.role}`)}
              {user.company ? ` · ${user.company.name}` : ''}
            </div>
          </div>
        )}
        <LanguageToggle />
        <ThemeToggle />
        {user && (
          <Button variant="ghost" size="sm" onClick={logout}>
            <LogOut className="rtl:-scale-x-100" />
            <span className="hidden sm:inline">{t('header.logout')}</span>
          </Button>
        )}
      </div>
    </header>
  )
}
