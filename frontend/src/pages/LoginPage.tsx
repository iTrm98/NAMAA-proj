import { useMutation } from '@tanstack/react-query'
import { HardHat, ShieldCheck, Sprout } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useNavigate } from 'react-router'
import { LanguageToggle, ThemeToggle } from '@/components/AppHeader'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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

  return (
    <div className="flex min-h-svh flex-col bg-gradient-to-b from-primary/10 via-background to-background">
      <div className="flex justify-end gap-1 p-3">
        <LanguageToggle />
        <ThemeToggle />
      </div>
      <main className="flex flex-1 items-center justify-center px-4 pb-16">
        <div className="w-full max-w-sm">
          <div className="mb-6 flex flex-col items-center gap-2 text-center">
            <span className="flex size-12 items-center justify-center rounded-2xl bg-primary/15 text-primary">
              <Sprout className="size-7" />
            </span>
            <h1 className="text-2xl font-semibold">{t('app.name')}</h1>
            <p className="text-sm text-muted-foreground">{t('app.tagline')}</p>
          </div>

          <Card>
            <CardHeader>
              <CardTitle>{t('login.title')}</CardTitle>
              <CardDescription>{t('login.subtitle')}</CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
                {message && (
                  <Alert variant="destructive">
                    <AlertDescription>{message}</AlertDescription>
                  </Alert>
                )}
                <div className="flex flex-col gap-2">
                  <Label htmlFor="email">{t('login.email')}</Label>
                  <Input
                    id="email"
                    type="email"
                    dir="ltr"
                    autoComplete="username"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    aria-invalid={error instanceof ApiError && error.status === 422}
                  />
                </div>
                <div className="flex flex-col gap-2">
                  <Label htmlFor="password">{t('login.password')}</Label>
                  <Input
                    id="password"
                    type="password"
                    dir="ltr"
                    autoComplete="current-password"
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    aria-invalid={error instanceof ApiError && error.status === 422}
                  />
                </div>
                <Button type="submit" size="lg" disabled={login.isPending || !email || !password}>
                  {login.isPending ? t('login.submitting') : t('login.submit')}
                </Button>
              </form>

              {demo.data && (
                <div className="mt-6 flex flex-col gap-3">
                  <div className="flex items-center gap-3 text-xs text-muted-foreground">
                    <span className="h-px flex-1 bg-border" />
                    {t('login.demoDivider')}
                    <span className="h-px flex-1 bg-border" />
                  </div>
                  <div className="grid grid-cols-2 gap-2">
                    <Button variant="outline" className="min-h-11" disabled={demoLogin.isPending} onClick={() => demoLogin.mutate('admin')}>
                      <ShieldCheck />
                      {t('login.enterAdmin')}
                    </Button>
                    <Button variant="outline" className="min-h-11" disabled={demoLogin.isPending} onClick={() => demoLogin.mutate('worker')}>
                      <HardHat />
                      {t('login.enterWorker')}
                    </Button>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </main>
    </div>
  )
}
