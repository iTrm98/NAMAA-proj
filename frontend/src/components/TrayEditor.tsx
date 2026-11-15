import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Skeleton, toast } from '@/components/ds'
import { useSaveTray, useTraySnapshot } from '@/hooks/useStand'
import { ApiError } from '@/lib/api'

const BTN = 'rounded-xl border border-[var(--glass-border-strong)] px-3 py-1.5 text-sm font-medium transition-opacity disabled:cursor-not-allowed disabled:opacity-40'

type Point = [number, number]

/**
 * Draw the tray area once on a snapshot of the camera (people are blurred in it). Points are stored as
 * fractions of the frame (0..1), so the area survives a change of camera resolution. Litter whose centre
 * is outside the area is ignored by the server. The picture is drawn left to right in both languages.
 */
export function TrayEditor({ nodeId, current, onClose }: { nodeId: number; current: Point[] | null; onClose: () => void }) {
  const { t } = useTranslation()
  const snapshot = useTraySnapshot(nodeId, true)
  const save = useSaveTray(nodeId)
  const [points, setPoints] = useState<Point[]>(current ?? [])

  const failure = snapshot.error instanceof ApiError ? (snapshot.error.body as { vision_offline?: boolean } | null) : null
  const poly = points.map(([x, y]) => `${x * 100},${y * 100}`).join(' ')

  const add = (e: React.MouseEvent<HTMLDivElement>) => {
    const r = e.currentTarget.getBoundingClientRect()
    const x = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width))
    const y = Math.min(1, Math.max(0, (e.clientY - r.top) / r.height))
    setPoints((p) => [...p, [Number(x.toFixed(4)), Number(y.toFixed(4))]])
  }
  const persist = (roi: Point[] | null) =>
    save.mutate(roi, {
      onSuccess: () => {
        toast.success(t('stand.traySaved'))
        onClose()
      },
      onError: () => toast.error(t('common.error')),
    })

  return (
    <div className="flex flex-col gap-3">
      <p className="m-0 text-xs text-text-2">{t('stand.trayHint')}</p>
      {snapshot.isPending ? (
        <>
          <Skeleton className="aspect-video w-full rounded-lg" />
          <p className="m-0 text-xs text-text-2">{t('stand.trayLoading')}</p>
        </>
      ) : snapshot.isError ? (
        <p className="m-0 py-6 text-center text-sm text-destructive">{failure?.vision_offline ? t('stand.trayVision') : t('stand.trayOffline')}</p>
      ) : (
        <div dir="ltr" className="relative cursor-crosshair overflow-hidden rounded-lg" onClick={add}>
          <img src={snapshot.data.image} alt="" className="block w-full select-none" draggable={false} />
          <svg viewBox="0 0 100 100" preserveAspectRatio="none" className="pointer-events-none absolute inset-0 size-full">
            {points.length >= 3 && <polygon points={poly} fill="rgba(34,197,94,0.18)" stroke="#22c55e" strokeWidth={0.6} vectorEffect="non-scaling-stroke" />}
            {points.length < 3 && points.length > 1 && <polyline points={poly} fill="none" stroke="#22c55e" strokeWidth={0.6} vectorEffect="non-scaling-stroke" />}
          </svg>
          {points.map(([x, y], i) => (
            <span key={i} className="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#22c55e] ring-2 ring-white" style={{ left: `${x * 100}%`, top: `${y * 100}%` }} />
          ))}
        </div>
      )}
      <div className="flex flex-wrap gap-2">
        <button type="button" className={BTN} onClick={() => setPoints([])} disabled={points.length === 0}>
          {t('stand.trayReset')}
        </button>
        <button type="button" className={BTN} onClick={() => persist(null)} disabled={save.isPending}>
          {t('stand.trayClear')}
        </button>
        <button type="button" className={`${BTN} bg-primary text-white`} onClick={() => persist(points)} disabled={points.length < 3 || save.isPending}>
          {t('stand.traySave')}
        </button>
        <button type="button" className={BTN} onClick={onClose}>
          {t('stand.trayClose')}
        </button>
      </div>
    </div>
  )
}
