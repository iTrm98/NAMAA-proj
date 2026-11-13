import { Camera, Droplets, Power, Radio, Ruler, ShieldCheck, WifiOff } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { FrameImage, LitterCount } from '@/components/camera'
import { Chip, EmptyState, GlassCard, Skeleton, toast } from '@/components/ds'
import { SourceBadge } from '@/components/StatusPill'
import { useParkFrames } from '@/hooks/useCamera'
import { useNow } from '@/hooks/useNow'
import { useStandCalibrate, useStandLive, useStandPump } from '@/hooks/useStand'
import { ApiError } from '@/lib/api'
import { formatAgo, formatDateTime, formatNumber } from '@/lib/format'
import type { StandLive } from '@/types'

const BTN = 'rounded-xl border border-[var(--glass-border-strong)] font-medium transition-opacity disabled:cursor-not-allowed disabled:opacity-40'

function Title({ icon, children }: { icon: React.ReactNode; children: React.ReactNode }) {
  return (
    <h2 className="m-0 mb-3 flex items-center gap-2 text-[15px] font-medium [&>svg]:size-4 [&>svg]:text-text-2">
      {icon}
      {children}
    </h2>
  )
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-3 text-[13px]">
      <dt className="text-text-2">{label}</dt>
      <dd className="m-0 text-end">{children}</dd>
    </div>
  )
}

function errorText(error: unknown, fallback: string) {
  return error instanceof ApiError && error.body?.message ? error.body.message : fallback
}

/** Last ~5 minutes as a line (percent when calibrated, raw otherwise). Drawn left to right in both languages. */
function Trend({ points, calibrated }: { points: StandLive['trend']; calibrated: boolean }) {
  const { t } = useTranslation()
  const values = points.map((p) => (calibrated ? p.pct : p.raw)).filter((v): v is number => v != null)
  if (values.length < 2) return <p className="m-0 text-xs text-text-2">{t('stand.trendEmpty')}</p>
  const min = Math.min(...values)
  const span = Math.max(...values) - min || 1
  const d = values.map((v, i) => `${i ? 'L' : 'M'}${((i / (values.length - 1)) * 200).toFixed(1)},${(36 - ((v - min) / span) * 32).toFixed(1)}`).join(' ')
  return (
    <svg viewBox="0 0 200 40" className="h-12 w-full text-primary" preserveAspectRatio="none" role="img" aria-label={t('stand.trend')}>
      <path d={d} fill="none" stroke="currentColor" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
    </svg>
  )
}

function CameraBlock({ live }: { live: StandLive }) {
  const { t } = useTranslation()
  const frames = useParkFrames(live.park_id, 'admin', 1)
  const shown = frames.data?.[0]
  const litter = live.camera ? live.camera.litter : (shown?.count ?? 0) > 0
  return (
    <GlassCard>
      <Title icon={<Camera />}>{t('stand.camera')}</Title>
      {frames.isPending ? (
        <Skeleton className="aspect-[4/3] w-full rounded-lg" />
      ) : frames.isError ? (
        <p className="m-0 text-sm text-destructive">{t('common.error')}</p>
      ) : !shown ? (
        <p className="m-0 py-8 text-center text-sm text-text-2">{t('stand.cameraEmpty')}</p>
      ) : (
        <div className="flex flex-col gap-3">
          <FrameImage detection={shown} />
          <div className="flex flex-wrap items-center gap-2 text-xs text-text-2">
            {litter ? <Chip tone="high">{t('stand.litterDetected')}</Chip> : <Chip tone="ok">{t('stand.clean')}</Chip>}
            <LitterCount count={shown.count} />
            <span title={formatDateTime(shown.captured_at)}>{formatAgo(shown.captured_at)}</span>
            <span className="flex items-center gap-1">
              <ShieldCheck className="size-3.5" />
              {t('stand.blurOn')}
            </span>
          </div>
        </div>
      )}
    </GlassCard>
  )
}

