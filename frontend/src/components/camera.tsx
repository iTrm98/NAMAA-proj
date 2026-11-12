import { Camera, ImageOff, ShieldCheck } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { type ApiScope, useParkFrames } from '@/hooks/useCamera'
import { apiBlob, isAbortError } from '@/lib/api'
import { formatAgo, formatDateTime, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { Detection } from '@/types'

const BOX = '#f59e0b' // amber: cleanliness

/** The protected frame image (Bearer token), kept as an object URL while shown. */
function useFrameUrl(path: string | null) {
  const [url, setUrl] = useState<string | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    if (!path) return
    const controller = new AbortController()
    let objectUrl: string | null = null
    setFailed(false)
    apiBlob(path, controller.signal)
      .then((blob) => {
        objectUrl = URL.createObjectURL(blob)
        setUrl(objectUrl)
      })
      .catch((error: unknown) => {
        // Unmounted or the frame changed: the abort is expected, not a failure.
        if (isAbortError(error) || controller.signal.aborted) return
        setFailed(true)
      })
    return () => {
      controller.abort()
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [path])

  return { url, failed }
}

/**
 * One camera frame with the litter boxes drawn over it (SVG in photo pixels,
 * so it scales with the image). People are already blurred by the server.
 */
export function FrameImage({ detection, className, labels = true, thumbOnly = false }: { detection: Detection; className?: string; labels?: boolean; thumbOnly?: boolean }) {
  const { t } = useTranslation()
  // The small version shows first (under a second), the display size replaces it when loaded; the full original is only the fallback.
  const thumb = useFrameUrl(detection.thumb_url ?? null)
  const display = useFrameUrl(thumbOnly && detection.thumb_url ? null : (detection.display_url ?? detection.image_url))
  const url = display.url ?? thumb.url
  const failed = display.failed && !thumb.url
  const w = detection.image_w ?? 640
  const h = detection.image_h ?? 480
  const stroke = Math.max(2, Math.round(Math.min(w, h) / 220))
  const font = Math.max(12, Math.round(Math.min(w, h) / 28))

  if (failed)
    return (
      <div className={cn('flex aspect-[4/3] items-center justify-center rounded-lg bg-muted text-muted-foreground', className)}>
        <ImageOff className="size-6" />
      </div>
    )
  if (!url) return <Skeleton className={cn('aspect-[4/3] rounded-lg', className)} />

  return (
    // Boxes are in photo pixels, left to right, in both languages.
    <div className={cn('relative overflow-hidden rounded-lg bg-black', className)} dir="ltr">
      <img src={url} alt={t('camera.frameAlt', { count: detection.count })} className="block h-auto w-full" />
      <svg viewBox={`0 0 ${w} ${h}`} className="pointer-events-none absolute inset-0 size-full" preserveAspectRatio="none" aria-hidden>
        {detection.items.map((item, i) => {
          const [x1, y1, x2, y2] = item.box
          const text = `${item.label} ${Math.round(item.confidence * 100)}%`
          const labelW = text.length * font * 0.58 + 8
          const above = y1 - font - 6 >= 0
          return (
            <g key={i}>
              <rect x={x1} y={y1} width={x2 - x1} height={y2 - y1} fill="none" stroke={BOX} strokeWidth={stroke} rx={stroke} />
              {labels && (
                <>
                  <rect x={x1} y={above ? y1 - font - 6 : y1} width={labelW} height={font + 6} fill={BOX} />
                  <text x={x1 + 4} y={(above ? y1 - 6 : y1 + font) - 1} fontSize={font} fill="#111" fontFamily="inherit" fontWeight={600}>
                    {text}
                  </text>
                </>
              )}
            </g>
          )
        })}
      </svg>
    </div>
  )
}

/** "3 items" / "Clean" pill. */
export function LitterCount({ count, className }: { count: number; className?: string }) {
  const { t } = useTranslation()
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        count > 0 ? 'bg-amber-500/15 text-amber-600 dark:text-amber-400' : 'bg-status-ok/15 text-status-ok',
        className,
      )}
    >
      {count > 0 ? t('camera.items', { count }) : t('camera.clean')}
    </span>
  )
}

/** Park page: the latest frame (live), its time and count, and a strip of the last 6 frames. */
export function CameraCard({ parkId, scope }: { parkId: number; scope?: ApiScope }) {
  const { t } = useTranslation()
  const frames = useParkFrames(parkId, scope)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const list = frames.data ?? []
  const shown = list.find((d) => d.id === selectedId) ?? list[0]

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2">
        <CardTitle className="flex items-center gap-2">
          <Camera className="size-4" />
          {t('camera.title')}
        </CardTitle>
        {shown && (
          <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <LitterCount count={shown.count} />
            <span title={formatDateTime(shown.captured_at)}>{formatAgo(shown.captured_at)}</span>
          </span>
        )}
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {frames.isPending ? (
          <Skeleton className="aspect-[4/3] w-full rounded-lg" />
        ) : frames.isError ? (
          <p className="text-sm text-destructive">{t('common.error')}</p>
        ) : !shown ? (
          <p className="py-8 text-center text-sm text-muted-foreground">{t('camera.noFrames')}</p>
        ) : (
          <>
            <FrameImage detection={shown} />
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
              <span>{formatDateTime(shown.captured_at)}</span>
              {shown.model && (
                <span>
                  {shown.model}
                  {shown.inference_ms != null && ` · ${formatNumber(shown.inference_ms, 0)} ms`}
                </span>
              )}
              {shown.origin !== 'camera' && <span className="rounded border border-dashed px-1.5">{t(`camera.origin.${shown.origin}`)}</span>}
              {shown.people_count > 0 && (
                <span className="flex items-center gap-1">
                  <ShieldCheck className="size-3.5" />
                  {t('camera.peopleBlurred', { count: shown.people_count })}
                </span>
              )}
            </div>
            {list.length > 1 && (
              <div className="grid grid-cols-6 gap-1.5">
                {list.map((d) => (
                  <button
                    key={d.id}
                    type="button"
                    onClick={() => setSelectedId(d.id === list[0].id ? null : d.id)}
                    className={cn('relative rounded-md ring-offset-background focus-visible:ring-2', d.id === shown.id && 'ring-2 ring-primary')}
                    aria-label={t('camera.frameAt', { time: formatDateTime(d.captured_at), count: d.count })}
                  >
                    <FrameImage detection={d} labels={false} thumbOnly className="rounded-md" />
                    <LitterCount count={d.count} className="absolute end-1 bottom-1 px-1.5 text-[10px]" />
                  </button>
                ))}
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  )
}
