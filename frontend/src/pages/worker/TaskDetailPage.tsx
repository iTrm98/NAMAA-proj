import 'maplibre-gl/dist/maplibre-gl.css'
import { ArrowRight, Camera, CheckCircle2, Clock, Droplets, MapPin, Play, Power, XCircle } from 'lucide-react'
import { type FormEvent, useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import Map, { Layer, Source } from 'react-map-gl/maplibre'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { alertDetail, CategoryTag, categoryFrame, DueBadge, PriorityBadge, STATUS_TEXT, TaskPhoto, TaskStatusBadge } from '@/components/ops'
import { SourceBadge, STATUS_COLORS, StatusPill } from '@/components/StatusPill'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Textarea } from '@/components/ui/textarea'
import { useMyTask, useWorkerAction } from '@/hooks/useOps'
import { parkName } from '@/hooks/useParks'
import { ApiError } from '@/lib/api'
import { formatAgo, formatNumber } from '@/lib/format'
import { mapStyle } from '@/lib/mapStyle'
import { cn } from '@/lib/utils'
import type { Park, WorkerTaskDetail } from '@/types'

/** /worker/tasks/:id: where, what is wrong, what to do; Start → (Pump) → Done; then the verification result, live. */
export default function TaskDetailPage() {
  const { t } = useTranslation()
  const id = Number(useParams().id)
  const task = useMyTask(id)

  if (task.isPending)
    return (
      <div className="flex flex-col gap-3">
        <Skeleton className="h-8 w-2/3" />
        <Skeleton className="h-40 w-full rounded-xl" />
        <Skeleton className="h-24 w-full rounded-xl" />
      </div>
    )

  if (task.isError)
    return (
      <div className="flex flex-col items-start gap-3">
        <p className="text-sm text-muted-foreground">
          {task.error instanceof ApiError && task.error.status === 404 ? t('worker.notFound') : t('common.error')}
        </p>
        <BackLink />
      </div>
    )

  return <TaskDetail task={task.data} />
}

function BackLink() {
  const { t } = useTranslation()
  return (
    <Button asChild variant="ghost" className="min-h-11 self-start px-2">
      <Link to="/worker">
        <ArrowRight className="ltr:-scale-x-100" />
        {t('worker.back')}
      </Link>
    </Button>
  )
}

function TaskDetail({ task }: { task: WorkerTaskDetail }) {
  const { t, i18n } = useTranslation()
  const park = task.park_detail
  const urgent = task.priority === 'urgent' && (task.status === 'open' || task.status === 'in_progress')
  const action = task.alert?.recommended_action ?? 'action.manual'

  return (
    <div className="flex flex-col gap-4 pb-8">
      <BackLink />

      <div className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center gap-1.5">
          <PriorityBadge priority={task.priority} className={urgent ? 'px-2 text-sm' : undefined} />
          <TaskStatusBadge status={task.status} />
          <DueBadge task={task} />
        </div>
        <h1 className="text-xl font-semibold">{task.title}</h1>
        <div className="flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
          <MapPin className="size-4 shrink-0" />
          <span>
            {parkName(park, i18n.language)}
            {park.district ? ` · ${park.district}` : ''}
          </span>
          <StatusPill status={park.status} />
          <SourceBadge source={park.source} />
        </div>
      </div>

      <MiniMap park={park} />
      <MoistureCard park={park} />

      {task.alert && (
        <Card className={cn('py-4', categoryFrame(task.alert.category))}>
          <CardContent className="flex flex-col gap-1 px-4">
            <div className="flex items-center justify-between gap-2">
              <span className="text-xs font-medium text-muted-foreground">{t('worker.problem')}</span>
              <CategoryTag category={task.alert.category} />
            </div>
            <div className="font-semibold">{t(`alertType.${task.alert.type}`)}</div>
            {alertDetail(t, task.alert.type, task.alert.data) && <div className="text-sm text-muted-foreground">{alertDetail(t, task.alert.type, task.alert.data)}</div>}
          </CardContent>
        </Card>
      )}

      <Card className="py-4">
        <CardContent className="flex flex-col gap-3 px-4">
          <div>
            <div className="text-xs font-medium text-muted-foreground">{t('worker.whatToDo')}</div>
            <p className="text-sm leading-relaxed">{t(action)}</p>
          </div>
          {task.note && (
            <div>
              <div className="text-xs font-medium text-muted-foreground">{t('worker.adminNote')}</div>
              <p className="text-sm leading-relaxed whitespace-pre-line">{task.note}</p>
            </div>
          )}
        </CardContent>
      </Card>

      <Actions task={task} />
    </div>
  )
}