function MoistureBlock({ live }: { live: StandLive }) {
  const { t } = useTranslation()
  const m = live.moisture
  return (
    <GlassCard>
      <Title icon={<Droplets />}>{t('stand.moisture')}</Title>
      {m.calibrated && m.pct != null ? (
        <>
          <div className="text-[56px] leading-none font-light tabular-nums">
            {formatNumber(m.pct, 1)}
            <span className="text-2xl text-text-2">%</span>
          </div>
          <p className="mt-1.5 mb-3 text-xs text-text-2">{t('stand.raw', { value: m.raw ?? '—' })}</p>
        </>
      ) : (
        <>
          <div className="text-[44px] leading-none font-light tabular-nums">{m.raw ?? '—'}</div>
          <p className="mt-1.5 mb-3 text-xs text-text-2">{t('stand.notCalibrated')}</p>
        </>
      )}
      <Trend points={live.trend} calibrated={m.calibrated} />
      <p className="mt-1 mb-0 text-[11px] text-text-2">{t('stand.trendHint')}</p>
    </GlassCard>
  )
}

function PumpBlock({ live, now }: { live: StandLive; now: number }) {
  const { t } = useTranslation()
  const pump = useStandPump()
  const { pump: p, board } = live
  const on = p.reported_on === true
  const remaining = p.target && p.cutoff_at ? Math.max(0, Math.round((new Date(p.cutoff_at).getTime() - now) / 1000)) : p.remaining_s
  const send = (value: boolean) => pump.mutate(value, { onError: (e) => toast.error(errorText(e, t('common.error'))) })
  return (
    <GlassCard>
      <Title icon={<Power />}>{t('stand.pump')}</Title>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <Chip tone={on ? 'ok' : 'muted'} className="!px-3 !py-1 !text-sm">
          {p.reported_on == null ? t('stand.pumpUnknown') : on ? t('stand.on') : t('stand.off')}
        </Chip>
        <span className="text-xs text-text-2">{t('stand.reportedByBoard')}</span>
        {p.reported_on != null && p.target !== on && <Chip tone="normal">{t('stand.pumpPending')}</Chip>}
      </div>
      {p.target && remaining != null && <p className="mt-0 mb-3 text-sm tabular-nums">{t('stand.autoOff', { seconds: remaining })}</p>}
      <div className="grid grid-cols-2 gap-3">
        <button type="button" disabled={!board.online || pump.isPending || p.target} onClick={() => send(true)} className={`${BTN} min-h-14 bg-primary text-lg font-semibold text-primary-foreground`}>
          {t('stand.turnOn')}
        </button>
        <button type="button" disabled={pump.isPending || !p.target} onClick={() => send(false)} className={`${BTN} min-h-14 text-lg font-semibold`}>
          {t('stand.turnOff')}
        </button>
      </div>
      {!board.online && <p className="mt-2 mb-0 text-xs text-text-2">{t('stand.onNeedsBoard')}</p>}
      <p className="mt-2 mb-0 text-[11px] text-text-2">{t('stand.maxHint', { seconds: p.max_s })}</p>
    </GlassCard>
  )
}

function BoardBlock({ live }: { live: StandLive }) {
  const { t } = useTranslation()
  const { board } = live
  return (
    <GlassCard>
      <Title icon={<Radio />}>{t('stand.board')}</Title>
      <dl className="m-0 flex flex-col gap-2">
        <Row label={t('stand.status')}>
          <Chip tone={board.online ? 'ok' : 'urgent'}>{board.online ? t('stand.online') : t('stand.offline')}</Chip>
        </Row>
        <Row label={t('stand.lastReading')}>
          <span title={formatDateTime(board.last_seen_at)}>{board.last_seen_at ? formatAgo(board.last_seen_at) : t('common.never')}</span>
        </Row>
        <Row label={t('stand.link')}>{board.link ? t(`stand.linkType.${board.link}`) : '—'}</Row>
        {board.rssi != null && (
          <Row label={t('stand.signal')}>
            <span dir="ltr">{board.rssi} dBm</span>
          </Row>
        )}
      </dl>
    </GlassCard>
  )
}

