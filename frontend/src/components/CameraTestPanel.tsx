import { Camera, ShieldCheck, WifiOff } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Chip, Skeleton, toast } from '@/components/ds'
import { useSaveThreshold, useTestLoop, useTestSnapshot } from '@/hooks/useStand'
import { formatNumber } from '@/lib/format'
import type { StandLive, TestBox } from '@/types'

const BTN = 'rounded-xl border border-[var(--glass-border-strong)] px-3 py-1.5 text-sm font-medium transition-opacity disabled:cursor-not-allowed disabled:opacity-40'
const MIN = 0.1
const MAX = 0.5

// Box colours: green = counts as litter, amber = inside the tray but not confirmed yet (2-of-3), grey = outside the tray.
const GREEN = '#22c55e'
const AMBER = '#f59e0b'
const GREY = '#9ca3af'
const colorOf = (b: TestBox) => (!b.inside ? GREY : b.passed ? GREEN : AMBER)

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex min-w-0 flex-col gap-0.5 rounded-lg bg-[var(--glass-border)] px-3 py-2">
      <span className="text-[11px] text-text-2">{label}</span>
      <span className="text-base font-medium tabular-nums">{value}</span>
    </div>
  )
}

/**
 * Camera Test mode: the litter AI on every frame with boxes and confidence, at a live threshold. The server stores nothing
 * (no detection, alert or task). The picture is left to right in both languages; people are blurred by the server.
 */
