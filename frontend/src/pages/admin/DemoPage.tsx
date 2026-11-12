import { BatteryLow, Check, CloudOff, Droplet, Filter, Flame, FlaskConical, Play, RotateCcw, Satellite, Sun, Trash2, TrendingUp, Undo2 } from 'lucide-react'
import { type ReactNode, useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CameraDemoCard } from '@/components/CameraDemoCard'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { NdviDemoCard } from '@/components/NdviDemoCard'
import { SystemHealthCard } from '@/components/SystemHealthCard'
import { Disclosure, EmptyState, GlassCard, Skeleton, toast } from '@/components/ds'
import { useDemoStatus, useRunScenario } from '@/hooks/useOps'
import { ApiError } from '@/lib/api'
import type { ScenarioName } from '@/types'

type StepId = 'reset' | 'dry' | 'leak' | 'litter'

const STORAGE_KEY = 'namaa.demo.ran'
const ORDER: StepId[] = ['reset', 'dry', 'leak', 'litter']

const STEPS: { id: StepId; scenario: ScenarioName; icon: ReactNode }[] = [
  { id: 'reset', scenario: 'reset', icon: <RotateCcw /> },
  { id: 'dry', scenario: 'dry', icon: <Sun /> },
  { id: 'leak', scenario: 'leak', icon: <Droplet /> },
  { id: 'litter', scenario: 'litter', icon: <Trash2 /> },
]

const MORE: { id: string; scenario: ScenarioName; icon: ReactNode }[] = [
  { id: 'heat', scenario: 'heat', icon: <Flame /> },
  { id: 'blockage', scenario: 'blockage', icon: <Filter /> },
  { id: 'battery', scenario: 'battery', icon: <BatteryLow /> },
  { id: 'offline', scenario: 'offline', icon: <CloudOff /> },
  { id: 'escalate', scenario: 'escalate', icon: <TrendingUp /> },
  { id: 'recover', scenario: 'recover', icon: <Undo2 /> },
]

function loadRan(): StepId[] {
  try {
    const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]')
    return Array.isArray(raw) ? raw.filter((x): x is StepId => ORDER.includes(x)) : []
  } catch {
    return []
  }
}

function saveRan(ran: StepId[]) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(ran))
  } catch {
    /* storage can be blocked; the hint then lives in memory only */
  }
}

const pill = 'inline-flex min-h-12 items-center gap-2 rounded-full px-6 text-base font-medium transition-opacity disabled:opacity-50 [&>svg]:size-5'
const lime = `${pill} bg-[var(--accent-lime)] text-[#14180a]`
const ghost = `${pill} border border-[var(--glass-border-strong)]`
const danger = `${pill} bg-[var(--urgent)] text-[#2a0a07]`

