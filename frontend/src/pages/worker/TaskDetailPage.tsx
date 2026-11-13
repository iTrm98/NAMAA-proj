import 'maplibre-gl/dist/maplibre-gl.css'
import { ArrowRight, Camera, CheckCircle2, Play, Power, XCircle } from 'lucide-react'
import { motion } from 'motion/react'
import { type FormEvent, useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import Map, { Layer, Source } from 'react-map-gl/maplibre'
import { Link, useParams } from 'react-router'
import type { Geometry } from 'geojson'
import { toast } from 'sonner'
import { FrameImage } from '@/components/camera'
import { Chip, type ChipTone, EmptyState, GlassCard, Skeleton } from '@/components/ds'
import { alertDetail, TaskPhoto } from '@/components/ops'
import { STATUS_COLORS } from '@/components/StatusPill'
import { isAreaGeometry, outerPoints } from '@/lib/geo'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { useDocumentTitle } from '@/hooks/useDocumentTitle'
import { useMyTask, useWorkerAction } from '@/hooks/useOps'
import { parkName } from '@/hooks/useParks'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { formatAgo, formatNumber } from '@/lib/format'
import { SatelliteNote } from '@/components/map/SatelliteNote'
import { mapStyle } from '@/lib/mapStyle'
import { personName, taskTitle } from '@/lib/names'
import { wateringOf } from '@/lib/watering'
import type { AlertType, Detection, Park, TaskPriority, WorkerTaskDetail } from '@/types'

const PRIORITY_TONE: Record<TaskPriority, ChipTone> = { urgent: 'urgent', high: 'high', normal: 'normal', low: 'muted' }
const BIG = 'flex h-14 w-full items-center justify-center gap-2 rounded-full text-base font-medium disabled:opacity-50'

/** /worker/tasks/:id: where, what is wrong, live moisture toward the target; Start, Pump, Done; then "Waiting for the sensors" and Verified, live. */
export default function TaskDetailPage() {
  const { t, i18n } = useTranslation()
  const id = Number(useParams().id)
  const task = useMyTask(id)
  useDocumentTitle(task.data ? taskTitle(t, i18n.language, task.data) : null)

  if (task.isPending)
    return (
      <div className="flex flex-col gap-3" aria-busy="true">
        <Skeleton h={28} w="60%" />
        <Skeleton h={120} radius={22} />
        <Skeleton h={130} radius={22} />
      </div>
    )

  if (task.isError) {
    const notFound = task.error instanceof ApiError && task.error.status === 404
    return (
      <div className="flex flex-col items-start gap-3">
        <EmptyState
          className="w-full"
          title={notFound ? t('worker.notFound') : t('common.error')}
          action={
            notFound ? undefined : (
              <button type="button" className="min-h-11 rounded-full border border-[var(--glass-border-strong)] px-5 text-sm" onClick={() => task.refetch()}>
                {t('common.retry')}
              </button>
            )
          }
        />
        <BackLink />
      </div>
    )
  }

  return <TaskDetail task={task.data} />
}

type Kind = 'plant' | 'water' | 'cleanliness' | 'devices' | 'general'
const WATERING_TYPES: AlertType[] = ['dry', 'heat_stress', 'over']

/** What the task is about decides which cards the page shows (a litter task never shows moisture or a pump). */
function taskKind(task: WorkerTaskDetail): Kind {
  const category = task.category ?? task.alert?.category ?? null
  if (category) return category
  return task.watering_target_pct != null ? 'plant' : 'general'
}

/** Moisture and the pump belong only to tasks that watering can fix. */
function showsMoisture(task: WorkerTaskDetail, kind: Kind): boolean {
  if (kind !== 'plant') return false
  return task.alert ? WATERING_TYPES.includes(task.alert.type) : task.watering_target_pct != null
}

/** The worker's own frame route (alert.frame_url): thumb first, then display size. Detection URLs are admin-prefixed and unusable here. */
function workerFrame(frameUrl: string, d: Detection) {
  return { ...d, image_url: frameUrl, thumb_url: `${frameUrl}?size=thumb`, display_url: `${frameUrl}?size=display` }
}

function BackLink() {
  const { t } = useTranslation()
  return (
    <Link to="/worker" className="flex min-h-11 items-center gap-1.5 self-start text-[13px] text-[var(--accent-lime)] no-underline">
      <ArrowRight className="size-4 ltr:-scale-x-100" />
      {t('worker.back')}
    </Link>
  )
}

function TaskDetail({ task }: { task: WorkerTaskDetail }) {
  const { t, i18n } = useTranslation()
  const park = task.park_detail
  const action = task.alert?.recommended_action ?? 'action.manual'
  const detail = task.alert ? alertDetail(t, task.alert.type, task.alert.data) : null
  const kind = taskKind(task)
  const showMoisture = showsMoisture(task, kind)
  const canPump = showMoisture && park.has_pump

  return (
    <div className="flex flex-col gap-3.5 pb-8">
      <BackLink />

      <div className="flex flex-col gap-1.5">
        <div className="flex flex-wrap gap-1.5">
          <Chip tone={PRIORITY_TONE[task.priority]}>{t(`priority.${task.priority}`)}</Chip>
          <Chip tone="muted">{t(`taskStatus.${task.status}`)}</Chip>
          {task.category && <Chip tone="muted">{t(`category.${task.category}`)}</Chip>}
          {park.site_type === 'demo_stand' && <Chip tone="muted">{t('taskCard.stand')}</Chip>}
          <Chip tone="muted">{t(`source.${park.source}`)}</Chip>
        </div>
        <h1 className="m-0 text-[26px] leading-tight font-normal">{taskTitle(t, i18n.language, task)}</h1>
        <span className="text-xs text-text-2">
          {parkName(park, i18n.language)}
          {park.district ? ` · ${park.district}` : ''}
          {task.alert ? ` · ${t(`alertType.${task.alert.type}`)}` : ''}
        </span>
      </div>

      <MiniMap park={park} />
      {showMoisture && <MoistureCard task={task} />}
      {kind === 'water' && <FlowCard task={task} />}
      {kind === 'devices' && <DeviceCard task={task} />}

      {task.alert && (
        <GlassCard radius="worker" padding={16} className="flex flex-col gap-1">
          <span className="text-xs text-text-2">{t('worker.problem')}</span>
          <div className="font-medium">{t(`alertType.${task.alert.type}`)}</div>
          {detail && <div className="text-sm text-text-2">{detail}</div>}
          {kind === 'cleanliness' && task.alert.detection && (
            // Workers read the frame through their task (the admin image route is not theirs); the small version shows first.
            <FrameImage detection={workerFrame(task.alert.frame_url ?? `/worker/tasks/${task.id}/frame`, task.alert.detection)} className="mt-2" />
          )}
        </GlassCard>
      )}

      {kind === 'cleanliness' && <CollectCard task={task} />}
      {(kind === 'water' || kind === 'devices') && <CheckCard task={task} kind={kind} />}

      <GlassCard radius="worker" padding={16} className="flex flex-col gap-3">
        <div>
          <div className="text-xs text-text-2">{t('worker.whatToDo')}</div>
          <p className="m-0 text-sm leading-relaxed">{t(action)}</p>
        </div>
        {task.note && (
          <div>
            <div className="text-xs text-text-2">{t('worker.adminNote')}</div>
            <p className="m-0 text-sm leading-relaxed whitespace-pre-line">{task.note}</p>
          </div>
        )}
      </GlassCard>

      <Actions task={task} canPump={canPump} />
    </div>
  )
}

/** The park outline on a small, non-interactive map, with "Open in Maps". */
function MiniMap({ park }: { park: Park }) {
  const { t } = useTranslation()
  const data = useMemo(() => ({ type: 'Feature' as const, properties: {}, geometry: park.polygon as Geometry }), [park.polygon])
  const bounds = useMemo(() => {
    const ring = outerPoints(park.polygon)
    const points = ring.length ? ring : [[park.lng, park.lat]]
    const lngs = points.map((c) => c[0])
    const lats = points.map((c) => c[1])
    return [
      [Math.min(...lngs), Math.min(...lats)],
      [Math.max(...lngs), Math.max(...lats)],
    ] as [[number, number], [number, number]]
  }, [park.polygon, park.lng, park.lat])
  const color = STATUS_COLORS[park.status]

  return (
    <div className="relative h-[120px] overflow-hidden rounded-[var(--r-worker)] border border-[var(--glass-border)]" dir="ltr">
      <Map
        initialViewState={{ bounds, fitBoundsOptions: { padding: 24, maxZoom: 17 } }}
        mapStyle={mapStyle('satellite')}
        interactive={false}
        attributionControl={{ compact: true }}
        style={{ width: '100%', height: '100%' }}
      >
        {isAreaGeometry(park.polygon) && (
          <Source id="park" type="geojson" data={data}>
            <Layer id="park-fill" type="fill" paint={{ 'fill-color': color, 'fill-opacity': 0.25 }} />
            <Layer id="park-line" type="line" paint={{ 'line-color': color, 'line-width': 3 }} />
          </Source>
        )}
      </Map>
      <SatelliteNote basemap="satellite" className="bottom-1.5" />
      <a
        href={`https://www.google.com/maps?q=${park.lat},${park.lng}`}
        target="_blank"
        rel="noreferrer"
        className="absolute start-2.5 top-2.5 flex min-h-11 items-center rounded-full bg-[rgba(16,18,14,.8)] px-3 text-xs text-[var(--text)] no-underline"
      >
        {t('worker.openInMaps')}
      </a>
    </div>
  )
}

/** Live moisture: the number, a bar that fills smoothly with the readings, the minimum marker and "Water until X %". */
function MoistureCard({ task }: { task: WorkerTaskDetail }) {
  const { t } = useTranslation()
  const park = task.park_detail
  const value = park.latest?.moisture_pct ?? null
  const water = wateringOf(task)
  const target = water?.target ?? null
  const width = value == null ? 0 : Math.min(100, Math.max(0, value))
  const low = value != null && value < park.moisture_min

  return (
    <GlassCard radius="worker" padding={16} className="flex flex-col gap-2.5">
      <div className="flex items-baseline justify-between">
        <span className="text-xs text-text-2">{t('worker.moistureLive')}</span>
        <span className="text-[30px] tabular-nums" aria-live="polite">
          {value == null ? '—' : `${formatNumber(value)}%`}
        </span>
      </div>
      <div
        className="relative h-2.5 rounded-full bg-white/10"
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={value == null ? undefined : Math.round(value)}
        dir="ltr"
      >
        <div className="h-full overflow-hidden rounded-full">
          <motion.div
            className="h-full w-full origin-left rounded-full"
            initial={false}
            animate={{ scaleX: width / 100 }}
            transition={{ duration: 0.3, ease: 'easeOut' }}
            style={{ background: low ? 'var(--water)' : 'var(--accent-lime)' }}
          />
        </div>
        <span className="absolute -top-1 h-[18px] w-0.5 rounded bg-[var(--awaiting)]" style={{ left: `${park.moisture_min}%` }} aria-hidden />
        {target != null && <span className="absolute -top-1 h-[18px] w-0.5 rounded bg-[var(--accent-lime)]" style={{ left: `${target}%` }} aria-hidden />}
      </div>
      <div className="flex justify-between text-xs">
        <span className="text-text-2">{t('worker.minimum', { min: formatNumber(park.moisture_min) })}</span>
        {target != null && <span className="text-[#D9F79A]">{t('worker.waterUntil', { target: formatNumber(target) })}</span>}
      </div>
      {water?.reached && <p className="m-0 text-sm text-[var(--healthy)]">{t('worker.targetReached')}</p>}
    </GlassCard>
  )
}

/** Water tasks: the live flow and the pump state (read-only; the fix is on site, not a button). */
function FlowCard({ task }: { task: WorkerTaskDetail }) {
  const { t } = useTranslation()
  const park = task.park_detail
  const flow = park.latest?.flow ?? null
  const pumpOn = task.pump?.target ?? park.pump_target ?? park.latest?.pump_on ?? null
  const expected = typeof task.alert?.data?.expected_flow === 'number' ? task.alert.data.expected_flow : null

  return (
    <GlassCard radius="worker" padding={16} className="flex flex-col gap-2">
      <div className="flex items-baseline justify-between">
        <span className="text-xs text-text-2">{t('worker.flowLive')}</span>
        <span className="text-[30px] tabular-nums" aria-live="polite">
          {flow == null ? '—' : formatNumber(flow, 2)}
          <span className="ms-1 text-sm text-text-2">{t('worker.flowUnit')}</span>
        </span>
      </div>
      <div className="flex flex-wrap items-center gap-1.5">
        {pumpOn != null && <Chip tone={pumpOn ? 'normal' : 'muted'}>{pumpOn ? t('worker.pumpIsOn') : t('worker.pumpIsOff')}</Chip>}
        {expected != null && <span className="text-xs text-text-2">{t('worker.flowExpected', { value: formatNumber(expected, 1) })}</span>}
      </div>
    </GlassCard>
  )
}

/** Device tasks: the node and when it was last seen. */
function DeviceCard({ task }: { task: WorkerTaskDetail }) {
  const { t, i18n } = useTranslation()
  const park = task.park_detail
  const node = task.alert?.node ?? null
  const seen = node?.last_seen_at ?? (typeof task.alert?.data?.last_seen_at === 'string' ? task.alert.data.last_seen_at : park.last_reading_at)

  return (
    <GlassCard radius="worker" padding={16} className="flex flex-col gap-1.5">
      <span className="text-xs text-text-2">{t('worker.deviceTitle')}</span>
      <div className="font-medium">{node ? `${node.name} · ${parkName(park, i18n.language)}` : `${t('worker.deviceNode')} · ${parkName(park, i18n.language)}`}</div>
      <span className="text-sm text-text-2">{seen ? t('worker.lastSeen', { time: formatAgo(seen) }) : t('alertDetail.offlineNever')}</span>
    </GlassCard>
  )
}

const CHECKS: Partial<Record<AlertType, string[]>> = {
  leak: ['leakNear', 'leakLine'],
  no_flow: ['tank', 'pipe', 'pumpPower'],
  blockage: ['filter', 'pipe', 'drippers'],
  offline: ['power', 'wifi', 'restart'],
  sensor_fault: ['cable', 'probe', 'restartSensor'],
  low_battery: ['panel', 'battery'],
}

/** A short list of what to look at, by problem (water and devices). */
function CheckCard({ task, kind }: { task: WorkerTaskDetail; kind: 'water' | 'devices' }) {
  const { t, i18n } = useTranslation()
  const park = task.park_detail
  const place = park.district ? `${parkName(park, i18n.language)} · ${park.district}` : parkName(park, i18n.language)
  const keys = (task.alert && CHECKS[task.alert.type]) ?? (kind === 'water' ? ['tank', 'pipe'] : ['power', 'cable', 'wifi'])

  return (
    <GlassCard radius="worker" padding={16} className="flex flex-col gap-2">
      <span className="text-xs text-text-2">{t('worker.whatToCheck')}</span>
      <ul className="m-0 flex list-disc flex-col gap-1 ps-5 text-sm leading-relaxed">
        {keys.map((k) => (
          <li key={k}>{t(`worker.check_${k}`, { place })}</li>
        ))}
      </ul>
    </GlassCard>
  )
}

/** Litter tasks: what to pick up, counted from the frame's boxes. */
function CollectCard({ task }: { task: WorkerTaskDetail }) {
  const { t } = useTranslation()
  const items = task.alert?.detection?.items ?? []
  const counts: Record<string, number> = {}
  for (const i of items) counts[i.label] = (counts[i.label] ?? 0) + 1
  const grouped = Object.entries(counts)
  const fromData = Array.isArray(task.alert?.data?.labels) ? (task.alert?.data?.labels as string[]) : []
  const chips = grouped.length > 0 ? grouped.map(([label, n]) => (n > 1 ? `${t('litterLabel.' + label, { defaultValue: label })} × ${formatNumber(n, 0)}` : t('litterLabel.' + label, { defaultValue: label }))) : fromData

  return (
    <GlassCard radius="worker" padding={16} className="flex flex-col gap-2">
      <span className="text-xs text-text-2">{t('worker.whatToCollect')}</span>
      {chips.length > 0 ? (
        <div className="flex flex-wrap gap-1.5">
          {chips.map((label) => (
            <Chip key={label} tone="muted">
              {label}
            </Chip>
          ))}
        </div>
      ) : (
        <span className="text-sm text-text-2">{t('worker.collectAll')}</span>
      )}
    </GlassCard>
  )
}

function Actions({ task, canPump }: { task: WorkerTaskDetail; canPump: boolean }) {
  const { t } = useTranslation()
  const action = useWorkerAction(task.id)
  const failed = (error: unknown) => toast.error(error instanceof ApiError && error.body?.message ? error.body.message : t('common.error'))

  if (task.status === 'cancelled') return <EmptyState title={t('worker.cancelled')} />
  if (task.status === 'done') return <Verification task={task} />

  const started = task.status === 'in_progress'
  const pumpOn = task.pump?.target ?? task.park_detail.pump_target ?? false

  return (
    <div className="flex flex-col gap-3">
      {!started && (
        <button
          type="button"
          className={`${BIG} bg-[var(--accent-lime)] text-[var(--on-accent)]`}
          disabled={action.isPending}
          onClick={() => action.mutate({ kind: 'start' }, { onError: failed })}
        >
          <Play className="size-5" />
          {t('worker.start')}
        </button>
      )}

      {canPump && (
        <div className="flex flex-col gap-1.5">
          <button
            type="button"
            className={`${BIG} ${pumpOn ? 'bg-[var(--water)] text-[var(--on-accent)]' : 'border border-white/[.18] bg-white/10 text-[var(--text)]'}`}
            disabled={!started || action.isPending}
            onClick={() => action.mutate({ kind: 'pump', on: !pumpOn }, { onSuccess: () => toast.success(t('worker.pumpSent')), onError: failed })}
          >
            <Power className="size-5" />
            {pumpOn ? t('worker.pumpOff') : t('worker.pumpOn')}
          </button>
          <p className="m-0 text-center text-xs text-text-2">{started ? (pumpOn ? t('worker.pumpRunning') : t('worker.pumpStopped')) : t('worker.startFirst')}</p>
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
  const [confirmBelow, setConfirmBelow] = useState(false)
  const target = task.watering_target_pct
  const current = task.park_detail.latest?.moisture_pct ?? null
  const belowTarget = target != null && current != null && current < target

  useEffect(() => {
    if (!photo) return setPreview(null)
    const url = URL.createObjectURL(photo)
    setPreview(url)
    return () => URL.revokeObjectURL(url)
  }, [photo])

  function submit(event: FormEvent) {
    event.preventDefault()
    // Below the watering target: ask first. Done stays possible (the worker may know better).
    if (belowTarget) return setConfirmBelow(true)
    send()
  }

  function send() {
    setConfirmBelow(false)
    action.mutate(
      { kind: 'done', note, photo },
      { onError: (error) => toast.error(error instanceof ApiError && error.body?.message ? error.body.message : t('common.error')) },
    )
  }

  return (
    <GlassCard radius="worker" padding={16}>
      <form onSubmit={submit} className="flex flex-col gap-4">
        <h2 className="m-0 text-base font-medium">{t('worker.doneTitle')}</h2>
        <div className="flex flex-col gap-2">
          <Label htmlFor="done-note">{t('worker.note')}</Label>
          <Textarea id="done-note" rows={3} maxLength={1000} value={note} placeholder={t('worker.notePlaceholder')} onChange={(e) => setNote(e.target.value)} />
        </div>
        <div className="flex flex-col gap-2">
          <span className="text-sm font-medium">{t('worker.photo')}</span>
          <label className="flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-full border border-dashed border-white/25 px-4 text-sm hover:bg-white/5">
            <Camera className="size-5" />
            {photo ? t('worker.changePhoto') : t('worker.takePhoto')}
            <input type="file" accept="image/*" capture="environment" className="sr-only" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
          </label>
          {preview && <img src={preview} alt="" className="max-h-48 w-full rounded-2xl object-cover" />}
        </div>
        <button type="submit" className={`${BIG} bg-[var(--accent-lime)] text-[var(--on-accent)]`} disabled={action.isPending}>
          <CheckCircle2 className="size-5" />
          {action.isPending ? t('worker.sending') : t('worker.done')}
        </button>
      </form>
      <AlertDialog open={confirmBelow} onOpenChange={setConfirmBelow}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t('worker.belowTargetTitle')}</AlertDialogTitle>
            <AlertDialogDescription>{t('worker.belowTargetBody', { target: formatNumber(target ?? 0) })}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
            <AlertDialogAction onClick={send}>{t('worker.doneAnyway')}</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </GlassCard>
  )
}

/** After Done: "Waiting for the sensors…", then Verified (pops in) or not verified; the page updates by itself when task.updated arrives. */
function Verification({ task }: { task: WorkerTaskDetail }) {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const first = user ? personName(user, i18n.language).trim().split(/\s+/)[0] : ''

  return (
    <div className="flex flex-col gap-3">
      {task.verified === null ? (
        <GlassCard radius="worker" padding={16} className="flex flex-col items-center gap-1.5 text-center" role="status">
          <span className="text-[17px] font-medium">{t('worker.waitingTitle')}</span>
          <span className="text-xs text-text-2">{t('worker.waitingHint')}</span>
        </GlassCard>
      ) : task.verified ? (
        <motion.div
          initial={{ opacity: 0, scale: 0.9 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ type: 'spring', stiffness: 380, damping: 22 }}
          className="glass flex flex-col items-center gap-2 text-center"
          style={{ padding: 16, borderRadius: 'var(--r-worker)', borderColor: 'rgba(139,227,107,.40)' }}
          role="status"
        >
          <motion.span
            initial={{ opacity: 0, scale: 0.6 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ type: 'spring', stiffness: 400, damping: 20 }}
            className="text-[var(--healthy)]"
          >
            <CheckCircle2 className="size-9" aria-hidden />
          </motion.span>
          <Chip tone="ok" className="!px-3 !py-1.5 !text-[13px]">
            {t('worker.verifiedBySensors')}
          </Chip>
          <span className="text-[17px] font-medium">{t('worker.goodWork', { name: first })}</span>
          {task.time_to_resolve_minutes != null && <span className="text-xs text-text-2">{t('worker.verifiedIn', { n: task.time_to_resolve_minutes })}</span>}
        </motion.div>
      ) : (
        <GlassCard radius="worker" padding={16} className="flex items-center gap-3" style={{ borderColor: 'rgba(255,107,94,.40)' }} role="status">
          <XCircle className="size-8 shrink-0 text-[var(--urgent)]" />
          <div className="text-sm">
            <p className="m-0 font-medium">{t('worker.notVerified')}</p>
            {task.verify_reason && <p className="m-0 text-text-2">{t(`verify.${task.verify_reason}`)}</p>}
          </div>
        </GlassCard>
      )}
      {task.done_below_target && task.watering_target_pct != null && task.done_moisture_pct != null && (
        <p className="m-0 text-sm text-[var(--water)]">
          {t('worker.doneBelowTarget', { moisture: formatNumber(task.done_moisture_pct), target: formatNumber(task.watering_target_pct) })}
        </p>
      )}
      {task.done_note && <p className="m-0 text-sm text-text-2"><bdi>{task.done_note}</bdi></p>}
      {task.has_photo && <TaskPhoto path={`/worker/tasks/${task.id}/photo`} alt={t('tasks.photoAlt')} className="max-h-48 w-full" />}
    </div>
  )
}
