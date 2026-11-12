import { Bell, LogOut, Sprout } from 'lucide-react'
import { DropdownMenu } from 'radix-ui'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { LanguageToggle, LiveDot, useLogout } from '@/components/AppHeader'
import { Chip } from '@/components/ds'
import { useAuth } from '@/lib/auth'

export const ICON_BTN =
  'flex size-11 shrink-0 items-center justify-center rounded-full border border-[var(--glass-border)] bg-white/[.06] text-[var(--text)] transition-colors hover:bg-white/10 [&>svg]:size-[18px]'

function initials(name: string): string {
  const parts = name.trim().split(/\s+/)
  return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '')).toUpperCase()
}

/** Logo mark + product name + the company name underneath. */
export function Logo({ to }: { to: string }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  return (
    <Link to={to} className="flex items-center gap-3 text-[var(--text)] no-underline">
      <span className="flex size-10 items-center justify-center rounded-[13px] bg-[var(--accent-lime)] text-[var(--on-accent)]">
        <Sprout className="size-[22px]" aria-hidden />
      </span>
      <span className="flex flex-col leading-tight">
        <span className="text-lg font-semibold">{t('app.name')}</span>
        <span className="text-xs text-text-2">{user?.company?.name ?? t('app.tagline')}</span>
      </span>
    </Link>
  )
}

/** Account avatar (lime) with a menu: who is signed in, sign out. */
export function AccountMenu() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const logout = useLogout()
  if (!user) return null
  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger
        aria-label={t('account.label', { name: user.name })}
        className="flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-full bg-[var(--accent-lime)] text-sm font-semibold text-[var(--on-accent)]"
      >
        {initials(user.name)}
      </DropdownMenu.Trigger>
      <DropdownMenu.Portal>
        <DropdownMenu.Content
          align="end"
          sideOffset={8}
          className="z-50 min-w-56 rounded-[var(--r-mini)] border border-[var(--glass-border-strong)] bg-popover p-2 shadow-xl"
        >
          <div className="px-3 py-2">
            <div className="text-sm font-medium">{user.name}</div>
            <div className="text-xs text-text-2">
              {t(`header.roles.${user.role}`)}
              {user.company ? ` · ${user.company.name}` : ''}
            </div>
          </div>
          <DropdownMenu.Separator className="my-1 h-px bg-[var(--glass-border)]" />
          <DropdownMenu.Item
            onSelect={() => void logout()}
            className="flex min-h-10 cursor-pointer items-center gap-2 rounded-xl px-3 text-sm outline-none data-[highlighted]:bg-white/10"
          >
            <LogOut className="size-4 rtl:-scale-x-100" aria-hidden />
            {t('header.logout')}
          </DropdownMenu.Item>
        </DropdownMenu.Content>
      </DropdownMenu.Portal>
    </DropdownMenu.Root>
  )
}

export function BellButton({ count, onClick }: { count: number; onClick: () => void }) {
  const { t } = useTranslation()
  return (
    <button type="button" className={`${ICON_BTN} relative`} aria-label={t('bell.label', { count })} onClick={onClick}>
      <Bell />
      {count > 0 && (
        <span className="absolute -top-0.5 -end-0.5 flex min-w-[18px] items-center justify-center rounded-full bg-[var(--urgent)] px-1 text-[10px] font-semibold leading-[18px] text-[var(--on-accent)] tabular-nums">
          {count > 99 ? '99+' : count}
        </span>
      )}
    </button>
  )
}

/** The top bar of the admin and municipality shells (logo, nav, live, language, extras, account). */
export function TopBar({ home, nav, extras, readOnly }: { home: string; nav?: ReactNode; extras?: ReactNode; readOnly?: boolean }) {
  const { t } = useTranslation()
  return (
    <header className="flex shrink-0 flex-wrap items-center justify-between gap-4 px-6 pt-5 pb-3 max-md:px-4">
      <Logo to={home} />
      {nav}
      <div className="flex items-center gap-2.5">
        {readOnly && <Chip tone="muted">{t('municipality.readOnly')}</Chip>}
        <LiveDot />
        <LanguageToggle />
        {extras}
        <AccountMenu />
      </div>
    </header>
  )
}
