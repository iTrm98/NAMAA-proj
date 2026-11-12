import { useMutation } from '@tanstack/react-query'
import { BellRing, Droplets, HardHat, ShieldCheck, Sprout } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useNavigate } from 'react-router'
import { LanguageToggle } from '@/components/AppHeader'
import { GlassCard } from '@/components/ds'
import { useDemoStatus } from '@/hooks/useOps'
import { adoptAccountLocale } from '@/i18n'
import { api, ApiError } from '@/lib/api'
import { auth, homeFor, useAuth } from '@/lib/auth'
import type { LoginResponse } from '@/types'

export function LoginPage() {
  const { t } = useTranslation()
  const { token, user } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  const demo = useDemoStatus()

  function enter({ token, user }: LoginResponse) {
    auth.signIn(token, user)
    adoptAccountLocale(user.locale)
    navigate(homeFor(user.role), { replace: true })
  }

  const login = useMutation({
    mutationFn: () => api<LoginResponse>('/auth/login', { method: 'POST', body: { email, password } }),
    onSuccess: enter,
  })

  // Demo mode only: one tap signs in as the seeded admin or worker (the server picks the account).
  const demoLogin = useMutation({
    mutationFn: (role: 'admin' | 'worker') => api<LoginResponse>('/auth/demo-login', { method: 'POST', body: { role } }),
    onSuccess: enter,
  })

  if (token && user) return <Navigate to={homeFor(user.role)} replace />

  function submit(event: FormEvent) {
    event.preventDefault()
    if (!email || !password) return
    login.mutate()
  }

  const error = login.error
  const message =
    error instanceof ApiError
      ? error.status === 422
        ? t('login.invalid')
        : error.status === 429
          ? t('login.tooMany')
          : t('common.error')
      : error
        ? t('login.failed')
        : demoLogin.error
          ? demoLogin.error instanceof ApiError && demoLogin.error.status === 429
            ? t('login.tooMany')
            : t('login.demoFailed')
          : null

  const FIELD = 'h-[46px] rounded-[14px] border border-[var(--glass-border)] bg-white/[.06] px-3.5 text-sm text-[var(--text)] outline-none placeholder:text-[var(--text-2)]/45 focus:border-[var(--accent-lime)]'
  const points = [
    { icon: <Droplets />, title: t('login.b1'), sub: t('login.b1s') },
    { icon: <BellRing />, title: t('login.b2'), sub: t('login.b2s') },
    { icon: <ShieldCheck />, title: t('login.b3'), sub: t('login.b3s') },
  ]

  return (
    <div className="flex min-h-svh flex-col px-6 py-5">
      <div className="flex justify-end">
        <LanguageToggle />
      </div>
      <main className="mx-auto flex w-full max-w-[1200px] flex-1 flex-wrap items-center justify-center gap-14 py-6">
        <section className="flex min-w-0 flex-[1_1_420px] flex-col gap-7">
          <div className="flex items-center gap-3">
            <span className="flex size-10 items-center justify-center rounded-xl bg-[var(--accent-lime)] text-[var(--on-accent)]">
              <Sprout className="size-6" aria-hidden />
            </span>
            <span className="text-[22px] font-semibold">{t('app.name')}</span>
          </div>
          <h1 className="m-0 text-[clamp(32px,4vw,48px)] leading-[1.15] font-light tracking-[-.01em]">{t('login.headline')}</h1>
          <ul className="m-0 flex list-none flex-col gap-[18px] p-0">
            {points.map((p) => (
              <li key={p.title} className="flex items-start gap-3.5">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-full border border-[var(--glass-border-strong)] text-[var(--text-muted)] [&>svg]:size-[18px]">{p.icon}</span>
                <div>
                  <div className="font-medium">{p.title}</div>
                  <span className="text-[13px] text-text-2">{p.sub}</span>
                </div>
              </li>
            ))}
          </ul>
        </section>

        <GlassCard as="section" padding={32} className="flex min-w-0 flex-[0_1_420px] flex-col gap-4 !rounded-[28px]">
          <div>
            <h2 className="m-0 text-[22px] font-medium">{t('login.title')}</h2>
            <span className="text-[13px] text-text-2">{t('login.subtitle')}</span>
          </div>
          <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {message && (
              <p role="alert" className="m-0 rounded-[14px] border border-[rgba(255,107,94,.4)] bg-[var(--chip-urgent-bg)] px-3.5 py-2.5 text-[13px] text-[var(--chip-urgent-fg)]">
                {message}
              </p>
            )}
            <label className="flex flex-col gap-1.5 text-[13px] text-text-2">
              {t('login.email')}
              <input
                id="email"
                type="email"
                dir="ltr"
                autoComplete="username"
                required
                placeholder={t('login.emailPlaceholder')}
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                aria-invalid={error instanceof ApiError && error.status === 422}
                className={FIELD}
              />
            </label>
            <label className="flex flex-col gap-1.5 text-[13px] text-text-2">
              {t('login.password')}
              <input
                id="password"
                type="password"
                dir="ltr"
                autoComplete="current-password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                aria-invalid={error instanceof ApiError && error.status === 422}
                className={FIELD}
              />
            </label>
            <button
              type="submit"
              disabled={login.isPending}
              className="min-h-[46px] rounded-full bg-[var(--accent-lime)] text-sm font-medium text-[var(--on-accent)] transition hover:brightness-95 disabled:opacity-70"
            >
              {login.isPending ? t('login.submitting') : t('login.submit')}
            </button>
          </form>

          {demo.data && (
            <div className="flex flex-col gap-3">
              <div className="flex items-center gap-3 text-xs text-text-2">
                <span className="h-px flex-1 bg-[var(--glass-border)]" />
                {t('login.demoDivider')}
                <span className="h-px flex-1 bg-[var(--glass-border)]" />
              </div>
              <div className="grid grid-cols-2 gap-2">
                {([['admin', <ShieldCheck key="a" />, t('login.enterAdmin')], ['worker', <HardHat key="w" />, t('login.enterWorker')]] as const).map(([role, icon, label]) => (
                  <button
                    key={role}
                    type="button"
                    disabled={demoLogin.isPending}
                    onClick={() => demoLogin.mutate(role)}
                    className="inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-[var(--glass-border-strong)] px-3 text-[13px] text-[var(--text)] hover:bg-white/10 disabled:opacity-70 [&>svg]:size-4"
                  >
                    {icon}
                    {label}
                  </button>
                ))}
              </div>
            </div>
          )}
        </GlassCard>
      </main>
    </div>
  )
}
