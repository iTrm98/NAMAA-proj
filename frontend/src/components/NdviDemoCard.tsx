import { CheckCircle2, Loader2, Satellite, XCircle } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useNdviRun, useStartNdvi } from '@/hooks/useNdvi'
import { parkName, useParks } from '@/hooks/useParks'
import { ApiError } from '@/lib/api'
import { formatAgo } from '@/lib/format'

/** Demo page: "Fetch satellite data now" (90 days, every park with an outline) with live progress. */
export function NdviDemoCard() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const run = useNdviRun()
  const start = useStartNdvi()
  const parks = useParks()
  const r = run.data
  const running = !!r && !r.finished_at
  const finished = useRef<string | null>(null)

  // When a run finishes: refresh the parks (map colours, NDVI charts) once.
  useEffect(() => {
    if (r?.finished_at && finished.current !== r.id) {
      if (finished.current !== null) void queryClient.invalidateQueries({ queryKey: ['admin', 'parks'] })
      finished.current = r.id
    }
    if (r && !r.finished_at && finished.current === r.id) finished.current = null
  }, [r, queryClient])

  const pct = r && r.total > 0 ? Math.round(((r.done + r.failed) / r.total) * 100) : 0
  const nameOf = (id: string) => {
    const park = parks.data?.find((p) => String(p.id) === id)
    return park ? parkName(park, i18n.language) : `#${id}`
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Satellite className="size-4" />
          {t('ndvi.demoTitle')}
        </CardTitle>
        <CardDescription>{t('ndvi.demoIntro')}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <div>
          <Button
            disabled={running || start.isPending}
            onClick={() =>
              start.mutate(undefined, {
                onError: (error) => toast.error(error instanceof ApiError && error.status === 409 ? t('ndvi.alreadyRunning') : t('common.error')),
              })
            }
          >
            {running || start.isPending ? <Loader2 className="animate-spin" /> : <Satellite />}
            {running ? t('ndvi.fetching') : t('ndvi.fetchNow')}
          </Button>
        </div>
        {r && (
          <div className="flex flex-col gap-2">
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}>
              <div className="h-full rounded-full bg-primary transition-all" style={{ width: `${pct}%` }} />
            </div>
            <p className="text-xs text-muted-foreground">
              {t('ndvi.progress', { done: r.done + r.failed, total: r.total, values: r.values })}
              {' · '}
              {r.finished_at ? t('ndvi.finished', { ago: formatAgo(r.finished_at) }) : t('ndvi.startedAgo', { ago: formatAgo(r.started_at) })}
            </p>
            <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
              {Object.entries(r.parks).map(([id, state]) => (
                <li key={id} className="flex items-center gap-1">
                  {state === 'done' || state === 'skipped' ? (
                    <CheckCircle2 className="size-3.5 text-status-ok" />
                  ) : state === 'failed' ? (
                    <XCircle className="size-3.5 text-destructive" />
                  ) : (
                    <Loader2 className="size-3.5 animate-spin text-muted-foreground" />
                  )}
                  {nameOf(id)}
                </li>
              ))}
            </ul>
            {r.failed > 0 && r.finished_at && <p className="text-xs text-destructive">{t('ndvi.someFailed')}</p>}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
