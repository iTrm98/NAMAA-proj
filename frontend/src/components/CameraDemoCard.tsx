import { Camera, ImageUp, Video, VideoOff } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { FrameImage, LitterCount } from '@/components/camera'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { useUploadFrame } from '@/hooks/useCamera'
import { parkName, useParks } from '@/hooks/useParks'
import { ApiError } from '@/lib/api'
import { formatAgo } from '@/lib/format'
import type { Detection } from '@/types'

const WEBCAM_EVERY_MS = 10_000

/**
 * Stage fallback for the camera board (or the Wi-Fi): frames from this laptop's
 * webcam (every 10 s while switched on; Docker cannot reach a USB webcam, the
 * browser can) or one uploaded photo. Both go through the normal camera pipeline.
 */
export function CameraDemoCard() {
  const { t, i18n } = useTranslation()
  const parks = useParks()
  const upload = useUploadFrame()
  // Test cameras only (Sim Cam): a real camera gets frames from the capture job, never from here.
  const cameras = (parks.data ?? []).filter((p) => p.camera?.source === 'simulated')
  const [nodeId, setNodeId] = useState<number | null>(null)
  const camera = nodeId ?? cameras[0]?.camera?.node_id ?? null
  const [webcamOn, setWebcamOn] = useState(false)
  const [last, setLast] = useState<Detection | null>(null)
  const videoRef = useRef<HTMLVideoElement>(null)
  const streamRef = useRef<MediaStream | null>(null)

  const send = useCallback(
    (image: Blob, via: 'file' | 'webcam') => {
      if (camera == null) return
      upload.mutate(
        { nodeId: camera, image, via },
        {
          onSuccess: (detection) => setLast(detection),
          onError: (error) => toast.error(error instanceof ApiError && error.status === 503 ? t('camera.visionDown') : error instanceof ApiError && error.body?.message ? error.body.message : t('common.error')),
        },
      )
    },
    [camera, upload, t],
  )

  // The interval below always calls the latest send (camera choice, mutation).
  const sendRef = useRef(send)
  useEffect(() => {
    sendRef.current = send
  }, [send])

  // Webcam on: preview + one JPEG frame every 10 s.
  useEffect(() => {
    if (!webcamOn) return
    if (!navigator.mediaDevices?.getUserMedia) {
      toast.error(t('camera.webcamDenied'))
      setWebcamOn(false)
      return
    }
    let timer: number | undefined
    let first: number | undefined
    let cancelled = false

    const grab = () => {
      const video = videoRef.current
      if (!video || !video.videoWidth) return
      const canvas = document.createElement('canvas')
      canvas.width = video.videoWidth
      canvas.height = video.videoHeight
      canvas.getContext('2d')?.drawImage(video, 0, 0)
      canvas.toBlob((blob) => blob && sendRef.current(blob, 'webcam'), 'image/jpeg', 0.85)
    }

    navigator.mediaDevices
      .getUserMedia({ video: { width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false })
      .then((stream) => {
        if (cancelled) return stream.getTracks().forEach((track) => track.stop())
        streamRef.current = stream
        if (videoRef.current) videoRef.current.srcObject = stream
        first = window.setTimeout(grab, 1500) // first frame once the camera has adjusted
        timer = window.setInterval(grab, WEBCAM_EVERY_MS)
      })
      .catch(() => {
        toast.error(t('camera.webcamDenied'))
        setWebcamOn(false)
      })

    return () => {
      cancelled = true
      window.clearTimeout(first)
      window.clearInterval(timer)
      streamRef.current?.getTracks().forEach((track) => track.stop())
      streamRef.current = null
    }
  }, [webcamOn, t])

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Camera className="size-4" />
          {t('camera.demoTitle')}
        </CardTitle>
        <CardDescription>{t('camera.demoIntro')}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {cameras.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('camera.noCamera')}</p>
        ) : (
          <>
            <label className="flex flex-wrap items-center gap-2 text-sm">
              {t('camera.sendTo')}
              <select
                className="rounded-md border bg-background px-2 py-1"
                value={camera ?? ''}
                onChange={(e) => setNodeId(Number(e.target.value))}
              >
                {cameras.map((p) => (
                  <option key={p.id} value={p.camera!.node_id}>
                    {t('camera.nodeOption', { camera: p.camera!.name, park: parkName(p, i18n.language) })}
                  </option>
                ))}
              </select>
              <span className="text-xs text-muted-foreground">{t('camera.simOnly')}</span>
            </label>

            <div className="grid gap-4 md:grid-cols-2">
              <div className="flex flex-col gap-2 rounded-lg border p-3">
                <label className="flex items-center justify-between gap-2 text-sm font-medium">
                  <span className="flex items-center gap-2">
                    {webcamOn ? <Video className="size-4" /> : <VideoOff className="size-4" />}
                    {t('camera.webcam')}
                  </span>
                  <Switch checked={webcamOn} onCheckedChange={setWebcamOn} aria-label={t('camera.webcam')} />
                </label>
                <p className="text-xs text-muted-foreground">{t('camera.webcamHint')}</p>
                {webcamOn && <video ref={videoRef} autoPlay muted playsInline className="aspect-video w-full rounded-md bg-black object-cover" />}
              </div>

              <div className="flex flex-col gap-2 rounded-lg border p-3">
                <span className="flex items-center gap-2 text-sm font-medium">
                  <ImageUp className="size-4" />
                  {t('camera.upload')}
                </span>
                <p className="text-xs text-muted-foreground">{t('camera.uploadHint')}</p>
                <Button asChild variant="outline" disabled={upload.isPending}>
                  <label className="cursor-pointer">
                    {upload.isPending ? t('camera.sending') : t('camera.choosePhoto')}
                    <input
                      type="file"
                      accept="image/jpeg,image/png"
                      className="sr-only"
                      onChange={(e) => {
                        const file = e.target.files?.[0]
                        if (file) send(file, 'file')
                        e.target.value = ''
                      }}
                    />
                  </label>
                </Button>
              </div>
            </div>

            {last && (
              <div className="flex flex-col gap-2">
                <div className="flex items-center gap-2 text-sm">
                  <span className="font-medium">{t('camera.lastResult')}</span>
                  <LitterCount count={last.count} />
                  <span className="text-xs text-muted-foreground">{formatAgo(last.captured_at)}</span>
                </div>
                <FrameImage detection={last} className="max-w-md" />
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  )
}
