import { Camera, Video, VideoOff } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { FrameImage, LitterCount } from '@/components/camera'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { useCameraTest, useSendBrowserFrame, useUpdateCamera } from '@/hooks/useHardware'
import { ApiError } from '@/lib/api'
import { formatAgo, formatDateTime, formatNumber } from '@/lib/format'
import type { Detection, HardwareNode } from '@/types'

function errorText(error: unknown, fallback: string, visionDown: string): string {
  if (error instanceof ApiError) {
    if (error.status === 503) return error.body?.message ?? visionDown
    const first = error.body?.errors ? Object.values(error.body.errors)[0]?.[0] : undefined
    return first ?? error.body?.message ?? fallback
  }
  return fallback
}

/** Camera node: capture_mode switch, then either "Test camera now" (url) or this computer's camera (browser). */
export function CameraPanel({ node }: { node: HardwareNode }) {
  const { t } = useTranslation()
  const update = useUpdateCamera(node.id)
  const camera = node.camera
  if (!camera) return null
  const browser = camera.capture_mode === 'browser'

  function setMode(on: boolean) {
    update.mutate(
      { capture_mode: on ? 'browser' : 'url' },
      { onError: (error) => toast.error(errorText(error, t('common.error'), t('camera.visionDown'))) },
    )
  }

  return (
    <section className="flex flex-col gap-3 rounded-lg border p-3">
      <label className="flex items-center justify-between gap-2 text-sm font-medium">
        <span>{t('hardware.camera.browserMode')}</span>
        <Switch checked={browser} disabled={update.isPending} onCheckedChange={setMode} aria-label={t('hardware.camera.browserMode')} />
      </label>
      <p className="text-xs text-muted-foreground">
        {browser ? t('hardware.camera.browserHint') : t('hardware.camera.urlHint', { url: camera.effective_url ?? t('hardware.camera.noUrl') })}
      </p>
      {browser ? <BrowserFeed node={node} /> : <UrlTest node={node} />}
    </section>
  )
}

function UrlTest({ node }: { node: HardwareNode }) {
  const { t } = useTranslation()
  const test = useCameraTest(node.id)
  const hasUrl = !!node.camera?.effective_url

  return (
    <div className="flex flex-col gap-2">
      <div>
        <Button variant="outline" size="sm" disabled={!hasUrl || test.isPending} onClick={() => test.mutate()}>
          <Camera />
          {test.isPending ? t('hardware.camera.testing') : t('hardware.camera.testNow')}
        </Button>
      </div>
      {test.isError && (
        <p role="alert" className="text-sm text-destructive">
          {errorText(test.error, t('common.error'), t('camera.visionDown'))}
        </p>
      )}
      {test.data && (
        <div className="flex flex-col gap-1.5">
          <img src={test.data.image} alt={t('hardware.camera.testAlt')} className="max-w-md rounded-lg bg-black" />
          <p className="text-xs text-muted-foreground">
            {t('hardware.camera.testResult', { ms: formatNumber(test.data.ms, 0), kb: formatNumber(test.data.bytes / 1024, 0) })}
          </p>
        </div>
      )}
    </div>
  )
}

