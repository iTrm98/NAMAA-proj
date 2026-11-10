import { BatteryLow, CloudOff, Droplet, Filter, Flame, RotateCcw, Sun, Trash2, TrendingUp, Undo2 } from 'lucide-react'
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { CameraDemoCard } from '@/components/CameraDemoCard'
import { NdviDemoCard } from '@/components/NdviDemoCard'
import { SystemHealthCard } from '@/components/SystemHealthCard'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { useDemoStatus, useRunScenario } from '@/hooks/useOps'
import { ApiError } from '@/lib/api'
import type { ScenarioName } from '@/types'

const SCENARIOS: { name: ScenarioName; icon: ReactNode }[] = [
  { name: 'dry', icon: <Sun /> },
  { name: 'heat', icon: <Flame /> },
  { name: 'escalate', icon: <TrendingUp /> },
  { name: 'leak', icon: <Droplet /> },
  { name: 'blockage', icon: <Filter /> },
  { name: 'battery', icon: <BatteryLow /> },
  { name: 'offline', icon: <CloudOff /> },
  { name: 'litter', icon: <Trash2 /> },
  { name: 'recover', icon: <Undo2 /> },
  { name: 'reset', icon: <RotateCcw /> },
]

/** /admin/demo: one button per scenario; simulated parks only. Hidden unless DEMO_MODE is on. */
export default function DemoPage() {
  const { t } = useTranslation()
  const demo = useDemoStatus()
  const run = useRunScenario()

  if (demo.isPending) return <Skeleton className="m-6 h-64" />
  if (!demo.data) return <p className="p-6 text-sm text-muted-foreground">{t('demo.notDemo')}</p>

  function start(name: ScenarioName, full = false) {
    run.mutate({ name, full }, {
      onSuccess: () => toast.success(t('demo.done', { name: full ? t('demo.fullReset') : t(`demo.scenario.${name}.title`) })),
      onError: (error) => toast.error(error instanceof ApiError && error.body?.message ? error.body.message : t('common.error')),
    })
  }

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 p-4 lg:p-6">
      <div>
        <h1 className="text-xl font-semibold">{t('demo.title')}</h1>
        <p className="text-sm text-muted-foreground">{t('demo.intro')}</p>
      </div>
      <SystemHealthCard />
      <CameraDemoCard />
      <NdviDemoCard />
      <div className="grid gap-3 sm:grid-cols-2">
        {SCENARIOS.map(({ name, icon }) => {
          const button = (
            <Button variant={name === 'reset' ? 'outline' : 'default'} disabled={run.isPending} onClick={name === 'reset' ? undefined : () => start(name)}>
              {icon}
              {run.isPending && run.variables?.name === name && !run.variables.full ? t('demo.running') : t('demo.run')}
            </Button>
          )
          return (
            <Card key={name}>
              <CardHeader>
                <CardTitle>{t(`demo.scenario.${name}.title`)}</CardTitle>
                <CardDescription>{t(`demo.scenario.${name}.body`)}</CardDescription>
              </CardHeader>
              <CardContent>
                {name === 'reset' ? (
                  <div className="flex flex-wrap gap-2">
                    <ConfirmDialog trigger={button} title={t('demo.confirmResetTitle')} body={t('demo.confirmResetBody')} onConfirm={() => start('reset')} />
                    <ConfirmDialog
                      trigger={
                        <Button variant="destructive" disabled={run.isPending}>
                          <RotateCcw />
                          {run.isPending && run.variables?.full ? t('demo.running') : t('demo.fullReset')}
                        </Button>
                      }
                      destructive
                      title={t('demo.confirmFullResetTitle')}
                      body={t('demo.confirmFullResetBody')}
                      onConfirm={() => start('reset', true)}
                    />
                  </div>
                ) : (
                  button
                )}
              </CardContent>
            </Card>
          )
        })}
      </div>
    </div>
  )
}