/** /admin/demo: a guided story (reset, three steps, then more situations); simulated parks only. Hidden unless DEMO_MODE is on. */
export default function DemoPage() {
  const { t } = useTranslation()
  const demo = useDemoStatus()
  const run = useRunScenario()
  const [ran, setRan] = useState<StepId[]>(loadRan)

  const markRan = useCallback((id: StepId) => {
    setRan((prev) => {
      // A reset starts a new demo: the earlier steps no longer count.
      const next: StepId[] = id === 'reset' ? ['reset'] : prev.includes(id) ? prev : [...prev, id]
      saveRan(next)
      return next
    })
  }, [])

  if (demo.isPending) return <Skeleton h={260} radius={26} />
  if (demo.isError || !demo.data) return <EmptyState icon={<FlaskConical />} title={demo.isError ? t('common.error') : t('demo.notDemo')} />

  function start(name: ScenarioName, opts: { full?: boolean; step?: StepId } = {}) {
    run.mutate(
      { name, full: opts.full },
      {
        onSuccess: () => {
          toast.success(t('demo.done', { name: opts.full ? t('demo.fullReset') : t(`demo.scenario.${name}.title`) }))
          if (opts.step) markRan(opts.step)
          else if (opts.full) markRan('reset')
        },
        onError: (error) => toast.error(error instanceof ApiError && error.body?.message ? error.body.message : t('common.error')),
      },
    )
  }

  const busy = (name: ScenarioName, full = false) => run.isPending && run.variables?.name === name && !!run.variables.full === full
  const runLabel = (name: ScenarioName, full = false) => (busy(name, full) ? t('demo.running') : t('demo.run'))
  const next = ORDER.find((id) => !ran.includes(id))
  const hint = next ? t(`demoPage.next.${next}`) : t('demoPage.next.all')

  return (
    <div className="flex min-w-0 flex-col gap-6 pb-10">
      <section className="flex flex-col gap-2">
        <h1 className="m-0 text-[40px] leading-[1.05] font-light tracking-[-.01em]">{t('demo.title')}</h1>
        <span className="text-base text-text-2">{t('demo.intro')}</span>
      </section>

      <GlassCard padding={18} className="flex items-center gap-3" role="status" aria-live="polite">
        <span className="grid size-10 shrink-0 place-items-center rounded-full bg-[var(--accent-lime)] text-[#14180a] [&>svg]:size-5">
          <Play />
        </span>
        <div className="flex min-w-0 flex-col">
          <span className="text-xs text-text-2">{t('demoPage.nextLabel')}</span>
          <span className="text-xl font-medium">{hint}</span>
        </div>
      </GlassCard>

      <ol className="m-0 flex list-none flex-col gap-5 p-0">
        {STEPS.map(({ id, scenario, icon }, index) => {
          const isDone = ran.includes(id)
          const isReset = id === 'reset'
          const button = (
            <button type="button" className={isReset ? ghost : lime} disabled={run.isPending} onClick={isReset ? undefined : () => start(scenario, { step: id })}>
              {icon}
              {runLabel(scenario)}
            </button>
          )
          return (
            <li key={id}>
              <GlassCard as="article" padding={24} className="flex flex-col gap-4">
                <div className="flex items-start gap-4">
                  <span className="grid size-12 shrink-0 place-items-center rounded-full border border-[var(--glass-border-strong)] text-xl tabular-nums" aria-hidden>
                    {index}
                  </span>
                  <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                    <span className="text-xs text-text-2">{t('demoPage.step', { n: index })}</span>
                    <h2 className="m-0 text-2xl font-medium">{t(`demoPage.steps.${id}.title`)}</h2>
                    <p className="m-0 text-lg leading-relaxed text-text-2">{t(`demoPage.steps.${id}.story`)}</p>
                  </div>
                  {isDone && (
                    <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-[var(--healthy)]/15 px-3 py-1.5 text-sm text-[var(--healthy)] [&>svg]:size-4">
                      <Check />
                      {t('demoPage.ranDone')}
                    </span>
                  )}
                </div>
                <p className="m-0 text-base leading-relaxed">
                  <strong className="font-medium text-[var(--accent-lime)]">{t('demoPage.watch')}</strong> {t(`demoPage.steps.${id}.watch`)}
                </p>
                <div className="flex flex-wrap gap-3">
                  {isReset ? (
                    <ConfirmDialog trigger={button} title={t('demo.confirmResetTitle')} body={t('demo.confirmResetBody')} onConfirm={() => start('reset', { step: 'reset' })} />
                  ) : (
                    button
                  )}
                </div>
                {id === 'litter' && (
                  <Disclosure title={t('demoPage.camera')}>
                    <CameraDemoCard />
                  </Disclosure>
                )}
              </GlassCard>
            </li>
          )
        })}
      </ol>

      <Disclosure title={t('demoPage.more')} meta={t('demoPage.moreHint')} className="text-base">
        <div className="grid gap-4 pt-2 md:grid-cols-2">
          {MORE.map(({ id, scenario, icon }) => (
            <GlassCard key={id} as="article" padding={20} className="flex flex-col gap-3">
              <h3 className="m-0 text-xl font-medium">{t(`demoPage.more_.${id}.title`)}</h3>
              <p className="m-0 text-base leading-relaxed text-text-2">{t(`demoPage.more_.${id}.story`)}</p>
              <p className="m-0 text-sm leading-relaxed">
                <strong className="font-medium text-[var(--accent-lime)]">{t('demoPage.watch')}</strong> {t(`demoPage.more_.${id}.watch`)}
              </p>
              <p className="m-0 flex-1 text-sm leading-relaxed text-text-2">
                <strong className="font-medium text-text">{t('demoPage.ends')}</strong> {t(`demoPage.more_.${id}.ends`)}
              </p>
              <div>
                <button type="button" className={lime} disabled={run.isPending} onClick={() => start(scenario)}>
                  {icon}
                  {runLabel(scenario)}
                </button>
              </div>
            </GlassCard>
          ))}

          <GlassCard as="article" padding={20} className="flex flex-col gap-3 md:col-span-2">
            <h3 className="m-0 flex items-center gap-2 text-xl font-medium">
              <Satellite className="size-5" aria-hidden />
              {t('demoPage.more_.ndvi.title')}
            </h3>
            <p className="m-0 text-base leading-relaxed text-text-2">{t('demoPage.more_.ndvi.story')}</p>
            <p className="m-0 text-sm leading-relaxed">
              <strong className="font-medium text-[var(--accent-lime)]">{t('demoPage.watch')}</strong> {t('demoPage.more_.ndvi.watch')}
            </p>
            <p className="m-0 text-sm leading-relaxed text-text-2">
              <strong className="font-medium text-text">{t('demoPage.ends')}</strong> {t('demoPage.more_.ndvi.ends')}
            </p>
            <NdviDemoCard />
          </GlassCard>

          <GlassCard as="article" padding={20} className="flex flex-col gap-3 md:col-span-2">
            <h3 className="m-0 text-xl font-medium">{t('demo.fullReset')}</h3>
            <p className="m-0 text-base leading-relaxed text-text-2">{t('demoPage.more_.full.story')}</p>
            <p className="m-0 text-sm leading-relaxed">
              <strong className="font-medium text-[var(--accent-lime)]">{t('demoPage.watch')}</strong> {t('demoPage.more_.full.watch')}
            </p>
            <p className="m-0 text-sm leading-relaxed text-text-2">
              <strong className="font-medium text-text">{t('demoPage.ends')}</strong> {t('demoPage.more_.full.ends')}
            </p>
            <div>
              <ConfirmDialog
                trigger={
                  <button type="button" className={danger} disabled={run.isPending}>
                    <RotateCcw />
                    {runLabel('reset', true)}
                  </button>
                }
                destructive
                title={t('demo.confirmFullResetTitle')}
                body={t('demo.confirmFullResetBody')}
                onConfirm={() => start('reset', { full: true })}
              />
            </div>
          </GlassCard>
        </div>
      </Disclosure>

      <Disclosure title={t('health.title')}>
        <SystemHealthCard />
      </Disclosure>
    </div>
  )
}