function CalibrationBlock({ live }: { live: StandLive }) {
  const { t } = useTranslation()
  const cal = useStandCalibrate()
  const c = live.calibration
  const record = (point: 'dry' | 'wet') =>
    cal.mutate(point, {
      onSuccess: () => toast.success(t('stand.recorded', { point: t(`stand.point.${point}`) })),
      onError: (e) => toast.error(errorText(e, t('common.error'))),
    })
  const disabled = !live.board.online || cal.isPending
  return (
    <GlassCard>
      <Title icon={<Ruler />}>{t('stand.calibration')}</Title>
      <ol className="m-0 mb-3 ps-5 text-xs leading-relaxed text-text-2">
        <li>{t('stand.calStep1')}</li>
        <li>{t('stand.calStep2')}</li>
      </ol>
      <div className="grid grid-cols-2 gap-3">
        <button type="button" disabled={disabled} onClick={() => record('dry')} className={`${BTN} min-h-12 text-sm`}>
          {t('stand.recordDry')}
        </button>
        <button type="button" disabled={disabled} onClick={() => record('wet')} className={`${BTN} min-h-12 text-sm`}>
          {t('stand.recordWet')}
        </button>
      </div>
      <dl className="mt-3 mb-0 flex flex-col gap-1.5">
        <Row label={t('stand.rawDry')}>
          <span className="tabular-nums">{c.raw_dry ?? '—'}</span>
        </Row>
        <Row label={t('stand.rawWet')}>
          <span className="tabular-nums">{c.raw_wet ?? '—'}</span>
        </Row>
        <Row label={t('stand.status')}>{c.calibrated ? <Chip tone="ok">{t('stand.calibrated')}</Chip> : <Chip tone="muted">{t('stand.notCalibratedShort')}</Chip>}</Row>
      </dl>
    </GlassCard>
  )
}

export default function StandPage() {
  const { t } = useTranslation()
  const stand = useStandLive()
  const now = useNow()
  const live = stand.data

  return (
    <div className="flex min-w-0 flex-col gap-5 pb-8">
      <section className="flex flex-col gap-1.5">
        <Chip tone="lime" className="w-fit">
          {t('stand.banner')}
        </Chip>
        <h1 className="m-0 text-[32px] leading-[1.05] font-light tracking-[-.01em] sm:text-[40px]">{t('stand.title')}</h1>
        {live && (
          <span className="flex items-center gap-2 text-sm text-text-2">
            {live.name}
            <SourceBadge source={live.source} />
          </span>
        )}
      </section>

      {stand.isPending ? (
        <div className="grid gap-4 md:grid-cols-2">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-48 w-full rounded-2xl" />
          ))}
        </div>
      ) : stand.isError || !live ? (
        <GlassCard>
          <EmptyState
            icon={<WifiOff />}
            title={stand.error instanceof ApiError && stand.error.status === 404 ? t('stand.none') : t('common.error')}
            action={
              <button type="button" onClick={() => void stand.refetch()} className={`${BTN} px-4 py-1.5 text-sm`}>
                {t('common.retry')}
              </button>
            }
          />
        </GlassCard>
      ) : (
        <>
          {!live.board.online && (
            <div role="alert" className="flex items-center gap-2 rounded-xl bg-[var(--chip-urgent-bg)] px-4 py-3 text-sm font-medium text-[var(--chip-urgent-fg)]">
              <WifiOff className="size-4" />
              {t('stand.offlineBanner')}
            </div>
          )}
          <div className="grid gap-4 md:grid-cols-2">
            <CameraBlock live={live} />
            <div className="flex flex-col gap-4">
              <MoistureBlock live={live} />
              <PumpBlock live={live} now={now} />
            </div>
            <BoardBlock live={live} />
            <CalibrationBlock live={live} />
          </div>
        </>
      )}
    </div>
  )
}