/** The park outline on a small, non-interactive map. */
function MiniMap({ park }: { park: Park }) {
  const data = useMemo(
    () => ({ type: 'Feature' as const, properties: {}, geometry: park.polygon }),
    [park.polygon],
  )
  const bounds = useMemo(() => {
    const points = park.polygon?.coordinates?.[0] ?? [[park.lng, park.lat]]
    const lngs = points.map((c) => c[0])
    const lats = points.map((c) => c[1])
    return [
      [Math.min(...lngs), Math.min(...lats)],
      [Math.max(...lngs), Math.max(...lats)],
    ] as [[number, number], [number, number]]
  }, [park.polygon, park.lng, park.lat])
  const color = STATUS_COLORS[park.status]

  return (
    <div className="h-44 overflow-hidden rounded-xl border" dir="ltr">
      <Map
        initialViewState={{ bounds, fitBoundsOptions: { padding: 30, maxZoom: 17 } }}
        mapStyle={mapStyle('satellite')}
        interactive={false}
        attributionControl={{ compact: true }}
        style={{ width: '100%', height: '100%' }}
      >
        {park.polygon?.type === 'Polygon' && (
          <Source id="park" type="geojson" data={data}>
            <Layer id="park-fill" type="fill" paint={{ 'fill-color': color, 'fill-opacity': 0.3 }} />
            <Layer id="park-line" type="line" paint={{ 'line-color': color, 'line-width': 3 }} />
          </Source>
        )}
      </Map>
    </div>
  )
}

function MoistureCard({ park }: { park: Park }) {
  const { t } = useTranslation()
  const value = park.latest?.moisture_pct ?? null
  const state = value == null ? null : value < park.moisture_min ? 'dry' : value > park.moisture_max ? 'over' : 'ok'

  return (
    <Card className="py-4">
      <CardContent className="flex items-center gap-4 px-4">
        <span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-sky-500/15 text-sky-500">
          <Droplets className="size-6" />
        </span>
        <div className="flex min-w-0 flex-col">
          <span className="text-xs text-muted-foreground">{t('worker.moistureNow')}</span>
          <span className={cn('text-3xl font-bold tabular-nums', state && STATUS_TEXT[state])}>{value == null ? '—' : `${formatNumber(value)} %`}</span>
          <span className="text-xs text-muted-foreground">
            {t('worker.range', { min: park.moisture_min, max: park.moisture_max })}
            {park.last_reading_at && ` · ${formatAgo(park.last_reading_at)}`}
          </span>
        </div>
      </CardContent>
    </Card>
  )
}

function Actions({ task }: { task: WorkerTaskDetail }) {
  const { t } = useTranslation()
  const action = useWorkerAction(task.id)
  const failed = (error: unknown) => toast.error(error instanceof ApiError && error.body?.message ? error.body.message : t('common.error'))

  if (task.status === 'cancelled') return <p className="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground">{t('worker.cancelled')}</p>
  if (task.status === 'done') return <Verification task={task} />

  const started = task.status === 'in_progress'
  const pumpOn = task.pump?.target ?? task.park_detail.pump_target ?? false

  return (
    <div className="flex flex-col gap-3">
      {!started && (
        <Button size="lg" className="h-14 text-base" disabled={action.isPending} onClick={() => action.mutate({ kind: 'start' }, { onError: failed })}>
          <Play />
          {t('worker.start')}
        </Button>
      )}

      {task.park_detail.has_pump && (
        <div className="flex flex-col gap-1.5">
          <Button
            size="lg"
            variant={pumpOn ? 'outline' : 'secondary'}
            className="h-14 text-base"
            disabled={!started || action.isPending}
            onClick={() =>
              action.mutate(
                { kind: 'pump', on: !pumpOn },
                { onSuccess: () => toast.success(t('worker.pumpSent')), onError: failed },
              )
            }
          >
            <Power />
            {pumpOn ? t('worker.pumpOff') : t('worker.pumpOn')}
          </Button>
          <p className="text-center text-xs text-muted-foreground">{started ? (pumpOn ? t('worker.pumpRunning') : t('worker.pumpStopped')) : t('worker.startFirst')}</p>
        </div>
      )}

      {started && <DoneForm task={task} />}
    </div>
  )
}

