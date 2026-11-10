import { useQuery } from '@tanstack/react-query'
import { CircleAlert, CircleCheck, CircleX, Info, Stethoscope } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import { formatAgo } from '@/lib/format'
import { cn } from '@/lib/utils'

type Status = 'ok' | 'warn' | 'fail' | 'info'

interface Health {
  overall: 'ok' | 'warn' | 'fail'
  checks: { key: string; status: Status; detail: string }[]
  checked_at: string
}

const ICON: Record<Status, { icon: typeof CircleCheck; className: string }> = {
  ok: { icon: CircleCheck, className: 'text-status-ok' },
  warn: { icon: CircleAlert, className: 'text-amber-500' },
  fail: { icon: CircleX, className: 'text-destructive' },
  info: { icon: Info, className: 'text-sky-500' },
}

/** Demo page: the namaa:doctor checks, refreshed every 10 s (the camera check uses the last frame, not /capture). */
export function SystemHealthCard() {
  const { t } = useTranslation()
  const health = useQuery({
    queryKey: ['admin', 'demo', 'health'],
    queryFn: ({ signal }) => api<{ data: Health }>('/admin/demo/health', { signal }).then((r) => r.data),
    refetchInterval: 10_000,
  })
  const data = health.data
  const Overall = data ? ICON[data.overall].icon : null

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-2">
        <div className="flex flex-col gap-1">
          <CardTitle className="flex items-center gap-2">
            <Stethoscope className="size-4" />
            {t('health.title')}
          </CardTitle>
          <CardDescription>{t('health.intro')}</CardDescription>
        </div>
        {data && Overall && (
          <span className={cn('flex items-center gap-1.5 text-sm font-medium', ICON[data.overall].className)}>
            <Overall className="size-4" />
            {t(`health.overall.${data.overall}`)}
          </span>
        )}
      </CardHeader>
      <CardContent>
        {health.isPending ? (
          <Skeleton className="h-48 w-full" />
        ) : health.isError || !data ? (
          <p className="text-sm text-destructive">{t('health.unreachable')}</p>
        ) : (
          <>
            <ul className="grid gap-x-6 gap-y-2 md:grid-cols-2">
              {data.checks.map((check) => {
                const { icon: Icon, className } = ICON[check.status]
                return (
                  <li key={check.key} className="flex items-start gap-2 text-sm">
                    <Icon className={cn('mt-0.5 size-4 shrink-0', className)} aria-label={t(`health.overall.${check.status === 'info' ? 'ok' : check.status}`)} />
                    <div className="min-w-0">
                      <div className="font-medium">{t(`health.check.${check.key}`)}</div>
                      {/* Technical details stay in English, as in the namaa:doctor output. */}
                      {check.key === 'scheduler' && check.status !== 'ok' && check.status !== 'info' && <div className="text-xs font-medium text-destructive">{t('scheduler.stalled')}</div>}
                      <div className="text-xs break-words text-muted-foreground" dir="ltr">
                        {check.detail}
                      </div>
                    </div>
                  </li>
                )
              })}
            </ul>
            <p className="mt-3 text-xs text-muted-foreground">{t('health.checked', { ago: formatAgo(data.checked_at) })}</p>
          </>
        )}
      </CardContent>
    </Card>
  )
}