/** getUserMedia preview and one JPEG frame per capture interval to POST /admin/nodes/{id}/frame. */
function BrowserFeed({ node }: { node: HardwareNode }) {
  const { t } = useTranslation()
  const send = useSendBrowserFrame(node.id)
  const [on, setOn] = useState(false)
  const [devices, setDevices] = useState<MediaDeviceInfo[]>([])
  const [deviceId, setDeviceId] = useState('')
  const [last, setLast] = useState<Detection | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [sentAt, setSentAt] = useState<number | null>(null)
  const videoRef = useRef<HTMLVideoElement>(null)
  // 10 s in demo mode, 5 min otherwise: the server reports it as the camera's expected interval.
  const intervalMs = Math.max(1, node.expected_interval_s) * 1000

  const post = useCallback(
    (image: Blob) => {
      send.mutate(image, {
        onSuccess: (detection) => {
          setLast(detection)
          setError(null)
          setSentAt(Date.now())
        },
        onError: (e) => setError(errorText(e, t('common.error'), t('camera.visionDown'))),
      })
    },
    [send, t],
  )
  // The timer below always calls the latest post and skips a tick while a frame is still uploading.
  const postRef = useRef(post)
  const busyRef = useRef(false)
  useEffect(() => {
    postRef.current = post
    busyRef.current = send.isPending
  }, [post, send.isPending])

  useEffect(() => {
    if (!on) return
    if (!navigator.mediaDevices?.getUserMedia) {
      setError(t('camera.webcamDenied'))
      setOn(false)
      return
    }
    let cancelled = false
    let stream: MediaStream | null = null
    let first: number | undefined
    let timer: number | undefined
    const video = videoRef.current

    const grab = () => {
      if (!video || !video.videoWidth || busyRef.current) return
      const canvas = document.createElement('canvas')
      canvas.width = video.videoWidth
      canvas.height = video.videoHeight
      canvas.getContext('2d')?.drawImage(video, 0, 0)
      canvas.toBlob((blob) => blob && postRef.current(blob), 'image/jpeg', 0.85)
    }

    navigator.mediaDevices
      .getUserMedia({ video: { ...(deviceId ? { deviceId: { exact: deviceId } } : {}), width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false })
      .then(async (s) => {
        if (cancelled) return s.getTracks().forEach((track) => track.stop())
        stream = s
        if (video) video.srcObject = s
        try {
          // Labels are only readable after permission was granted.
          const all = await navigator.mediaDevices.enumerateDevices()
          if (!cancelled) setDevices(all.filter((d) => d.kind === 'videoinput'))
        } catch {
          // no picker
        }
        if (cancelled) return
        setError(null)
        first = window.setTimeout(grab, 1500)
        timer = window.setInterval(grab, intervalMs)
      })
      .catch(() => {
        if (cancelled) return
        setError(t('camera.webcamDenied'))
        setOn(false)
      })

    return () => {
      cancelled = true
      window.clearTimeout(first)
      window.clearInterval(timer)
      stream?.getTracks().forEach((track) => track.stop())
      if (video) video.srcObject = null
    }
  }, [on, deviceId, intervalMs, t])

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-2">
        <Button variant={on ? 'secondary' : 'outline'} size="sm" onClick={() => setOn((v) => !v)}>
          {on ? <VideoOff /> : <Video />}
          {on ? t('hardware.camera.stop') : t('hardware.camera.useThis')}
        </Button>
        {on && devices.length > 1 && (
          <select
            aria-label={t('hardware.camera.picker')}
            className="max-w-full rounded-md border bg-background px-2 py-1 text-sm"
            value={deviceId || devices[0]?.deviceId}
            onChange={(e) => setDeviceId(e.target.value)}
          >
            {devices.map((d, i) => (
              <option key={d.deviceId || i} value={d.deviceId}>
                {d.label || t('hardware.camera.deviceN', { n: i + 1 })}
              </option>
            ))}
          </select>
        )}
      </div>
      {/* Always mounted so the effect can attach the stream; hidden while off. */}
      <video ref={videoRef} autoPlay muted playsInline className={on ? 'aspect-video w-full max-w-md rounded-md bg-black object-cover' : 'hidden'} />
      {on && (
        <p className="text-xs text-muted-foreground">
          {t('hardware.camera.sendingEvery', { s: Math.round(intervalMs / 1000) })}
          {send.isPending && ` · ${t('camera.sending')}`}
          {sentAt && !send.isPending && ` · ${t('hardware.camera.sentAt', { ago: formatAgo(new Date(sentAt).toISOString()) })}`}
        </p>
      )}
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      {last ? (
        <div className="flex flex-col gap-2">
          <div className="flex items-center gap-2 text-sm">
            <span className="font-medium">{t('camera.lastResult')}</span>
            <LitterCount count={last.count} />
            <span className="text-xs text-muted-foreground">{formatDateTime(last.captured_at)}</span>
          </div>
          <FrameImage detection={last} className="max-w-md" />
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">{t('hardware.camera.noFrameYet')}</p>
      )}
    </div>
  )
}
