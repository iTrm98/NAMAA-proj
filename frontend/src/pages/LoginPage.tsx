import { useMutation } from '@tanstack/react-query'
import { Sprout } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useNavigate } from 'react-router'
import { LanguageToggle, ThemeToggle } from '@/components/AppHeader'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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

  const login = useMutation({
    mutationFn: () => api<LoginResponse>('/auth/login', { method: 'POST', body: { email, password } }),
    onSuccess: ({ token, user }) => {
      auth.signIn(token, user)
      adoptAccountLocale(user.locale)
      navigate(homeFor(user.role), { replace: true })
    },
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
            </CardContent>
          </Card>
        </div>
      </main>
    </div>
  )
}
