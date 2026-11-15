import { CheckCircle2, Droplets, MoreHorizontal, WifiOff } from 'lucide-react'
import { DropdownMenu } from 'radix-ui'
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { FrameImage, LitterCount } from '@/components/camera'
import { Chip, EmptyState, GlassCard, Skeleton, toast } from '@/components/ds'
import { useParkFrames } from '@/hooks/useCamera'
import { useGatewayAuto, useGatewayLive, useReadiness, useZonePump } from '@/hooks/useStand'
import { ApiError } from '@/lib/api'
import { formatAgo, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { Detection, GatewayLive, GatewayTimelineEntry, GatewayZone, TimelineKind } from '@/types'

const MENU_ITEM = 'flex min-h-10 cursor-pointer items-center gap-2 rounded-xl px-3 text-sm outline-none data-[highlighted]:bg-white/10'
const PRIMARY = 'inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-[var(--accent-lime)] px-7 text-base font-medium text-[var(--on-accent)] transition-[filter] hover:brightness-95 disabled:opacity-50'

/** What kind of step a timeline row is: the problem, what NAMAA did about it, or the fix confirmed. */
const STAGE: Record<TimelineKind, 'problem' | 'action' | 'fixed'> = {
  alert_raised: 'problem',
  litter_seen: 'problem',
  pump_on: 'action',
  pump_off: 'action',
  task_created: 'action',
  auto_changed: 'action',
  alert_cleared: 'fixed',
  litter_cleared: 'fixed',
  task_verified: 'fixed',
}

/** /admin/live: is the system working right now? Ready line, two zone tiles, what NAMAA did, water used today. */
export default function LiveDemoPage() {
  const { t } = useTranslation()
  const live = useGatewayLive()
  const data = live.data

  return (
    <div className="flex min-w-0 flex-col gap-5 pb-8">
      <section className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
        <h1 className="m-0 text-[32px] leading-[1.05] font-light tracking-[-.01em] sm:text-[40px]">{t('liveDemo.title')}</h1>
        <ReadyLine />
      </section>

      {live.isPending ? (
        <div className="grid gap-5 lg:grid-cols-2" aria-busy="true">
          <Skeleton h={460} radius={26} />
          <Skeleton h={460} radius={26} />
        </div>
      ) : live.isError || !data ? (
        <GlassCard>
          <EmptyState
            icon={<WifiOff />}
            title={t('common.error')}
            action={
              <button type="button" className="min-h-11 rounded-full border border-[var(--glass-border-strong)] px-5 text-sm" onClick={() => void live.refetch()}>
                {t('common.retry')}
              </button>
            }
          />
        </GlassCard>
      ) : !data.zones || data.zones.length === 0 ? (
        <GlassCard>
          <EmptyState icon={<Droplets />} title={t('liveDemo.noZones')} action={<Link to="/admin/setup/connect" className="text-sm text-[var(--accent-lime)] no-underline hover:underline">{t('liveDemo.openConnect')}</Link>} />
        </GlassCard>
      ) : (
        <>
          <div className="grid items-start gap-5 lg:grid-cols-2">
            {(['A', 'B'] as const).map((z) => {
              const zone = data.zones?.find((x) => x.zone === z)
              return zone ? <ZoneTile key={z} zone={zone} live={data} /> : null
            })}
          </div>
          <Timeline entries={data.timeline ?? []} water={data.water_today_l ?? null} />
        </>
      )}
    </div>
  )
}

/** Green dot and one line when everything is connected; otherwise a red ring, the count and the link to Connect & Test. */
function ReadyLine() {
  const { t } = useTranslation()
  const readiness = useReadiness()
  if (readiness.isPending || readiness.isError || !readiness.data) return <span className="text-sm text-text-2">{readiness.isError ? t('liveDemo.readyUnknown') : ''}</span>
  const ready = readiness.data.ready
  return (
    <p role="status" className="m-0 flex items-center gap-2.5 text-[15px]">
      <span className={cn('size-3 shrink-0 rounded-full', ready ? 'bg-[var(--healthy)]' : 'bg-[var(--urgent)]')} aria-hidden />
      <span>{ready ? t('liveDemo.ready') : t('liveDemo.notReady')}</span>
      {!ready && (
        <Link to="/admin/setup/connect" className="text-[var(--accent-lime)] no-underline hover:underline">
          {t('liveDemo.openConnect')}
        </Link>
      )}
    </p>
  )
}

function errorText(error: unknown, fallback: string, down: string) {
  if (error instanceof ApiError && error.status === 502) return down
  return fallback
}

function ZoneTile({ zone, live }: { zone: GatewayZone; live: GatewayLive }) {
  const { t } = useTranslation()
  const pump = useZonePump()
  const auto = useGatewayAuto()
  const irrigationZone = zone.zone === 'A'
  const gw = live.gateway
  const watering = irrigationZone && (gw?.irrigating_zone === 'A' || zone.pump_on === true)
  const autoOn = gw?.auto === true
  const down = t('liveDemo.gatewayDown')

  const water = () => pump.mutate({ zone: zone.zone, on: true }, { onError: (e) => toast.error(errorText(e, t('common.error'), down)) })
  const stop = () => pump.mutate({ zone: zone.zone, on: false }, { onError: (e) => toast.error(errorText(e, t('common.error'), down)) })
  const toggleAuto = () => auto.mutate(!autoOn, { onError: (e) => toast.error(errorText(e, t('common.error'), down)) })

  const statusLine = watering
    ? gw?.pulses ? t('liveDemo.wateringPulse', { n: gw.pulses }) : t('liveDemo.watering')
    : autoOn && zone.flags.includes('dry')
      ? t('liveDemo.soaking')
      : t('liveDemo.idle')

  const chips: { key: string; tone: 'muted' | 'high' | 'urgent'; text: string }[] = []
  if (zone.temp_c != null) chips.push({ key: 'temp', tone: 'muted', text: `${formatNumber(zone.temp_c, 0)}°` })
  if (zone.humidity_pct != null) chips.push({ key: 'hum', tone: 'muted', text: t('liveDemo.humidity', { value: formatNumber(zone.humidity_pct, 0) }) })
  if (irrigationZone && zone.flags.includes('tank_low')) chips.push({ key: 'tank', tone: 'high', text: t('liveDemo.tankLow') })
  if (irrigationZone && zone.flags.includes('no_flow')) chips.push({ key: 'flow', tone: 'urgent', text: t('liveDemo.noFlow') })

  return (
    <GlassCard as="section" aria-labelledby={`zone-${zone.zone}`} className="flex min-w-0 flex-col gap-4">
      <header className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 id={`zone-${zone.zone}`} className="m-0 text-[17px] font-medium">
            {t('liveDemo.zone', { zone: zone.zone })}
          </h2>
          <span className="text-xs text-text-2">{t(irrigationZone ? 'liveDemo.smartIrrigation' : 'liveDemo.monitoring')}</span>
        </div>
        {irrigationZone && zone.online && (
          <div className="flex items-center gap-2">
            {watering ? (
              <button type="button" className={cn(PRIMARY, 'min-h-11 bg-transparent px-6 text-[var(--text)] border border-[var(--glass-border-strong)] hover:bg-white/10')} disabled={pump.isPending} onClick={stop}>
                {t('liveDemo.stop')}
              </button>
            ) : (
              <button type="button" className={PRIMARY} disabled={pump.isPending} onClick={water}>
                <Droplets className="size-5" aria-hidden />
                {t('liveDemo.waterNow')}
              </button>
            )}
            <DropdownMenu.Root>
              <DropdownMenu.Trigger aria-label={t('liveDemo.more')} className="flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-full border border-[var(--glass-border)] bg-white/[.06] hover:bg-white/10">
                <MoreHorizontal className="size-5" aria-hidden />
              </DropdownMenu.Trigger>
              <DropdownMenu.Portal>
                <DropdownMenu.Content align="end" sideOffset={8} className="z-50 min-w-48 rounded-[var(--r-mini)] border border-[var(--glass-border-strong)] bg-popover p-2 shadow-xl">
                  {watering && (
                    <DropdownMenu.Item className={MENU_ITEM} onSelect={stop}>
                      {t('liveDemo.stop')}
                    </DropdownMenu.Item>
                  )}
                  <DropdownMenu.Item className={MENU_ITEM} onSelect={toggleAuto}>
                    {autoOn ? t('liveDemo.autoOff') : t('liveDemo.autoOn')}
                  </DropdownMenu.Item>
                </DropdownMenu.Content>
              </DropdownMenu.Portal>
            </DropdownMenu.Root>
          </div>
        )}
      </header>

      {!zone.online ? (
        <p role="status" className="m-0 flex items-center gap-2 rounded-xl bg-white/[.06] px-4 py-3 text-sm text-text-2">
          <WifiOff className="size-4 shrink-0" aria-hidden />
          {t('liveDemo.zoneOffline', { zone: zone.zone })}
        </p>
      ) : (
        <>
          <div className="flex flex-wrap items-end gap-x-6 gap-y-2">
            <div className="flex flex-col">
              <span className="text-[56px] leading-none font-light tabular-nums">
                {zone.soil_pct != null ? formatNumber(zone.soil_pct, 0) : '—'}
                <span className="text-2xl text-text-2">%</span>
              </span>
              <span className="mt-1 text-xs text-text-2">{t('liveDemo.soil')}</span>
            </div>
            <Trend points={zone.trend} className="min-w-24 flex-1" />
          </div>
          {irrigationZone && (
            <p className="m-0 flex items-center gap-2 text-[15px]" role="status">
              <span className={cn('size-2.5 rounded-full', watering ? 'bg-[var(--water)]' : 'border-[1.5px] border-[var(--awaiting)]')} aria-hidden />
              {statusLine}
            </p>
          )}
          {chips.length > 0 && (
            <div className="flex flex-wrap gap-1.5">
              {chips.map((c) => (
                <Chip key={c.key} tone={c.tone}>
                  {c.text}
                </Chip>
              ))}
            </div>
          )}
        </>
      )}

      <ZoneCamera zone={zone} parkId={live.park_id} />
    </GlassCard>
  )
}

/** The last ~5 minutes of soil moisture as one quiet line. Drawn left to right in both languages. */
function Trend({ points, className }: { points: GatewayZone['trend']; className?: string }) {
  const { t } = useTranslation()
  const values = points.map((p) => p.pct).filter((v): v is number => v != null)
  if (values.length < 2) return null
  const min = Math.min(...values)
  const span = Math.max(...values) - min || 1
  const d = values.map((v, i) => `${i ? 'L' : 'M'}${((i / (values.length - 1)) * 200).toFixed(1)},${(34 - ((v - min) / span) * 30).toFixed(1)}`).join(' ')
  return (
    <svg viewBox="0 0 200 38" preserveAspectRatio="none" role="img" aria-label={t('liveDemo.trend')} className={cn('h-10 text-[var(--info)]', className)}>
      <path d={d} fill="none" stroke="currentColor" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
    </svg>
  )
}

/** The zone's camera: newest photo with the AI boxes and "N items". One calm line when there is nothing to show. */
function ZoneCamera({ zone, parkId }: { zone: GatewayZone; parkId: number }) {
  const { t } = useTranslation()
  const cam = zone.camera
  const frames = useParkFrames(parkId, 'admin', 12)

  const detection = useMemo<Detection | null>(() => {
    if (!cam || cam.detection_id == null) return null
    const match = frames.data?.find((d) => d.id === cam.detection_id) ?? frames.data?.find((d) => d.node_id === cam.node_id)
    if (match) return match
    if (!cam.image_url) return null
    return {
      id: cam.detection_id,
      park_id: parkId,
      node_id: cam.node_id,
      count: cam.count ?? 0,
      items: [],
      people_count: 0,
      model: null,
      inference_ms: null,
      image_w: null,
      image_h: null,
      origin: 'camera',
      image_url: cam.image_url,
      captured_at: cam.at ?? new Date().toISOString(),
    }
  }, [cam, frames.data, parkId])

  if (!cam || !cam.has_url) return null
  if (!cam.online && !detection) return <p className="m-0 text-sm text-text-2">{t('liveDemo.cameraOffline')}</p>
  if (!detection) return frames.isPending ? <Skeleton className="aspect-[4/3] w-full rounded-lg" /> : <p className="m-0 text-sm text-text-2">{t('liveDemo.noPhoto')}</p>

  return (
    <div className="flex flex-col gap-2">
      <FrameImage detection={detection} labels={false} />
      <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-text-2">
        <LitterCount count={detection.count} />
        {!cam.online ? <span>{t('liveDemo.cameraOffline')}</span> : <span>{formatAgo(detection.captured_at)}</span>}
      </div>
    </div>
  )
}

/** "What NAMAA did": the latest five steps (problem, action, fixed) and the water used today. */
function Timeline({ entries, water }: { entries: GatewayTimelineEntry[]; water: number | null }) {
  const { t } = useTranslation()
  const latest = entries.slice(0, 5)

  return (
    <section className="grid items-start gap-5 lg:grid-cols-[2fr_1fr]" aria-label={t('liveDemo.did')}>
      <GlassCard className="flex min-w-0 flex-col gap-3">
        <h2 className="m-0 text-[17px] font-medium">{t('liveDemo.did')}</h2>
        {latest.length === 0 ? (
          <p className="m-0 flex items-center gap-2 py-3 text-sm text-text-2">
            <CheckCircle2 className="size-4 text-[var(--healthy)]" aria-hidden />
            {t('liveDemo.didEmpty')}
          </p>
        ) : (
          <ol className="m-0 flex list-none flex-col gap-2.5 p-0">
            {latest.map((e) => {
              const stage = STAGE[e.kind]
              const what = typeof e.params?.type === 'string' ? t(`alertType.${e.params.type}`, { defaultValue: '' }) : ''
              const state = e.params?.on === false || e.params?.state === 'off' ? t('liveDemo.off') : t('liveDemo.on')
              return (
                <li key={e.id} className="flex items-start gap-3 text-sm">
                  <span
                    className={cn('mt-1.5 size-2.5 shrink-0 rounded-full', stage === 'fixed' ? 'bg-[var(--healthy)]' : stage === 'problem' ? 'bg-[var(--water)]' : 'bg-[var(--info)]')}
                    aria-hidden
                  />
                  <span className="min-w-0 flex-1">
                    {t(`liveDemo.timeline.${e.kind}`, { what, state })}
                    {e.zone ? <span className="text-text-2"> · {t('liveDemo.zone', { zone: e.zone })}</span> : null}
                  </span>
                  <span className="shrink-0 text-xs text-text-2">{formatAgo(e.at)}</span>
                </li>
              )
            })}
          </ol>
        )}
      </GlassCard>
      <GlassCard className="flex flex-col gap-1">
        <span className="text-[44px] leading-none font-light tabular-nums">
          {water != null ? formatNumber(water, 1) : '—'}
          <span className="ms-1 text-xl text-text-2">{t('liveDemo.litres')}</span>
        </span>
        <span className="text-xs text-text-2">{t('liveDemo.waterToday')}</span>
      </GlassCard>
    </section>
  )
}