export function CameraTestPanel({ node }: { node: NonNullable<StandLive['camera_node']> }) {
  const { t } = useTranslation()
  const [threshold, setThreshold] = useState(node.effective_threshold)
  const thresholdRef = useRef(threshold)
  thresholdRef.current = threshold
  const { frame, error, stats } = useTestLoop(true, thresholdRef)
  const save = useSaveThreshold(node.node_id)
  const snapshot = useTestSnapshot()

  const saved = node.litter_threshold
  const isSaved = saved !== null && Math.abs(saved - threshold) < 0.0005
  const w = frame?.image_w || 640
  const h = frame?.image_h || 480
  const poly = node.camera_roi?.map(([x, y]) => `${x * w},${y * h}`).join(' ')
  const stroke = Math.max(2, Math.round(Math.min(w, h) / 220))
  const font = Math.max(12, Math.round(Math.min(w, h) / 28))

  const onSave = () =>
    save.mutate(Number(threshold.toFixed(3)), {
      onSuccess: () => toast.success(t('stand.test.thresholdSaved', { value: formatNumber(threshold, 3) })),
      onError: () => toast.error(t('common.error')),
    })
  const onDefault = () =>
    save.mutate(null, {
      onSuccess: () => {
        setThreshold(node.default_threshold)
        toast.success(t('stand.test.thresholdCleared'))
      },
      onError: () => toast.error(t('common.error')),
    })
  const onSnapshot = () =>
    snapshot.mutate(undefined, {
      onSuccess: (r) => toast.success(t('stand.test.snapshotSaved', { path: r.path })),
      onError: () => toast.error(t('stand.test.snapshotFailed')),
    })

  return (
    <div className="flex flex-col gap-3">
      {error && (
        <p role="status" className="m-0 flex items-center gap-2 rounded-lg bg-[var(--glass-border)] px-3 py-2 text-sm">
          <WifiOff className="size-4 shrink-0" />
          {error === 'camera' ? t('stand.cameraOffline') : error === 'vision' ? t('stand.trayVision') : t('common.error')}
        </p>
      )}

      {frame ? (
        <div dir="ltr" className="relative overflow-hidden rounded-lg">
          <img src={frame.image} alt={t('stand.test.frameAlt')} className="block w-full select-none" draggable={false} />
          <svg viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" className="pointer-events-none absolute inset-0 size-full">
            {poly && <polygon points={poly} fill="rgba(34,197,94,0.10)" stroke={GREEN} strokeWidth={stroke} strokeDasharray={`${stroke * 4} ${stroke * 3}`} />}
            {frame.items.map((b, i) => {
              const [x1, y1, x2, y2] = b.box
              const label = formatNumber(b.confidence, 2)
              return (
                <g key={i}>
                  <rect x={x1} y={y1} width={x2 - x1} height={y2 - y1} fill="none" stroke={colorOf(b)} strokeWidth={stroke} strokeDasharray={b.inside ? undefined : `${stroke * 3} ${stroke * 2}`} />
                  <rect x={x1} y={Math.max(0, y1 - font - 4)} width={label.length * font * 0.62 + 8} height={font + 4} fill={colorOf(b)} />
                  <text x={x1 + 4} y={Math.max(0, y1 - font - 4) + font} fontSize={font} fontWeight={600} fill="#000">
                    {label}
                  </text>
                </g>
              )
            })}
          </svg>
        </div>
      ) : (
        !error && <Skeleton className="aspect-[4/3] w-full rounded-lg" />
      )}

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Stat label={t('stand.test.items')} value={frame ? `${frame.count}${frame.total !== frame.count ? ` / ${frame.total}` : ''}` : '—'} />
        <Stat label={t('stand.test.topConf')} value={frame?.top_confidence != null ? formatNumber(frame.top_confidence, 2) : '—'} />
        <Stat label={t('stand.test.inference')} value={frame?.inference_ms != null ? t('stand.test.ms', { value: frame.inference_ms }) : '—'} />
        <Stat label={t('stand.test.rate')} value={stats.fps != null ? t('stand.test.fps', { value: formatNumber(stats.fps, 1) }) : '—'} />
      </div>

      {frame && frame.items.length > 0 && (
        <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
          {frame.items.map((b, i) => (
            <li key={i} className="flex flex-wrap items-center gap-2 text-xs">
              <span className="inline-block size-2.5 rounded-sm" style={{ background: colorOf(b) }} />
              <span className="font-medium tabular-nums">{formatNumber(b.confidence, 2)}</span>
              <Chip tone={b.inside ? 'ok' : 'muted'}>{b.inside ? t('stand.test.inside') : t('stand.test.outside')}</Chip>
              <Chip tone={b.passed ? 'high' : 'muted'}>{b.passed ? t('stand.test.passed') : t('stand.test.notPassed')}</Chip>
            </li>
          ))}
        </ul>
      )}
      <p className="m-0 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-text-2">
        <span className="flex items-center gap-1">
          <ShieldCheck className="size-3.5" />
          {t('stand.blurOn')}
        </span>
        <span>{node.camera_roi ? t('stand.trayActive') : t('stand.trayWhole')}</span>
        <span>{t('stand.test.legend')}</span>
      </p>

      <div className="flex flex-col gap-2 rounded-lg border border-[var(--glass-border)] p-3">
        <label className="flex items-center justify-between gap-3 text-sm" htmlFor="test-threshold">
          <span>{t('stand.test.threshold')}</span>
          <span className="font-medium tabular-nums">{formatNumber(threshold, 3)}</span>
        </label>
        <input
          id="test-threshold"
          dir="ltr"
          type="range"
          min={MIN}
          max={MAX}
          step={0.005}
          value={threshold}
          onChange={(e) => setThreshold(Number(e.target.value))}
          className="w-full accent-[var(--primary,#22c55e)]"
        />
        <p className="m-0 text-[11px] text-text-2">
          {t('stand.test.thresholdHint', { default: formatNumber(node.default_threshold, 3), saved: saved !== null ? formatNumber(saved, 3) : t('stand.test.none') })}
        </p>
        <div className="flex flex-wrap gap-2">
          <button type="button" className={`${BTN} bg-primary text-white`} onClick={onSave} disabled={save.isPending || isSaved}>
            {t('stand.test.saveThreshold')}
          </button>
          <button type="button" className={BTN} onClick={onDefault} disabled={save.isPending || saved === null}>
            {t('stand.test.useDefault')}
          </button>
          <button type="button" className={`${BTN} flex items-center gap-1.5`} onClick={onSnapshot} disabled={!frame || snapshot.isPending}>
            <Camera className="size-4" />
            {t('stand.test.snapshot')}
          </button>
        </div>
      </div>

      <dl className="m-0 grid grid-cols-3 gap-2">
        <Stat label={t('stand.test.frames')} value={String(stats.frames)} />
        <Stat label={t('stand.test.litterFrames')} value={String(stats.litterFrames)} />
        <Stat label={t('stand.test.avgInference')} value={stats.avgMs != null ? t('stand.test.ms', { value: Math.round(stats.avgMs) }) : '—'} />
      </dl>
    </div>
  )
}