function DoneForm({ task }: { task: WorkerTaskDetail }) {
  const { t } = useTranslation()
  const action = useWorkerAction(task.id)
  const [note, setNote] = useState('')
  const [photo, setPhoto] = useState<File | null>(null)
  const [preview, setPreview] = useState<string | null>(null)

  useEffect(() => {
    if (!photo) return setPreview(null)
    const url = URL.createObjectURL(photo)
    setPreview(url)
    return () => URL.revokeObjectURL(url)
  }, [photo])

  function submit(event: FormEvent) {
    event.preventDefault()
    action.mutate(
      { kind: 'done', note, photo },
      { onError: (error) => toast.error(error instanceof ApiError && error.body?.message ? error.body.message : t('common.error')) },
    )
  }

  return (
    <Card className="py-4">
      <CardContent className="px-4">
        <form onSubmit={submit} className="flex flex-col gap-4">
          <h2 className="font-semibold">{t('worker.doneTitle')}</h2>
          <div className="flex flex-col gap-2">
            <Label htmlFor="done-note">{t('worker.note')}</Label>
            <Textarea id="done-note" rows={3} maxLength={1000} value={note} placeholder={t('worker.notePlaceholder')} onChange={(e) => setNote(e.target.value)} />
          </div>
          <div className="flex flex-col gap-2">
            <span className="text-sm font-medium">{t('worker.photo')}</span>
            <label className="flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed px-4 text-sm hover:bg-muted/50">
              <Camera className="size-5" />
              {photo ? t('worker.changePhoto') : t('worker.takePhoto')}
              <input
                type="file"
                accept="image/*"
                capture="environment"
                className="sr-only"
                onChange={(e) => setPhoto(e.target.files?.[0] ?? null)}
              />
            </label>
            {preview && <img src={preview} alt="" className="max-h-48 w-full rounded-lg object-cover" />}
          </div>
          <Button type="submit" size="lg" className="h-14 text-base" disabled={action.isPending}>
            <CheckCircle2 />
            {action.isPending ? t('worker.sending') : t('worker.submitDone')}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}

/** After Done: waiting, then ✓ or ✗; the page updates by itself when task.updated arrives. */
function Verification({ task }: { task: WorkerTaskDetail }) {
  const { t } = useTranslation()

  return (
    <div className="flex flex-col gap-3">
      {task.verified === null ? (
        <div className="flex items-center gap-3 rounded-xl border bg-muted/40 p-4" role="status">
          <Clock className="size-6 shrink-0 animate-pulse text-muted-foreground" />
          <p className="text-sm">{t('worker.waiting')}</p>
        </div>
      ) : task.verified ? (
        <div className="flex items-center gap-3 rounded-xl border border-status-ok/40 bg-status-ok/10 p-4" role="status">
          <CheckCircle2 className="size-8 shrink-0 text-status-ok" />
          <div className="text-sm">
            <p className="font-semibold">{t('worker.verified')}</p>
            {task.time_to_resolve_minutes != null && <p className="text-muted-foreground">{t('worker.verifiedIn', { n: task.time_to_resolve_minutes })}</p>}
          </div>
        </div>
      ) : (
        <div className="flex items-center gap-3 rounded-xl border border-destructive/40 bg-destructive/10 p-4" role="status">
          <XCircle className="size-8 shrink-0 text-destructive" />
          <div className="text-sm">
            <p className="font-semibold">{t('worker.notVerified')}</p>
            {task.verify_reason && <p className="text-muted-foreground">{t(`verify.${task.verify_reason}`)}</p>}
          </div>
        </div>
      )}
      {task.done_note && <p className="text-sm text-muted-foreground">“{task.done_note}”</p>}
      {task.has_photo && <TaskPhoto path={`/worker/tasks/${task.id}/photo`} alt={t('tasks.photoAlt')} className="max-h-48 w-full" />}
    </div>
  )
}
