import { ChevronDown } from 'lucide-react'
import { type ReactNode, useEffect, useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { CameraTestPanel } from '@/components/CameraTestPanel'
import { GlassCard } from '@/components/ds'
import { ReadinessPanel } from '@/components/ReadinessPanel'
import { useCameraMode, useReadiness, useStandLive, useStandTest } from '@/hooks/useStand'
import { cn } from '@/lib/utils'

const GHOST = 'inline-flex min-h-11 items-center justify-center rounded-full border border-[var(--glass-border-strong)] px-5 text-sm font-medium hover:bg-white/10 disabled:opacity-50'

/** Collapsible section, closed by default (a plain button and region: keyboard and RTL safe). */
function Section({ title, children }: { title: string; children: ReactNode }) {
  const [open, setOpen] = useState(false)
  const id = useId()
  return (
    <GlassCard padding={0} className="overflow-hidden">
      <button
        type="button"
        aria-expanded={open}
        aria-controls={id}
        className="flex min-h-14 w-full cursor-pointer items-center justify-between gap-3 px-5 text-start text-[17px] font-medium"
        onClick={() => setOpen(!open)}
      >
        {title}
        <ChevronDown className={cn('size-5 text-text-2 transition-transform', open && 'rotate-180')} aria-hidden />
      </button>
      {open && (
        <div id={id} className="flex flex-col gap-3 px-5 pb-5">
          {children}
        </div>
      )}
    </GlassCard>
  )
}

/** One test button with its one-line result. */
function TestRow({ label, testKey }: { label: string; testKey: string }) {
  const { t } = useTranslation()
  const test = useStandTest()
  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <span className="text-sm">{label}</span>
      <div className="flex items-center gap-3">
        {test.data && (
          <span role="status" className={cn('text-sm', test.data.ok ? 'text-[var(--healthy)]' : 'text-[var(--urgent)]')}>
            {test.data.ok ? t('connect.testOk') : t('connect.testFail')}
          </span>
        )}
        {test.isError && (
          <span role="status" className="text-sm text-[var(--urgent)]">
            {t('common.error')}
          </span>
        )}
        <button type="button" className={GHOST} disabled={test.isPending} onClick={() => test.mutate(testKey)}>
          {test.isPending ? t('connect.testing') : t('connect.test')}
        </button>
      </div>
    </div>
  )
}

/** The Bench camera's test mode runs only while the AI test section is open; closing it goes back to demo mode. */
function AiTest() {
  const { t } = useTranslation()
  const live = useStandLive()
  const setMode = useCameraMode()
  const node = live.data?.camera_node ?? null
  const mode = node?.mode
  const { mutate } = setMode
  const nodeId = node?.node_id

  useEffect(() => {
    if (nodeId === undefined) return
    mutate('test')
    return () => mutate('demo')
    // only when the section opens (mounts) or closes (unmounts)
  }, [nodeId, mutate])

  return (
    <div className="flex flex-col gap-4">
      <TestRow label={t('connect.cameraA')} testKey="litter_a" />
      <TestRow label={t('connect.cameraB')} testKey="litter_b" />
      {node && mode === 'test' ? <CameraTestPanel node={node} /> : node ? null : <p className="m-0 text-sm text-text-2">{t('connect.noTestCamera')}</p>}
    </div>
  )
}

/** /admin/setup/connect: is everything connected? Checklist, two closed test sections, then on to the Live Demo. */
export default function ConnectTestPage() {
  const { t } = useTranslation()
  const readiness = useReadiness()
  const ready = readiness.data?.ready === true

  return (
    <div className="flex max-w-3xl min-w-0 flex-col gap-5 pb-8">
      <section className="flex flex-col gap-1.5">
        <h1 className="m-0 text-[32px] leading-[1.05] font-light tracking-[-.01em] sm:text-[40px]">{t('connect.title')}</h1>
        <span className="text-[15px] text-text-2">{t('connect.sub')}</span>
      </section>

      <GlassCard className="py-2">
        <ReadinessPanel />
      </GlassCard>

      <Section title={t('connect.aiTest')}>
        <AiTest />
      </Section>

      <Section title={t('connect.pumpTest')}>
        <p className="m-0 text-sm text-text-2">{t('connect.pumpHint')}</p>
        <TestRow label={t('connect.pumpA')} testKey="pump_a" />
        <TestRow label={t('connect.pumpB')} testKey="pump_b" />
      </Section>

      {ready && (
        <GlassCard className="flex flex-wrap items-center justify-between gap-3">
          <span className="flex items-center gap-2.5 text-[17px]">
            <span className="size-3 rounded-full bg-[var(--healthy)]" aria-hidden />
            {t('connect.allGreen')}
          </span>
          <Link to="/admin/live" className="inline-flex min-h-12 items-center rounded-full bg-[var(--accent-lime)] px-7 text-base font-medium text-[var(--on-accent)] no-underline hover:brightness-95">
            {t('connect.goLive')}
          </Link>
        </GlassCard>
      )}
    </div>
  )
}
