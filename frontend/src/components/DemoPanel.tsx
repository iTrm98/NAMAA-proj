import { FlaskConical, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { GlassCard, toast } from '@/components/ds'
import { useDemoStory, useRunScenario } from '@/hooks/useOps'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/utils'
import type { ScenarioName } from '@/types'

const SCENARIOS: ScenarioName[] = ['reset', 'dry', 'heat', 'leak', 'blockage', 'offline', 'litter', 'recover']
const CHIP = 'inline-flex min-h-11 items-center justify-center rounded-full border border-[var(--glass-border-strong)] px-4 text-sm font-medium hover:bg-white/10 disabled:opacity-50'

/** The scenario buttons: Reset, Dry, Heat, Leak, Blockage, Offline, Litter, Recover. Buttons that cannot run are hidden, with one short line. */
export function DemoScenarios({ className }: { className?: string }) {
  const { t } = useTranslation()
  const story = useDemoStory(true)
  const run = useRunScenario()
  const list = story.data?.scenarios
  const allowed = (name: ScenarioName) => name === 'reset' || !list || list.some((s) => s.name === name && s.available)
  const shown = SCENARIOS.filter(allowed)
  const hidden = SCENARIOS.length - shown.length

  function start(name: ScenarioName) {
    run.mutate(
      { name },
      {
        onSuccess: () => toast.success(t('demoPanel.done', { name: t(`demoPanel.s.${name}`) })),
        onError: (e) => toast.error(e instanceof ApiError && e.body?.message ? e.body.message : t('common.error')),
      },
    )
  }

  if (story.isPending) return <p className="m-0 text-sm text-text-2">{t('common.loading')}</p>
  if (story.isError)
    return (
      <p role="alert" className="m-0 text-sm text-destructive">
        {t('common.error')}{' '}
        <button type="button" className="underline" onClick={() => void story.refetch()}>
          {t('common.retry')}
        </button>
      </p>
    )

  return (
    <div className={cn('flex flex-col gap-3', className)}>
      <div className="flex flex-wrap gap-2">
        {shown.map((name) =>
          name === 'reset' ? (
            <ConfirmDialog
              key={name}
              trigger={
                <button type="button" className={CHIP} disabled={run.isPending}>
                  {t('demoPanel.s.reset')}
                </button>
              }
              title={t('demo.confirmResetTitle')}
              body={t('demo.confirmResetBody')}
              onConfirm={() => start('reset')}
            />
          ) : (
            <button key={name} type="button" className={CHIP} disabled={run.isPending} onClick={() => start(name)}>
              {t(`demoPanel.s.${name}`)}
            </button>
          ),
        )}
      </div>
      {hidden > 0 && <p className="m-0 text-xs text-text-2">{t('demoPanel.unavailable')}</p>}
    </div>
  )
}

/** A small floating "Demo" button that opens a compact scenario panel (Overview, DEMO_MODE only). */
export function DemoPanel() {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  return (
    <div className="fixed end-6 bottom-6 z-30 flex flex-col items-end gap-3">
      {open && (
        <GlassCard solid padding={16} className="w-[min(360px,calc(100vw-32px))]" role="dialog" aria-label={t('demoPanel.title')}>
          <div className="mb-3 flex items-center justify-between gap-2">
            <h2 className="m-0 text-[15px] font-medium">{t('demoPanel.title')}</h2>
            <button type="button" aria-label={t('common.close')} className="flex size-9 items-center justify-center rounded-full hover:bg-white/10" onClick={() => setOpen(false)}>
              <X className="size-4" aria-hidden />
            </button>
          </div>
          <DemoScenarios />
        </GlassCard>
      )}
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen(!open)}
        className="inline-flex min-h-11 items-center gap-2 rounded-full border border-[var(--glass-border-strong)] bg-[var(--solid-over-map)] px-4 text-sm font-medium shadow-lg hover:bg-white/10"
      >
        <FlaskConical className="size-4" aria-hidden />
        {t('demoPanel.button')}
      </button>
    </div>
  )
}
