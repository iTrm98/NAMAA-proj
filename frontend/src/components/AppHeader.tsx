import { useQueryClient } from '@tanstack/react-query'
import { Languages, LogOut, Moon, Sprout, Sun } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Button } from '@/components/ui/button'
import { setLanguage } from '@/i18n'
import { api } from '@/lib/api'
import { auth, useAuth } from '@/lib/auth'
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

export function AppHeader() {
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

      <div className="ms-auto flex items-center gap-1">
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
